<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use LogicException;

/**
 * Analisis del protocolo de utilidad del motor (predeclaracion cerrada del
 * 2026-09-22, `versions.md`) sobre las filas de
 * `FundamentalFilterMeasurementRows`. Solo lee filas ya persistidas: no
 * toca la base de datos ni la red.
 *
 * **Metrica PRIMARIA (fallo de arbitraje final de `auditor-estadistico`,
 * aceptando la objecion de `gestor-riesgo` sobre la v1 de `analista-mercado`):**
 * `P_cond = media(D_i | D/E_i >= 2,0)`, SOLO sobre el subconjunto RECHAZADO
 * de la cohorte evaluable de B (equivale a `-media(V_A(i))` en esas
 * entradas). El bootstrap circular de bloques
 * (`PolicyReplayStatistics::blockBootstrapReplicates()`, sin cambios de
 * formula) y sus salvaguardas (`blocks_in_range>=20`, `effective_n>=30`) se
 * calculan sobre ESE subconjunto, no sobre la cohorte completa -- por eso
 * importa el gate de cobertura (rechazo >=3%, analogo a
 * `reviews_without_candidate<=5%` del trailing). `delta`=1,0pp se aplica a
 * `P_cond` (aqui SI es una media continua de retornos por operacion, misma
 * forma que el `D_i` del trailing -- la analogia de orden de magnitud que
 * la v1 daba por buena para la media DILUIDA no era valida).
 *
 * `P` (la media diluida sobre TODA la cohorte evaluable de B, con `D_i=0`
 * en las aceptadas) pasa a SECUNDARIA informativa, sin veredicto -- mismo
 * rol que `D'_i` (tecnica+gestion vs mantener sin gestionar). Cumple
 * EXACTAMENTE `P = tasa_rechazo x P_cond` (assert de la predeclaracion,
 * comprobado aqui, no supuesto).
 *
 * Cuatro veredictos, exhaustivos y mutuamente excluyentes por construccion
 * (una unica rama `match(true) { ... default => }`, nunca `elseif`
 * reordenables): VALIDADO, DESCARTADO, INSUFICIENTE (informativo pero ni lo
 * uno ni lo otro -- DISTINTO de no informativo, no se relanza con otro
 * umbral tras verlo) y NO_INFORMATIVO.
 *
 * Multiplicidad acumulada del proyecto (obligatorio de `auditor-estadistico`,
 * cambio 4): esta es la QUINTA prueba formal de "fundamentales vs retorno"
 * sobre este universo/periodo (score compuesto x4: P3.3/v2.114/sp400/sp600,
 * veredicto nulo las cuatro veces, mas este filtro D/E). Un VALIDADO aqui es
 * PROVISIONAL, pendiente de confirmacion prospectiva -- que solo puede
 * llegar por A-vs-C (`D'_i`), porque B queda bloqueada en evaluacion
 * prospectiva genuina desde que expiro la suscripcion de EODHD el
 * 2026-10-01 (§2 de la predeclaracion).
 */
final class FundamentalFilterAnalysis
{
    public const SEED = 20260922;
    public const DELTA_PP = 1.0;
    public const REENTRY_PENALTY_NOT_APPLICABLE = null; // Fase 1 sin reentradas: no hay coste de reentrada que penalizar (a diferencia del trailing).

    /** Gate de cobertura (`gestor-riesgo`): fijado por analogia con `reviews_without_candidate<=5%` del trailing, SIN haber mirado antes la tasa real de este dataset. */
    public const MIN_REJECTION_RATE_PCT = 3.0;

    /** Sectores excluidos del universo de esta fase (misma regla ya vigente en produccion, `FundamentalSectorExclusion`). */
    public const EXCLUDED_SECTORS = ['Financial Services', 'Real Estate'];

    public function __construct(
        private readonly PolicyReplayStatistics $statistics = new PolicyReplayStatistics()
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows filas de `FundamentalFilterMeasurementRows::rows()` de TODOS los tickers
     * @return array<string, mixed>
     */
    public function analyze(array $rows): array
    {
        $cohort = array_values(array_filter($rows, static fn (array $row): bool => $row['in_cohort']));
        $evaluable = array_values(array_filter($cohort, static fn (array $row): bool => $row['de_evaluable']));
        $notEvaluable = array_values(array_filter($cohort, static fn (array $row): bool => !$row['de_evaluable']));
        $rejected = array_values(array_filter($evaluable, static fn (array $row): bool => $row['de_rejected']));
        $accepted = array_values(array_filter($evaluable, static fn (array $row): bool => !$row['de_rejected']));

        // Assert de particion completa (predeclaracion §8, assert 4).
        if (count($evaluable) + count($notEvaluable) !== count($cohort)) {
            throw new LogicException('La particion evaluable/no-evaluable no cubre exactamente la cohorte.');
        }

        // Invariante por fila que GARANTIZA la identidad P = tasa_rechazo x
        // P_cond (assert 14): aceptada -> d=0 exacto; rechazada -> d=-v_a.
        // Esta clase no confia en que quien construyo las filas (fuera de su
        // control, pueden venir de disco) cumpliera `FundamentalFilterMeasurementRows`
        // al pie de la letra -- lo comprueba ella misma.
        foreach ($evaluable as $row) {
            $expectedD = $row['de_rejected'] ? -(float) $row['v_a'] : 0.0;

            if (abs((float) $row['d'] - $expectedD) > 1e-9) {
                throw new LogicException(sprintf(
                    '%s %s: d=%s no coincide con el esperado (%s) para de_rejected=%s.',
                    $row['ticker'],
                    $row['entry_date'],
                    var_export($row['d'], true),
                    var_export($expectedD, true),
                    var_export($row['de_rejected'], true)
                ));
            }
        }

        $n = count($evaluable);
        $rejectionRatePct = $n > 0 ? count($rejected) / $n * 100 : null;
        $coverageOk = $rejectionRatePct !== null && $rejectionRatePct >= self::MIN_REJECTION_RATE_PCT;

        $rejectedDiffs = $this->pairedDiffs($rejected, 'd');
        $primarySummary = $this->statistics->summarizePairedDiffsPrecise($rejectedDiffs, self::SEED);
        $pCond = $primarySummary['avg_diff'];

        $allDiffs = $this->pairedDiffs($evaluable, 'd');
        $secondarySummary = $this->statistics->summarizePairedDiffsPrecise($allDiffs, self::SEED);
        $p = $secondarySummary['avg_diff'];

        $primeDiffs = $this->pairedDiffs($cohort, 'd_prime');
        $primeSummary = $this->statistics->summarizePairedDiffsPrecise($primeDiffs, self::SEED);

        // Assert 14 (arbitraje): P = tasa_rechazo x P_cond, exacto.
        if ($p !== null && $pCond !== null && $n > 0) {
            $expectedP = (count($rejected) / $n) * $pCond;

            if (abs($p - $expectedP) > 1e-9) {
                throw new LogicException(sprintf('Identidad P = tasa_rechazo x P_cond violada: P=%.10f esperado=%.10f', $p, $expectedP));
            }
        }

        $informative = $coverageOk && $primarySummary['result_informative'];
        $ciLow = $primarySummary['ci95_low'];
        $ciHigh = $primarySummary['ci95_high'];

        // Assert 15 (obligatorio de auditor-estadistico): rama UNICA, exhaustiva y mutuamente excluyente.
        $verdict = match (true) {
            !$informative => 'NO_INFORMATIVO',
            $pCond >= self::DELTA_PP && $ciLow !== null && $ciLow > 0.0 => 'VALIDADO',
            $ciHigh !== null && $ciHigh < 0.0 => 'DESCARTADO',
            default => 'INSUFICIENTE',
        };

        return [
            'cohort_n' => count($cohort),
            'evaluable_n' => $n,
            'not_evaluable_n' => count($notEvaluable),
            'not_evaluable_by_reason' => $this->countByReason($notEvaluable),
            'accepted_n' => count($accepted),
            'rejected_n' => count($rejected),
            'rejection_rate_pct' => $rejectionRatePct !== null ? round($rejectionRatePct, 2) : null,
            'coverage_gate_min_pct' => self::MIN_REJECTION_RATE_PCT,
            'coverage_gate_ok' => $coverageOk,
            'primary' => [
                'label' => 'P_cond = media(D_i | D/E >= 2,0), solo entradas rechazadas',
                'delta_pp' => self::DELTA_PP,
                'p_cond' => $pCond,
                'ci95_low' => $ciLow,
                'ci95_high' => $ciHigh,
                'blocks_in_range' => $primarySummary['blocks_in_range'],
                'effective_n' => $primarySummary['effective_n'],
                'result_informative' => $primarySummary['result_informative'],
                'informative_overall' => $informative,
            ],
            'verdict' => $verdict,
            'secondary_diluted' => [
                'label' => 'P = media(D_i), toda la cohorte evaluable (D_i=0 en aceptadas) -- sin veredicto',
                'p' => $p,
                'ci95_low' => $secondarySummary['ci95_low'],
                'ci95_high' => $secondarySummary['ci95_high'],
                'blocks_in_range' => $secondarySummary['blocks_in_range'],
                'result_informative' => $secondarySummary['result_informative'],
            ],
            'secondary_a_vs_c' => [
                'label' => "D'_i = V_A - V_C (tecnica+gestion vs mantener sin gestionar) -- sin veredicto",
                'mean' => $primeSummary['avg_diff'],
                'ci95_low' => $primeSummary['ci95_low'],
                'ci95_high' => $primeSummary['ci95_high'],
                'blocks_in_range' => $primeSummary['blocks_in_range'],
                'result_informative' => $primeSummary['result_informative'],
            ],
            'descriptive' => $this->descriptive($cohort, $accepted, $rejected),
            'multiplicity_note' => 'Quinta prueba formal de fundamentales-vs-retorno sobre este universo/periodo (score compuesto x4 con veredicto nulo, mas este filtro D/E). Un VALIDADO es provisional, pendiente de confirmacion prospectiva via D_prime (A-vs-C); B esta bloqueada en evaluacion prospectiva genuina desde el fin de la suscripcion EODHD (2026-10-01).',
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{entry_date: string, exposure_end_date: string, diff: float}>
     */
    private function pairedDiffs(array $rows, string $field): array
    {
        $diffs = [];

        foreach ($rows as $row) {
            if ($row[$field] === null) {
                continue;
            }

            $diffs[] = [
                'entry_date' => $row['entry_date'],
                'exposure_end_date' => $row['entry_date'],
                'diff' => (float) $row[$field],
            ];
        }

        return $diffs;
    }

    /**
     * @param list<array<string, mixed>> $notEvaluable
     * @return array<string, int>
     */
    private function countByReason(array $notEvaluable): array
    {
        $byReason = [];

        foreach ($notEvaluable as $row) {
            $reason = (string) ($row['de_not_evaluable_reason'] ?? 'sin_motivo_registrado');
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }

        return $byReason;
    }

    /**
     * @param list<array<string, mixed>> $cohort
     * @param list<array<string, mixed>> $accepted
     * @param list<array<string, mixed>> $rejected
     * @return array<string, mixed>
     */
    private function descriptive(array $cohort, array $accepted, array $rejected): array
    {
        $percentiles = static function (array $values, float $p): ?float {
            if ($values === []) {
                return null;
            }

            sort($values);
            $index = (int) floor($p * (count($values) - 1));

            return $values[$index];
        };

        $vaRejected = array_column($rejected, 'v_a');
        $vaAccepted = array_column($accepted, 'v_a');

        $bySector = [];

        foreach ($cohort as $row) {
            $sector = (string) $row['sector'];
            $bySector[$sector] ??= ['cohort' => 0, 'rejected' => 0];
            $bySector[$sector]['cohort']++;
        }

        foreach ($rejected as $row) {
            $bySector[(string) $row['sector']]['rejected']++;
        }

        ksort($bySector);

        return [
            'v_a_rejected' => [
                'n' => count($vaRejected),
                'mean' => $vaRejected === [] ? null : round(array_sum($vaRejected) / count($vaRejected), 3),
                'p50' => $percentiles($vaRejected, 0.5),
                'p5' => $percentiles($vaRejected, 0.05),
                'p1' => $percentiles($vaRejected, 0.01),
            ],
            'v_a_accepted' => [
                'n' => count($vaAccepted),
                'mean' => $vaAccepted === [] ? null : round(array_sum($vaAccepted) / count($vaAccepted), 3),
                'p50' => $percentiles($vaAccepted, 0.5),
                'p5' => $percentiles($vaAccepted, 0.05),
                'p1' => $percentiles($vaAccepted, 0.01),
            ],
            'sector_breakdown' => $bySector,
        ];
    }
}
