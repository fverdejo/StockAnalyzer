<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\Concerns\StopLossExitCalculator;

/**
 * Segunda medicion propuesta por Astra (`REVISION_REPLAY_MOTOR_ASTRA_2026-09-14.md`,
 * caso 6): "misma entrada y misma fecha de valoracion".
 *
 * Por que existe una clase aparte de `PolicyReplaySimulator`: ese simulador
 * mide "cuanto rinde SEGUIR la ficha indefinidamente" (una sola posicion
 * por ticker, sin horizonte, solo sale por stop) -- y su propia
 * comparacion primaria selecciona SOLO operaciones cerradas por stop, lo
 * que Astra señala (con razon, ver `versions.md`, `2026-09-14`) que sesga
 * la muestra hacia las perdedoras: las ganadoras nunca se cierran solas,
 * asi que nunca entran en esa comparacion. Esta clase mide una pregunta
 * DISTINTA y mas acotada: "de las mismas candidatas aceptadas, ¿gestionar
 * con el stop durante VEINTE SESIONES (ni un dia mas) aporta algo frente a
 * mantener sin gestionar esas mismas veinte sesiones?" -- ambos brazos se
 * valoran a la MISMA fecha de corte, asi que las ganadoras SI entran (a
 * precio de mercado en esa fecha, ni mas ni menos que las perdedoras).
 *
 * **Esto NO mide "el beneficio de mantener ganadoras durante años"** (esa
 * es la pregunta de `PolicyReplaySimulator`, con sus limitaciones ya
 * documentadas) **ni sustituye la contabilidad de una cartera real**
 * (capital finito, reglas de admision/desempate cuando varias candidatas
 * compiten, reinversion, valor diario) -- Astra es explicita en que este
 * "episodio" es un paso previo, deliberadamente mas simple.
 *
 * Diseño (literal de Astra, caso 6):
 *
 * - Cada candidata aceptada (misma regla que `PolicyReplaySimulator`:
 *   `recommendation === 'BUY'`, elegible por indice, con stop calculable)
 *   abre su PROPIO episodio, independiente de si otro episodio anterior
 *   del mismo ticker sigue "abierto" -- a diferencia de
 *   `PolicyReplaySimulator`, aqui NO hay una sola posicion activa por
 *   ticker. Astra es explicita: "Todos los candidatos pueden generar
 *   episodios solapados; eso no constituye una cartera ejecutable ni
 *   elimina su dependencia" -- el solape es un supuesto conocido de esta
 *   pregunta concreta, no un descuido.
 * - Fecha de valoracion comun del episodio: `entrada + 20` sesiones (el
 *   horizonte estandar del proyecto, no un numero nuevo).
 * - Brazo gestionado: vigila el stop ADOPTADO a diario, igual que
 *   `PolicyReplaySimulator`. Si se cruza ANTES de la fecha de valoracion,
 *   el episodio usa ESE retorno (el efectivo resultante no rinde nada
 *   durante los dias que falten hasta la valoracion -- supuesto declarado
 *   explicitamente, ni coste de oportunidad ni tipo libre de riesgo, para
 *   no inventar un numero sin medir). Si NO se cruza, se valora a mercado
 *   EN la fecha de valoracion -- a diferencia de `PolicyReplaySimulator`,
 *   nunca sigue esperando mas alla de esas veinte sesiones.
 * - Comparador: mantiene hasta la MISMA fecha de valoracion (igual que
 *   siempre).
 * - Si la fecha de valoracion todavia no ha ocurrido (el historico
 *   congelado por `$asOf` no llega tan lejos, y la propia fecha de corte
 *   tampoco): episodio pendiente en AMBOS brazos, `exit_reason='pending_future'`.
 * - Si la fecha de valoracion YA deberia haber ocurrido (el historico del
 *   TICKER se acaba antes de `$asOf`, tipico de un deslistado/suspension)
 *   pero el dato no llega: `exit_reason='unresolved_gap'` -- distinto de
 *   `pending_future`, para no confundir "todavia no ha pasado" con "deberia
 *   haber datos y no los hay" (Astra: "no borrarlo silenciosamente").
 *   Ambos casos se marcan `pending=true` (excluidos de la metrica
 *   primaria, mismo criterio que `PolicyReplaySimulator`).
 *
 * Reutiliza `Services\PolicyReplayStatistics` sin cambios: al estar
 * SIEMPRE acotada a 20 sesiones (~28 dias naturales), la ventana de
 * exposicion de cada episodio es practicamente constante -- el bootstrap
 * de bloques moviles (diseñado para duraciones variables) sigue siendo
 * valido aqui, solo que con una anchura de bloque mucho mas pequeña y
 * estable que en `PolicyReplaySimulator`.
 */
final class PolicyReplayEpisodeSimulator
{
    use StopLossExitCalculator;

    /**
     * "Veinte sesiones fijas", literal de Astra -- el mismo horizonte
     * estandar que `PolicyReplaySimulator::BASELINE_HORIZON_DAYS`, no un
     * numero nuevo elegido para esta medicion.
     */
    private const VALUATION_HORIZON_DAYS = 20;

    public function __construct(
        private readonly BacktestingConfig $backtestingConfig = new BacktestingConfig()
    ) {
    }

    protected function getCostRate(): float
    {
        return $this->backtestingConfig->getCostRate();
    }

    /**
     * @param list<array{date: string, index: int, recommendation: string, stop_loss: ?float, fundamental_change: ?\StockAnalyzer\DTO\FundamentalChangeAssessment, entry_price: ?float, eligible: bool}> $timeline ver BacktestingService::replayTimeline()
     * @param list<HistoricalQuote> $history ver BacktestingService::historyFor(), MISMO $asOf que generó $timeline
     * @param DateTimeImmutable $asOf MISMO corte que se uso para congelar $timeline/$history -- necesario para distinguir "todavia no ha pasado" de "deberia haber datos y faltan" en los episodios sin resolver.
     * @return array{ticker: string, trades: list<array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: float, baseline_return: ?float, baseline_exit_date: ?string, baseline_pending: bool, revisar_tesis_events: int}>, entries_total: int, entries_closed: int, entries_pending: int, candidates_excluded_by_membership: int}
     */
    public function replay(string $ticker, array $timeline, array $history, DateTimeImmutable $asOf): array
    {
        $historyCount = count($history);
        // Buffer de una semana natural: `$asOf` puede caer en fin de
        // semana/festivo mientras la ultima vela real del ticker es de
        // unos dias antes, sin que eso signifique que dejo de cotizar --
        // solo se trata como "datos que faltan" (deslistado/suspension)
        // si el hueco es claramente mayor que eso.
        $lastAvailableDate = $historyCount > 0 ? $history[$historyCount - 1]->getDate() : null;
        $tickerDataEndsBeforeAsOf = $lastAvailableDate !== null && $lastAvailableDate < $asOf->modify('-7 days');

        $episodes = [];
        $excludedByMembership = 0;

        foreach ($timeline as $point) {
            if ($point['recommendation'] !== 'BUY' || $point['stop_loss'] === null || $point['entry_price'] === null) {
                continue;
            }

            if (!$point['eligible']) {
                $excludedByMembership++;

                continue;
            }

            $entryIndex = $point['index'] + 1;

            if ($entryIndex >= $historyCount) {
                // Misma guarda que PolicyReplaySimulator: la candidata no
                // llega a tener sesion siguiente donde ejecutarse.
                continue;
            }

            $episodes[] = $this->buildEpisode(
                $history,
                $entryIndex,
                (float) $point['entry_price'],
                (float) $point['stop_loss'],
                $tickerDataEndsBeforeAsOf
            );
        }

        return [
            'ticker' => strtoupper($ticker),
            'trades' => $episodes,
            'entries_total' => count($episodes),
            'entries_closed' => count(array_filter($episodes, static fn (array $e): bool => !$e['pending'])),
            'entries_pending' => count(array_filter($episodes, static fn (array $e): bool => $e['pending'])),
            'candidates_excluded_by_membership' => $excludedByMembership,
        ];
    }

    /**
     * @param list<HistoricalQuote> $history
     * @return array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: float, baseline_return: ?float, baseline_exit_date: ?string, baseline_pending: bool, revisar_tesis_events: int}
     */
    private function buildEpisode(
        array $history,
        int $entryIndex,
        float $entryPrice,
        float $adoptedStop,
        bool $tickerDataEndsBeforeAsOf
    ): array {
        $entryDate = $history[$entryIndex]->getDate()->format('Y-m-d');
        $valuationIndex = $entryIndex + self::VALUATION_HORIZON_DAYS;

        if ($valuationIndex >= count($history)) {
            return [
                'entry_date' => $entryDate,
                'entry_index' => $entryIndex,
                'entry_price' => round($entryPrice, 4),
                'exit_date' => $entryDate,
                'exit_index' => $entryIndex,
                'exit_price' => round($entryPrice, 4),
                'exit_reason' => $tickerDataEndsBeforeAsOf ? 'unresolved_gap' : 'pending_future',
                'pending' => true,
                'holding_days' => 0,
                'managed_return' => 0.0,
                'baseline_return' => null,
                'baseline_exit_date' => null,
                'baseline_pending' => true,
                'revisar_tesis_events' => 0,
            ];
        }

        $breach = $this->walkForStopBreach($history, $entryIndex, $valuationIndex, $adoptedStop);
        $valuationClose = $history[$valuationIndex]->getClose();
        $valuationDate = $history[$valuationIndex]->getDate()->format('Y-m-d');
        $baselineReturn = $this->netReturn($entryPrice, $valuationClose);

        if ($breach !== null) {
            [$day, $exitPrice] = $breach;

            return [
                'entry_date' => $entryDate,
                'entry_index' => $entryIndex,
                'entry_price' => round($entryPrice, 4),
                'exit_date' => $history[$day]->getDate()->format('Y-m-d'),
                'exit_index' => $day,
                'exit_price' => round($exitPrice, 4),
                'exit_reason' => 'stop_loss',
                'pending' => false,
                'holding_days' => $day - $entryIndex,
                'managed_return' => $this->netReturn($entryPrice, $exitPrice),
                'baseline_return' => $baselineReturn,
                'baseline_exit_date' => $valuationDate,
                'baseline_pending' => false,
                'revisar_tesis_events' => 0,
            ];
        }

        return [
            'entry_date' => $entryDate,
            'entry_index' => $entryIndex,
            'entry_price' => round($entryPrice, 4),
            'exit_date' => $valuationDate,
            'exit_index' => $valuationIndex,
            'exit_price' => round($valuationClose, 4),
            'exit_reason' => 'valuation_close',
            'pending' => false,
            'holding_days' => $valuationIndex - $entryIndex,
            'managed_return' => $baselineReturn,
            'baseline_return' => $baselineReturn,
            'baseline_exit_date' => $valuationDate,
            'baseline_pending' => false,
            'revisar_tesis_events' => 0,
        ];
    }

}
