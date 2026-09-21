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
 *
 * Correccion del 2026-09-22 (encargo C6 de Astra): la cohorte por fecha de
 * corte GLOBAL admitia una operacion pendiente cuyo historico terminaba
 * ANTES de cumplir `$days` (entrada 03/01/2024, ultimo dato 19/02/2024, corte
 * 31/12/2024 -> cohorte 1, S91 0%, aunque no hay seguimiento). Ahora una
 * pendiente con menos de `$days` de seguimiento real queda CENSURADA
 * (`censored_short_followup`), y las entradas aun sin `$days` hasta el corte
 * se cuentan aparte (`not_yet_in_window`). En las 636 salidas completas de
 * la medicion de trailing el efecto es nulo (ningun pendiente con historico
 * corto): es una correccion de contrato, no cambia ese resultado.
 */
final class PolicyReplayExposureMetrics
{
    /**
     * @param list<array{ticker: string, trades: list<array{entry_date: string, exit_date: string, pending: bool, baseline_exit_date: ?string}>}> $replaysByTicker
     * @return array{days: int, cutoff: string, entries_total: int, cohort: int, over: int, share_pct: ?float, pending_at_boundary: int, censored_short_followup: int, not_yet_in_window: int}
     */
    public function exposureShare(array $replaysByTicker, DateTimeImmutable $cutoff, int $days = 91): array
    {
        $entriesTotal = 0;
        $cohort = 0;
        $over = 0;
        $pendingAtBoundary = 0;
        $censoredShortFollowup = 0;
        $notYetInWindow = 0;

        foreach ($replaysByTicker as $replay) {
            foreach ($replay['trades'] as $trade) {
                $entriesTotal++;

                $entry = new DateTimeImmutable($trade['entry_date']);

                if ($entry->diff($cutoff)->invert === 1 || (int) $entry->diff($cutoff)->days < $days) {
                    $notYetInWindow++;

                    continue;
                }

                $endDate = $trade['exit_date'];

                if ($trade['baseline_exit_date'] !== null && $trade['baseline_exit_date'] > $endDate) {
                    $endDate = $trade['baseline_exit_date'];
                }

                $exposureDays = (int) $entry->diff(new DateTimeImmutable($endDate))->days;

                // Seguimiento OBSERVABLE (C6, `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`):
                // la cohorte por fecha de corte GLOBAL no basta. Una operacion
                // PENDIENTE cuyo ultimo dato (el historico de ese ticker acaba
                // antes del corte: deslistado, hueco) queda antes de cumplir
                // `$days` no tiene resultado conocido: no puede entrar como
                // "no supera `$days`". Se cuenta aparte (censurada). Una
                // operacion CERRADA antes de `$days` si tiene resultado
                // conocido (su comparador de ~28 dias no puede superarlo).
                if ($trade['pending'] && $exposureDays < $days) {
                    $censoredShortFollowup++;

                    continue;
                }

                $cohort++;

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
            'censored_short_followup' => $censoredShortFollowup,
            'not_yet_in_window' => $notYetInWindow,
        ];
    }
}
