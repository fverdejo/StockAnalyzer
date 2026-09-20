<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;

/**
 * Analisis de la medicion completa de trailing a horizonte comun (fase 1,
 * predeclaracion final del `2026-09-20`, tercera entrada de `versions.md`)
 * sobre las filas de `PolicyReplayHorizonRows`. Solo lee filas ya
 * persistidas: no toca la base de datos ni la red.
 *
 * Metrica primaria `P` = media de `D_i` (trailing - fijo a `H = 45`
 * sesiones, cohorte comun, caja al 0%); descomposicion `P = P_drift + P_T`
 * con `P_drift = -r̄ x media(Δx)`; penalizacion de reentrada
 * `T = P_T - 0,40pp x cuota`. GO = informativo y `ci95_low(T) >= 0`;
 * NO-GO = informativo y `ci95_high(P) < -δ` Y `ci95_high(P_T) < 0`; INDET el
 * resto. Las puertas G1-G5 son DESCRIPTIVAS de prioridad. Todos los IC
 * salen del mismo bootstrap circular de bloques de `PolicyReplayStatistics`
 * (5.000 replicas, cada llamada re-siembra con `SEED`), recalculando
 * `P`, `P_T` y `T` (con `r̄`, `media(Δx)` y `cuota` incluidos) DENTRO de cada
 * replica, y la decision usa el IC SIN redondear.
 */
final class PolicyReplayHorizonAnalysis
{
    public const SEED = 20260920;
    public const DELTA_PP = 1.0;
    public const REENTRY_PENALTY_PP = 0.40;

    private const MIN_BEAR_ENTRIES = 300;
    private const MIN_BEAR_BLOCKS = 4;
    private const MIN_ENTRIES_PER_BEAR_BLOCK = 20;

    public function __construct(
        private readonly PolicyReplayStatistics $statistics = new PolicyReplayStatistics()
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows filas de `PolicyReplayHorizonRows::rows()` de TODOS los tickers
     * @return array<string, mixed>
     */
    public function analyze(array $rows, DateTimeImmutable $cutoff): array
    {
        $primaryHorizon = PolicyReplayHorizonRows::PRIMARY_HORIZON;
        $cohort = $this->cohort($rows, $primaryHorizon);

        $estimates = $this->estimates($cohort);
        $bootstrap = $this->bootstrapPrimary($cohort);
        $summary = $this->statistics->summarizePairedDiffs($this->pairedDiffs($cohort, $primaryHorizon, 'd'), self::SEED);

        $informative = $summary['result_informative'];
        $ci = $bootstrap['ci'];
        $go = $informative && $ci !== null && $ci['T']['ci95_low'] >= 0.0;
        $noGo = $informative && $ci !== null && $ci['P']['ci95_high'] < -self::DELTA_PP && $ci['P_T']['ci95_high'] < 0.0;

        $verdict = match (true) {
            !$informative => 'NO_INFORMATIVO (ningun GO ni NO-GO posible; solo cabe una nueva predeclaracion)',
            $go => 'GO (prioridad alta de fase 2)',
            $noGo => 'NO-GO (cierra solo raise-only k=2,5, cadencia 5, H=45)',
            default => 'INDET (fase 2 tras el backlog)',
        };

        return [
            'entries_total' => count($rows),
            'cohort_n' => count($cohort),
            'primary' => [
                'estimates' => $estimates,
                'intervals' => $ci,
                'block_width_days' => $bootstrap['block_width_days'],
                'blocks_in_range' => $bootstrap['blocks_in_range'],
                'effective_n' => $summary['effective_n'],
                'design_effect' => $summary['design_effect'],
                'result_informative' => $informative,
                // Control: el IC de D del bootstrap propio, redondeado a 2
                // decimales, debe coincidir con el de `summarizePairedDiffs()`.
                'ci_consistent_with_summarize' => $ci !== null
                    && round($ci['P']['ci95_low'], 2) === $summary['ci95_low']
                    && round($ci['P']['ci95_high'], 2) === $summary['ci95_high'],
            ],
            'verdict' => $verdict,
            'gates_descriptive_of_priority' => $this->gates($cohort, $estimates, $ci, $bootstrap['block_width_days']),
            'descriptive' => $this->descriptive($rows, $cohort, $cutoff, $bootstrap['block_width_days']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>> ordenadas por (entry_date, ticker, entry_index)
     */
    private function cohort(array $rows, int $horizon): array
    {
        $cohort = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['closes'][$horizon] !== null
        ));

        usort($cohort, static fn (array $a, array $b): int => [$a['entry_date'], $a['ticker'], $a['entry_index']] <=> [$b['entry_date'], $b['ticker'], $b['entry_index']]);

        return $cohort;
    }

    /**
     * @param list<array<string, mixed>> $cohort
     * @return list<array{entry_date: string, exposure_end_date: string, diff: float}>
     */
    private function pairedDiffs(array $cohort, int $horizon, string $field): array
    {
        return array_map(
            static fn (array $row): array => [
                'entry_date' => $row['entry_date'],
                'exposure_end_date' => $row['closes'][$horizon]['date'],
                'diff' => (float) $row[$field],
            ],
            $cohort
        );
    }

    /**
     * @param list<array<string, mixed>> $cohort
     * @return array<string, float|int|null>
     */
    private function estimates(array $cohort): array
    {
        $n = count($cohort);

        if ($n === 0) {
            return ['n' => 0, 'P' => null, 'r_bar' => null, 'mean_delta_x' => null, 'P_drift' => null, 'P_T' => null, 'share_reentry' => null, 'T' => null, 'P_penalized' => null];
        }

        $p = array_sum(array_column($cohort, 'd')) / $n;
        $rBar = array_sum(array_column($cohort, 'g')) / $n;
        $meanDx = array_sum(array_column($cohort, 'delta_x')) / $n;
        $share = count(array_filter($cohort, static fn (array $row): bool => $row['reentry'] === true)) / $n;
        $pDrift = -$rBar * $meanDx;
        $pT = $p - $pDrift;

        // Assert 12 (tautologico por construccion; control de implementacion).
        if (abs($pDrift + $pT - $p) >= 1e-9) {
            throw new \LogicException('P_drift + P_T != P.');
        }

        return [
            'n' => $n,
            'P' => $p,
            'r_bar' => $rBar,
            'mean_delta_x' => $meanDx,
            'P_drift' => $pDrift,
            'P_T' => $pT,
            'share_reentry' => $share,
            'T' => $pT - self::REENTRY_PENALTY_PP * $share,
            'P_penalized' => $p - self::REENTRY_PENALTY_PP * $share,
        ];
    }

    /**
     * @param list<array<string, mixed>> $cohort
     * @return array{ci: ?array<string, array{se: ?float, ci95_low: float, ci95_high: float}>, block_width_days: ?int, blocks_in_range: ?float}
     */
    private function bootstrapPrimary(array $cohort): array
    {
        $d = array_map(static fn (array $row): float => (float) $row['d'], $cohort);
        $g = array_map(static fn (array $row): float => (float) $row['g'], $cohort);
        $dx = array_map(static fn (array $row): float => (float) $row['delta_x'], $cohort);
        $re = array_map(static fn (array $row): float => $row['reentry'] === true ? 1.0 : 0.0, $cohort);
        $penalty = self::REENTRY_PENALTY_PP;

        $result = $this->statistics->blockBootstrapReplicates(
            $this->pairedDiffs($cohort, PolicyReplayHorizonRows::PRIMARY_HORIZON, 'd'),
            static function (array $indexes) use ($d, $g, $dx, $re, $penalty): array {
                $sumD = $sumG = $sumDx = $sumRe = 0.0;

                foreach ($indexes as $i) {
                    $sumD += $d[$i];
                    $sumG += $g[$i];
                    $sumDx += $dx[$i];
                    $sumRe += $re[$i];
                }

                $m = count($indexes);
                $p = $sumD / $m;
                $pT = $p + ($sumG / $m) * ($sumDx / $m);
                $share = $sumRe / $m;

                return ['P' => $p, 'P_T' => $pT, 'T' => $pT - $penalty * $share, 'P_penalized' => $p - $penalty * $share];
            },
            self::SEED
        );

        if ($result['blocks_in_range'] === null) {
            return ['ci' => null, 'block_width_days' => null, 'blocks_in_range' => null];
        }

        $ci = [];

        foreach ($result['replicates'] as $name => $replicates) {
            $ci[$name] = $this->statistics->replicateInterval($replicates);
        }

        return ['ci' => $ci, 'block_width_days' => $result['block_width_days'], 'blocks_in_range' => $result['blocks_in_range']];
    }

    /**
     * @param list<array<string, mixed>> $cohort
     * @param array<string, float|int|null> $estimates
     * @param ?array<string, array{se: ?float, ci95_low: float, ci95_high: float}> $ci
     * @return array<string, mixed>
     */
    private function gates(array $cohort, array $estimates, ?array $ci, ?int $blockWidth): array
    {
        $delta = self::DELTA_PP;
        $n = count($cohort);

        if ($n === 0 || $ci === null || $blockWidth === null) {
            return ['evaluable' => false];
        }

        // G1: give-back medio (MFE - V) baja >= 15% relativo frente al fijo.
        $giveBack = static function (string $arm) use ($cohort): float {
            $values = array_map(
                static fn (array $row): float => $row['arms'][$arm]['mfe'] - $row['arms'][$arm]['v'][PolicyReplayHorizonRows::PRIMARY_HORIZON],
                $cohort
            );

            return array_sum($values) / count($values);
        };
        $giveBackFixed = $giveBack('fixed');
        $giveBackTrail = $giveBack('trail');

        // G3: terciles por NUMERO de entradas (cohorte ya ordenada por fecha).
        $terciles = [];

        foreach ([[0, intdiv($n, 3)], [intdiv($n, 3), intdiv(2 * $n, 3)], [intdiv(2 * $n, 3), $n]] as [$from, $to]) {
            $slice = array_slice($cohort, $from, $to - $from);
            $terciles[] = [
                'from' => $slice[0]['entry_date'] ?? null,
                'to' => $slice[count($slice) - 1]['entry_date'] ?? null,
                'n' => count($slice),
                'mean_d' => $slice === [] ? null : array_sum(array_column($slice, 'd')) / count($slice),
            ];
        }

        $tercilesOk = count(array_filter($terciles, static fn (array $t): bool => $t['mean_d'] !== null && $t['mean_d'] < -2.0)) === 0
            && count(array_filter($terciles, static fn (array $t): bool => $t['mean_d'] !== null && $t['mean_d'] >= -1.0)) >= 2;

        // G4: regimen bajista (S&P 500 < SMA200 el dia previo), bloques consecutivos
        // del mismo ancho que el del bootstrap desde la primera fecha de la cohorte.
        $bear = array_values(array_filter($cohort, static fn (array $row): bool => $row['bear'] === true));
        $unknownRegime = count(array_filter($cohort, static fn (array $row): bool => $row['bear'] === null));
        $start = new DateTimeImmutable($cohort[0]['entry_date']);
        $perBlock = [];

        foreach ($bear as $row) {
            $block = intdiv((int) $start->diff(new DateTimeImmutable($row['entry_date']))->days, $blockWidth);
            $perBlock[$block] = ($perBlock[$block] ?? 0) + 1;
        }

        $blocksWithEnough = count(array_filter($perBlock, static fn (int $count): bool => $count >= self::MIN_ENTRIES_PER_BEAR_BLOCK));
        $bearEvaluable = count($bear) >= self::MIN_BEAR_ENTRIES && $blocksWithEnough >= self::MIN_BEAR_BLOCKS;
        $bearMean = $bear === [] ? null : array_sum(array_column($bear, 'd')) / count($bear);

        // G5: robustez.
        $dValues = array_map(static fn (array $row): float => (float) $row['d'], $cohort);
        sort($dValues);
        $trim = (int) floor(0.01 * $n);
        $trimmed = array_slice($dValues, $trim, $n - 2 * $trim);
        $trimmedMean = array_sum($trimmed) / count($trimmed);

        $byTicker = [];

        foreach ($cohort as $row) {
            $byTicker[$row['ticker']] = ($byTicker[$row['ticker']] ?? 0.0) + $row['d'];
        }

        uksort($byTicker, static fn (string $a, string $b): int => [-abs($byTicker[$a]), $a] <=> [-abs($byTicker[$b]), $b]);
        $topTickers = array_slice(array_keys($byTicker), 0, 10);
        $withoutTop = array_values(array_filter($cohort, static fn (array $row): bool => !in_array($row['ticker'], $topTickers, true)));
        $withoutTopMean = $withoutTop === [] ? null : array_sum(array_column($withoutTop, 'd')) / count($withoutTop);

        $cadence10 = $this->statistics->blockBootstrapReplicates(
            $this->pairedDiffs($cohort, PolicyReplayHorizonRows::PRIMARY_HORIZON, 'd10'),
            static function (array $indexes) use ($cohort): array {
                $sum = 0.0;

                foreach ($indexes as $i) {
                    $sum += $cohort[$i]['d10'];
                }

                return ['P10' => $sum / count($indexes)];
            },
            self::SEED
        );
        $ci10 = $this->statistics->replicateInterval($cadence10['replicates']['P10']);
        $p10 = array_sum(array_column($cohort, 'd10')) / $n;
        $p = (float) $estimates['P'];

        return [
            'G1_giveback' => [
                'mean_giveback_fixed' => $giveBackFixed,
                'mean_giveback_trailing' => $giveBackTrail,
                'relative_change' => $giveBackFixed > 0.0 ? $giveBackTrail / $giveBackFixed - 1 : null,
                'pass' => $giveBackFixed > 0.0 && $giveBackTrail <= 0.85 * $giveBackFixed,
            ],
            'G2_reentry_penalized_P' => [
                'P_penalized' => $estimates['P_penalized'],
                'ci95_low' => $ci['P_penalized']['ci95_low'],
                'pass' => $ci['P_penalized']['ci95_low'] >= -$delta,
            ],
            'G3_terciles' => ['terciles' => $terciles, 'pass' => $tercilesOk],
            'G4_bear_regime' => [
                'n_bear' => count($bear),
                'n_regime_unknown' => $unknownRegime,
                'blocks_with_at_least_20' => $blocksWithEnough,
                'evaluable' => $bearEvaluable,
                'mean_d_bear' => $bearMean,
                'pass' => $bearEvaluable && $bearMean !== null && $bearMean >= -$delta,
                'status' => $bearEvaluable ? 'evaluable' : 'INDET (n o bloques insuficientes)',
            ],
            'G5_robustness' => [
                'trimmed_mean_1_99' => $trimmedMean,
                'trimmed_vs_mean_abs_diff' => abs($trimmedMean - $p),
                'trimmed_ok' => abs($trimmedMean - $p) < 0.5,
                'top10_tickers_by_abs_contribution' => $topTickers,
                'mean_d_without_top10' => $withoutTopMean,
                'without_top10_ok' => $withoutTopMean !== null && $withoutTopMean >= -$delta,
                'cadence10_P' => $p10,
                'cadence10_ci95_low' => $ci10['ci95_low'],
                'cadence10_ok' => $ci10['ci95_low'] >= -1.5 && abs($p10 - $p) < 1.0,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $cohort
     * @return array<string, mixed>
     */
    private function descriptive(array $rows, array $cohort, DateTimeImmutable $cutoff, ?int $blockWidth): array
    {
        // Horizontes secundarios (sin decision), cada uno con su resolucion.
        $secondary = [];

        foreach ([21, 90, 250] as $horizon) {
            $sub = $this->cohort($rows, $horizon);
            $sub = array_map(static function (array $row) use ($horizon): array {
                $row['d_h'] = $row['arms']['trail']['v'][$horizon] - $row['arms']['fixed']['v'][$horizon];

                return $row;
            }, $sub);
            $summary = $this->statistics->summarizePairedDiffs($this->pairedDiffs($sub, $horizon, 'd_h'), self::SEED);
            $secondary["H{$horizon}"] = [
                'n' => $summary['cohorts'],
                'avg_diff' => $summary['avg_diff'],
                'ci95_low' => $summary['ci95_low'],
                'ci95_high' => $summary['ci95_high'],
                'blocks_in_range' => $summary['blocks_in_range'],
                'result_informative' => $summary['result_informative'],
            ];
        }

        // Sensibilidad sin el primer bloque de calendario (2017).
        $withoutFirstBlock = null;

        if ($cohort !== [] && $blockWidth !== null) {
            $start = new DateTimeImmutable($cohort[0]['entry_date']);
            $kept = array_values(array_filter(
                $cohort,
                static fn (array $row): bool => (int) $start->diff(new DateTimeImmutable($row['entry_date']))->days >= $blockWidth
            ));
            $withoutFirstBlock = ['n' => count($kept), 'estimates' => $this->estimates($kept)];
        }

        // Whipsaw: salidas trailing anteriores a la del fijo y <= H.
        $early = array_values(array_filter($cohort, static fn (array $row): bool => $row['reentry'] === true));
        $rose = 0;
        $fell = 0;

        foreach ($early as $row) {
            $exit = $row['arms']['trail']['exit_price_raw'];
            $close = $row['closes'][PolicyReplayHorizonRows::PRIMARY_HORIZON]['close'];

            if ($close > $exit * 1.02) {
                $rose++;
            } elseif ($close < $exit * 0.98) {
                $fell++;
            }
        }

        $lifecycle = [];
        $metrics = new PolicyReplayExposureMetrics();

        foreach (['fixed', 'trail'] as $arm) {
            $trades = array_map(
                static fn (array $row): array => [
                    'entry_date' => $row['entry_date'],
                    'exit_date' => $row['arms'][$arm]['exit_date'],
                    'pending' => $row['arms'][$arm]['pending'],
                    'baseline_exit_date' => $row['baseline_exit_date'],
                ],
                $rows
            );
            $spans = [];

            foreach ($trades as $trade) {
                if ($trade['baseline_exit_date'] === null) {
                    continue;
                }

                $spans[] = (int) (new DateTimeImmutable($trade['entry_date']))->diff(new DateTimeImmutable(max($trade['exit_date'], $trade['baseline_exit_date'])))->days;
            }

            sort($spans);
            $count = count($spans);
            $pending = count(array_filter($rows, static fn (array $row): bool => $row['arms'][$arm]['pending']));
            $holdDiffs = array_values(array_filter(array_map(
                static fn (array $row): ?float => $row['baseline_return'] === null ? null : $row['arms'][$arm]['managed_return'] - $row['baseline_return'],
                $rows
            ), static fn (?float $value): bool => $value !== null));

            $lifecycle[$arm] = [
                'S91' => $metrics->exposureShare([['ticker' => 'ALL', 'trades' => $trades]], $cutoff),
                'exposure_days' => $count === 0 ? [] : ['n' => $count, 'p50' => $spans[(int) floor(0.5 * ($count - 1))], 'p90' => $spans[(int) floor(0.9 * ($count - 1))], 'max' => $spans[$count - 1]],
                'pending_share_pct' => $rows === [] ? null : round($pending / count($rows) * 100, 2),
                'avg_diff_vs_hold20_pending_marked_to_market' => $holdDiffs === [] ? null : array_sum($holdDiffs) / count($holdDiffs),
            ];
        }

        $raises = array_column($rows, 'stop_raises');

        return [
            'secondary_horizons' => $secondary,
            'sensitivity_without_first_block' => $withoutFirstBlock,
            'whipsaw' => [
                'early_trailing_exits' => count($early),
                'price_above_exit_by_more_than_2pct' => $rose,
                'price_below_exit_by_more_than_2pct' => $fell,
            ],
            'lifecycle' => $lifecycle,
            'mean_stop_raises_per_trade' => $raises === [] ? null : array_sum($raises) / count($raises),
        ];
    }
}
