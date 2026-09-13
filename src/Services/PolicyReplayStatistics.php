<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

/**
 * Agrega los resultados de `PolicyReplaySimulator::replay()` de MUCHOS
 * tickers en una unica medicion de utilidad economica (Entrega 3/4 de
 * `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`).
 *
 * Metrica primaria: diferencia PAREADA por operacion entre el retorno
 * gestionado (siguiendo `PositionDecisionAdvisor` de verdad) y el
 * comparador de veinte sesiones fijas, SOLO sobre operaciones YA CERRADAS
 * por stop-loss (las pendientes al corte se excluyen de esta metrica,
 * consenso de `2026-09-13`: no se les atribuye una orden que la politica
 * nunca dio).
 *
 * **Por que dos t-stat, no uno** (consenso de `auditor-estadistico`,
 * `2026-09-13`): el diseño transversal de `BacktestingService` trata cada
 * FECHA como un voto independiente porque `runCrossSectional()` exige
 * `step >= horizonDays`, lo que garantiza que las ventanas de retorno de
 * dos fechas distintas nunca se solapan. Aqui esa garantia NO existe: las
 * operaciones duran meses variables (sin horizonte fijo, ver
 * `PolicyReplaySimulator`), asi que una entrada de marzo y otra de junio
 * pueden compartir exposicion al mismo tramo de mercado. Tratar cada
 * operacion como un voto independiente (`naive`) puede inflar la
 * significancia. `blocked` agrupa las operaciones en bloques
 * cronologicos SIN SOLAPAR (una entrada nueva empieza un bloque nuevo solo
 * si su fecha de entrada es POSTERIOR a la salida mas tardia de todo el
 * bloque anterior) y promedia cada bloque a un unico valor antes de
 * calcular el t-stat -- menos "votos", pero honestos sobre la
 * dependencia temporal. Una divergencia grande entre `naive` y `blocked`
 * es en si misma la medida de cuanto exceso de confianza introduce el
 * solape, mismo espiritu que `alpha_t_stat` vs `pooled_alpha_t_stat` en
 * `BacktestingService::runCrossSectional()`.
 *
 * Formula identica a `splitHalfStats()`/`$calc` de
 * `storage/scratch/run_point_in_time_backtest.php` (ya usada para medir
 * `config/measured_edge.php`): media, desviacion muestral (n-1), error
 * estandar = desviacion/sqrt(n), t = media/error estandar.
 */
final class PolicyReplayStatistics
{
    /**
     * @param list<array{ticker: string, trades: list<array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: float, baseline_return: ?float, baseline_pending: bool, revisar_tesis_events: int}>, entries_total: int, entries_closed: int, entries_pending: int}> $replaysByTicker
     * @return array{entries_total: int, entries_closed: int, entries_pending: int, pct_pending: ?float, pending_avg_managed_return: ?float, cohorts_naive: int, avg_diff_naive: ?float, stderr_diff_naive: ?float, t_stat_naive: ?float, cohorts_blocked: int, avg_diff_blocked: ?float, stderr_diff_blocked: ?float, t_stat_blocked: ?float, revisar_tesis_events_total: int}
     */
    public function summarize(array $replaysByTicker): array
    {
        $entriesTotal = 0;
        $entriesClosed = 0;
        $entriesPending = 0;
        $pendingManagedReturns = [];
        $revisarTesisEventsTotal = 0;
        /** @var list<array{entry_date: string, exit_date: string, diff: float}> $pairedDiffs */
        $pairedDiffs = [];

        foreach ($replaysByTicker as $replay) {
            foreach ($replay['trades'] as $trade) {
                $entriesTotal++;
                $revisarTesisEventsTotal += $trade['revisar_tesis_events'];

                if ($trade['pending']) {
                    $entriesPending++;
                    $pendingManagedReturns[] = $trade['managed_return'];

                    continue;
                }

                $entriesClosed++;

                if ($trade['baseline_return'] === null) {
                    // Operacion ya cerrada por stop-loss, pero el
                    // comparador de 20 sesiones todavia no tiene desenlace
                    // (cerca del corte): no se puede emparejar, se excluye
                    // solo de la diferencia, no del conteo de cerradas.
                    continue;
                }

                $pairedDiffs[] = [
                    'entry_date' => $trade['entry_date'],
                    'exit_date' => $trade['exit_date'],
                    'diff' => $trade['managed_return'] - $trade['baseline_return'],
                ];
            }
        }

        usort($pairedDiffs, static fn (array $a, array $b): int => $a['entry_date'] <=> $b['entry_date']);

        $naiveValues = array_column($pairedDiffs, 'diff');
        [$naiveMean, $naiveStderr, $naiveT] = $this->pairedStats($naiveValues);

        $blockedValues = $this->clusterIntoNonOverlappingBlocks($pairedDiffs);
        [$blockedMean, $blockedStderr, $blockedT] = $this->pairedStats($blockedValues);

        return [
            'entries_total' => $entriesTotal,
            'entries_closed' => $entriesClosed,
            'entries_pending' => $entriesPending,
            'pct_pending' => $entriesTotal > 0 ? round($entriesPending / $entriesTotal * 100, 2) : null,
            'pending_avg_managed_return' => $this->average($pendingManagedReturns),
            'cohorts_naive' => count($naiveValues),
            'avg_diff_naive' => $naiveMean,
            'stderr_diff_naive' => $naiveStderr,
            't_stat_naive' => $naiveT,
            'cohorts_blocked' => count($blockedValues),
            'avg_diff_blocked' => $blockedMean,
            'stderr_diff_blocked' => $blockedStderr,
            't_stat_blocked' => $blockedT,
            'revisar_tesis_events_total' => $revisarTesisEventsTotal,
        ];
    }

    /**
     * @param list<array{entry_date: string, exit_date: string, diff: float}> $sortedDiffs YA ordenados por entry_date
     * @return list<float> media de cada bloque
     */
    private function clusterIntoNonOverlappingBlocks(array $sortedDiffs): array
    {
        $blocks = [];
        $currentBlock = [];
        $blockEndDate = null;

        foreach ($sortedDiffs as $item) {
            if ($blockEndDate !== null && $item['entry_date'] > $blockEndDate) {
                $blocks[] = array_sum($currentBlock) / count($currentBlock);
                $currentBlock = [];
                $blockEndDate = null;
            }

            $currentBlock[] = $item['diff'];
            $blockEndDate = $blockEndDate === null ? $item['exit_date'] : max($blockEndDate, $item['exit_date']);
        }

        if ($currentBlock !== []) {
            $blocks[] = array_sum($currentBlock) / count($currentBlock);
        }

        return $blocks;
    }

    /**
     * @param list<float> $values
     * @return array{0: ?float, 1: ?float, 2: ?float} media, error estandar, t
     */
    private function pairedStats(array $values): array
    {
        $n = count($values);

        if ($n === 0) {
            return [null, null, null];
        }

        $mean = array_sum($values) / $n;

        if ($n < 2) {
            return [round($mean, 2), null, null];
        }

        $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / ($n - 1);
        $stderr = sqrt($variance) / sqrt($n);
        $t = $stderr > 0.0 ? $mean / $stderr : null;

        return [round($mean, 2), round($stderr, 3), $t !== null ? round($t, 2) : null];
    }

    /**
     * @param list<float> $values
     */
    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }
}
