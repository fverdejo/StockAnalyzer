<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;

/**
 * Metrica de viabilidad del piloto de trailing (`2026-09-20`, especificacion
 * predeclarada por `gestor-riesgo`): S91, la proporcion de entradas cuya
 * EXPOSICION supera 91 dias naturales.
 *
 * Por que no `blocks_in_range` (ver `PolicyReplayStatistics`): en una
 * ventana de 2 años exigir `blocks_in_range >= 20` equivale a exigir un P90
 * de exposicion <= ~18 dias, imposible por construccion para una politica
 * sin horizonte -- el criterio fallaria siempre y no distinguiria una
 * regla de salida buena de una mala. S91 mide lo mismo que hace fallar a la
 * medicion 1 (la cola larga de exposicion) sin depender del largo de la
 * ventana.
 *
 * Exposicion de una entrada = dias naturales desde la entrada hasta la
 * fecha MAS TARDIA entre la salida gestionada (o el corte, si sigue
 * pendiente) y la salida del comparador de 20 sesiones -- el mismo criterio
 * que `PolicyReplayStatistics::summarize()` para el ancho de bloque.
 *
 * Cohorte: solo entradas con al menos `$days` dias hasta el corte, para que
 * "exposicion > $days" sea observable sin censura. Una entrada pendiente
 * cuya exposicion mide EXACTAMENTE `$days` esta en la cohorte pero no puede
 * contar como "> $days" (no se sabe que hara despues): se cuenta aparte en
 * `pending_at_boundary` para poder ver que es despreciable.
 */
final class PolicyReplayExposureMetrics
{
    /**
     * @param list<array{ticker: string, trades: list<array{entry_date: string, exit_date: string, pending: bool, baseline_exit_date: ?string}>}> $replaysByTicker
     * @return array{days: int, cutoff: string, entries_total: int, cohort: int, over: int, share_pct: ?float, pending_at_boundary: int}
     */
    public function exposureShare(array $replaysByTicker, DateTimeImmutable $cutoff, int $days = 91): array
    {
        $entriesTotal = 0;
        $cohort = 0;
        $over = 0;
        $pendingAtBoundary = 0;

        foreach ($replaysByTicker as $replay) {
            foreach ($replay['trades'] as $trade) {
                $entriesTotal++;

                $entry = new DateTimeImmutable($trade['entry_date']);

                if ($entry->diff($cutoff)->invert === 1 || (int) $entry->diff($cutoff)->days < $days) {
                    continue;
                }

                $cohort++;

                $endDate = $trade['exit_date'];

                if ($trade['baseline_exit_date'] !== null && $trade['baseline_exit_date'] > $endDate) {
                    $endDate = $trade['baseline_exit_date'];
                }

                $exposureDays = (int) $entry->diff(new DateTimeImmutable($endDate))->days;

                if ($exposureDays > $days) {
                    $over++;
                } elseif ($trade['pending'] && $exposureDays === $days) {
                    $pendingAtBoundary++;
                }
            }
        }

        return [
            'days' => $days,
            'cutoff' => $cutoff->format('Y-m-d'),
            'entries_total' => $entriesTotal,
            'cohort' => $cohort,
            'over' => $over,
            'share_pct' => $cohort > 0 ? round($over / $cohort * 100, 2) : null,
            'pending_at_boundary' => $pendingAtBoundary,
        ];
    }
}
