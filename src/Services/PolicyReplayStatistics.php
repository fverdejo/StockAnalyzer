<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;

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
 * significancia. `blocked` agrupa las operaciones por VENTANA DE
 * CALENDARIO DE ANCHO FIJO (no por cadena de solape) antes de calcular el
 * t-stat -- menos "votos", pero honestos sobre la dependencia temporal.
 * Una divergencia grande entre `naive` y `blocked` es en si misma la
 * medida de cuanto exceso de confianza introduce el solape, mismo
 * espiritu que `alpha_t_stat` vs `pooled_alpha_t_stat` en
 * `BacktestingService::runCrossSectional()`.
 *
 * **Por que ventanas de ancho FIJO y no una cadena que se extiende
 * mientras algo la toque** (segunda consulta a `auditor-estadistico`,
 * `2026-09-14`, tras un piloto real de 60 tickers donde una cadena
 * colapso 82 diferencias emparejadas en solo 3 bloques): agrupar POR
 * TICKER no serviria de nada -- `PolicyReplaySimulator` garantiza una
 * sola posicion activa por ticker (`$inPosition`), asi que dos
 * operaciones del MISMO ticker nunca se solapan en el tiempo, es
 * estructuralmente imposible; la dependencia real (marzo y junio
 * compartiendo regimen de mercado) es siempre ENTRE tickers distintos.
 * Y una cadena GLOBAL (todos los tickers juntos, se extiende mientras la
 * siguiente entrada caiga antes de que salga la mas tardia del bloque)
 * es transitiva: con cientos de tickers y holdings de meses, casi
 * siempre hay ALGUNA posicion abierta en algun ticker, asi que la cadena
 * practicamente nunca se rompe y todo colapsa a 1-2 bloques -- no es
 * "bloques de duracion >= mediana de holding", es el caso degenerado de
 * un unico bloque. La correccion: particionar el EJE TEMPORAL en tramos
 * de ancho fijo `W` = mediana de la duracion (en dias naturales, no
 * sesiones bursatiles) de las propias operaciones emparejadas, empezando
 * en la fecha de entrada mas antigua; cada operacion se asigna a la
 * ventana que contiene SU fecha de entrada. El numero de bloques sale asi
 * de `rango_temporal_total / W`, no de cuantas operaciones se solapan.
 *
 * **Umbral minimo de bloques, predeclarado antes de medir sobre el
 * universo completo** (mismo origen, `2026-09-14`): con menos de 10
 * bloques, `t_stat_blocked` se reporta igual como diagnostico, pero el
 * hallazgo se documenta como NO CONCLUYENTE frente al umbral
 * `\|t\|>=1,96` predeclarado en `roadmap.md`, con independencia del valor
 * numerico que salga -- no se decide esto despues de ver cuantos bloques
 * da la medicion real.
 *
 * Formula identica a `splitHalfStats()`/`$calc` de
 * `storage/scratch/run_point_in_time_backtest.php` (ya usada para medir
 * `config/measured_edge.php`): media, desviacion muestral (n-1), error
 * estandar = desviacion/sqrt(n), t = media/error estandar.
 */
final class PolicyReplayStatistics
{
    /**
     * Bajo este numero de bloques, `t_stat_blocked` sigue calculandose
     * (diagnostico), pero no autoriza una promocion a "ventaja validada"
     * -- ver el docblock de la clase.
     */
    private const MIN_CONCLUSIVE_BLOCKS = 10;

    /**
     * @param list<array{ticker: string, trades: list<array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: float, baseline_return: ?float, baseline_pending: bool, revisar_tesis_events: int}>, entries_total: int, entries_closed: int, entries_pending: int}> $replaysByTicker
     * @return array{entries_total: int, entries_closed: int, entries_pending: int, pct_pending: ?float, pending_avg_managed_return: ?float, cohorts_naive: int, avg_diff_naive: ?float, stderr_diff_naive: ?float, t_stat_naive: ?float, cohorts_blocked: int, block_width_days: ?int, avg_diff_blocked: ?float, stderr_diff_blocked: ?float, t_stat_blocked: ?float, blocked_design_conclusive: bool, revisar_tesis_events_total: int}
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

        $blockWidthDays = $this->calendarBlockWidth($pairedDiffs);
        $blockedValues = $blockWidthDays === null ? [] : $this->clusterIntoCalendarWindows($pairedDiffs, $blockWidthDays);
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
            'block_width_days' => $blockWidthDays,
            'avg_diff_blocked' => $blockedMean,
            'stderr_diff_blocked' => $blockedStderr,
            't_stat_blocked' => $blockedT,
            'blocked_design_conclusive' => count($blockedValues) >= self::MIN_CONCLUSIVE_BLOCKS,
            'revisar_tesis_events_total' => $revisarTesisEventsTotal,
        ];
    }

    /**
     * `W`: mediana de la duracion de cada operacion emparejada, en DIAS
     * NATURALES (no sesiones bursatiles) -- el eje que se particiona es el
     * calendario, no el propio historico de ningun ticker. `null` sin
     * operaciones emparejables (nada que particionar).
     *
     * @param list<array{entry_date: string, exit_date: string, diff: float}> $diffs
     */
    private function calendarBlockWidth(array $diffs): ?int
    {
        if ($diffs === []) {
            return null;
        }

        $holdingDays = array_map(
            static fn (array $item): int => (new DateTimeImmutable($item['entry_date']))
                ->diff(new DateTimeImmutable($item['exit_date']))
                ->days,
            $diffs
        );
        sort($holdingDays);

        // Minimo de 1 dia: una mediana de 0 (todas las operaciones
        // cerradas el mismo dia que entraron) dejaria una anchura de
        // ventana nula, indefinida para particionar el calendario.
        return max(1, $this->median($holdingDays));
    }

    /**
     * Particiona el calendario en tramos de anchura fija `$windowDays`,
     * empezando en la fecha de entrada MAS ANTIGUA de `$diffs`; cada
     * operacion se asigna por SU fecha de entrada (mismo criterio "una
     * entrada = un voto" que el resto de este proyecto). El numero de
     * bloques resultante depende del rango temporal total y de `W`, NO de
     * cuantas operaciones se solapen entre si -- a diferencia de una
     * cadena por solape, que con cientos de tickers y holdings largos casi
     * siempre degenera en un unico bloque (ver el docblock de la clase).
     *
     * @param list<array{entry_date: string, exit_date: string, diff: float}> $diffs
     * @return list<float> media de cada ventana con al menos una operacion
     */
    private function clusterIntoCalendarWindows(array $diffs, int $windowDays): array
    {
        $entryDates = array_map(
            static fn (array $item): DateTimeImmutable => new DateTimeImmutable($item['entry_date']),
            $diffs
        );

        $start = $entryDates[0];

        foreach ($entryDates as $date) {
            if ($date < $start) {
                $start = $date;
            }
        }

        $buckets = [];

        foreach ($diffs as $index => $item) {
            $daysSinceStart = $start->diff($entryDates[$index])->days;
            $windowIndex = intdiv($daysSinceStart, $windowDays);
            $buckets[$windowIndex][] = $item['diff'];
        }

        return array_map(
            static fn (array $bucket): float => array_sum($bucket) / count($bucket),
            array_values($buckets)
        );
    }

    /**
     * @param list<int> $sortedValues YA ordenados ascendentemente
     */
    private function median(array $sortedValues): int
    {
        $n = count($sortedValues);
        $mid = intdiv($n, 2);

        if ($n % 2 === 1) {
            return $sortedValues[$mid];
        }

        return intdiv($sortedValues[$mid - 1] + $sortedValues[$mid], 2);
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
