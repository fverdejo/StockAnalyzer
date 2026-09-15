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
 * - **Corregido el `2026-09-16`** (hallazgo real de Astra,
 *   `REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md`, caso 3): si la
 *   fecha de valoracion todavia no ha ocurrido (no hay datos hasta ahi),
 *   el brazo GESTIONADO puede seguir teniendo un desenlace CONOCIDO (un
 *   stop ya cruzado dentro del tramo disponible) aunque el COMPARADOR
 *   siga sin resolver -- ese retorno conocido NUNCA se descarta ni se
 *   sustituye por 0,00% (la version anterior lo hacia, borrando una
 *   perdida real). El PAR se marca `pending=true` igualmente (la
 *   comparacion necesita ambos brazos), pero `managed_return` queda con
 *   su valor real y solo `baseline_return` queda `null` (desconocido de
 *   verdad, nunca cero).
 * - **Maduracion del episodio, tambien corregida el `2026-09-16`**: la
 *   distincion entre `pending_future` (la fecha de valoracion todavia no
 *   ha podido ocurrir) y `unresolved_gap` (ya deberia haber ocurrido,
 *   pero faltan datos -- deslistado, suspension, hueco de proveedor) ya
 *   NO se infiere de "cuantos dias hace de la ultima vela disponible"
 *   (una regla de siete dias que Astra demuestra rota en los dos
 *   sentidos: marca hueco cuando en realidad la valoracion sigue siendo
 *   futura, y marca futuro cuando en realidad la valoracion ya vencio).
 *   Se compara directamente la fecha de valoracion PREVISTA -proyectada
 *   desde la entrada (20 sesiones ~ 28 dias naturales, una estimacion que
 *   solo puede quedarse CORTA si hay festivos de por medio, nunca larga)-
 *   contra `$asOf`. Ambos casos se marcan `pending=true` (excluidos de la
 *   metrica primaria, mismo criterio que `PolicyReplaySimulator`).
 *
 * **Limitacion conocida, sin corregir todavia** (caso 2 de la misma
 * auditoria): la fecha de valoracion se calcula como `entryIndex + 20`
 * sobre el HISTORICO PROPIO de este ticker, sin verificar que esas 20
 * posiciones de array representen de verdad 20 sesiones bursatiles reales
 * sin huecos -- si al ticker le falta una vela intermedia (hueco de
 * proveedor, no un festivo: los festivos estan ausentes de TODOS los
 * tickers por igual y no desplazan nada), la valoracion cae un dia mas
 * tarde de lo debido y puede fabricar una diferencia artificial. Medido
 * sobre datos reales antes de decidir la urgencia (2026-09-16, sin red,
 * 150 tickers muestreados de `point_in_time_universe.txt`): solo 1/150
 * (0,67%) tiene algun hueco interno real, y es `LEG`, el mismo ticker que
 * ya falla de forma conocida en cada medicion completa ("Yahoo response is
 * incomplete"). Corregirlo de verdad exige un calendario de referencia
 * (compartido entre tickers, como ya hace `sampleOnCalendar()`, o un
 * calendario de festivos de EEUU) -- una comprobacion local ingenua
 * (huecos de calendario dia a dia) NO basta: un festivo real tambien deja
 * un hueco de varios dias en el historico de un ticker, y confundirlo con
 * un hueco de datos marcaria como "no resuelta" a la mayoria de las
 * ventanas de 20 sesiones (casi todas contienen un festivo). Dado el
 * impacto medido tan bajo, se documenta y se aplaza en vez de improvisar
 * una correccion parcial que podria ser peor que el problema.
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
     * @param DateTimeImmutable $asOf MISMO corte que se uso para congelar $timeline/$history -- necesario para saber si la fecha de valoracion PREVISTA de un episodio ya deberia haber ocurrido (Entrega/caso 3 de `REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md`).
     * @return array{ticker: string, trades: list<array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: ?float, baseline_return: ?float, baseline_exit_date: ?string, baseline_pending: bool, revisar_tesis_events: int}>, entries_total: int, entries_closed: int, entries_pending: int, candidates_excluded_by_membership: int}
     */
    public function replay(string $ticker, array $timeline, array $history, DateTimeImmutable $asOf): array
    {
        $historyCount = count($history);
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
                $asOf
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
     * Corregido el `2026-09-16` (hallazgo real de Astra,
     * `REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md`, caso 3): la
     * version anterior, en cuanto el historico congelado no llegaba a la
     * fecha de valoracion, devolvia `managed_return=0.0` sin siquiera
     * buscar si el stop YA se habia cruzado dentro del tramo disponible --
     * borrando una perdida (o ganancia) YA CONOCIDA del brazo gestionado
     * solo porque el brazo COMPARADOR seguia sin resolver. Reproducido por
     * Astra: stop cruzado el 29/04 (retorno conocido -10%), comparador sin
     * llegar a la sesion 20 -- el simulador devolvia 0, no -10%.
     *
     * Ahora se busca el stop SIEMPRE, con independencia de si hay datos
     * suficientes para el comparador: si se encuentra, `managed_return` es
     * el valor REAL conocido (nunca 0.0 salvo que el calculo de verdad de
     * 0,00%); solo `baseline_return` queda `null` (desconocido de verdad,
     * no cero) mientras el comparador siga sin desenlace. El PAR sigue
     * `pending=true` (excluido de la metrica primaria, ya que la
     * comparacion necesita AMBOS brazos), pero el valor gestionado ya no
     * se pierde -- `PolicyReplayStatistics` lo recoge igual que antes en
     * `pending_avg_managed_return`.
     *
     * Tambien corregido: la distincion `pending_future` vs
     * `unresolved_gap` ya NO se infiere de "cuantos dias hace de la
     * ultima vela" (una regla de 7 dias que Astra demuestra rota en
     * ambos sentidos -- ver el docblock de la clase). Se compara
     * directamente la fecha de valoracion PREVISTA (proyectada desde la
     * entrada, no desde el final del historico) contra `$asOf`: si la
     * prevista es POSTERIOR a `$asOf`, todavia no ha podido ocurrir
     * (`pending_future`); si es ANTERIOR O IGUAL, ya deberia haber
     * ocurrido y faltan datos (`unresolved_gap`).
     *
     * @param list<HistoricalQuote> $history
     * @return array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: ?float, baseline_return: ?float, baseline_exit_date: ?string, baseline_pending: bool, revisar_tesis_events: int}
     */
    private function buildEpisode(
        array $history,
        int $entryIndex,
        float $entryPrice,
        float $adoptedStop,
        DateTimeImmutable $asOf
    ): array {
        $entryDate = $history[$entryIndex]->getDate();
        $entryDateIso = $entryDate->format('Y-m-d');
        $lastIndex = count($history) - 1;
        $valuationIndex = $entryIndex + self::VALUATION_HORIZON_DAYS;
        // Estimacion conservadora de la fecha de valoracion PREVISTA:
        // veinte sesiones bursatiles equivalen a cuatro semanas de cinco
        // dias si no hay ningun festivo de por medio -- 28 dias
        // naturales. Un festivo real solo puede alargar la fecha
        // verdadera, nunca acortarla, asi que esta estimacion nunca
        // adelanta una valoracion que en la realidad aun no ha llegado.
        $projectedValuationDate = $entryDate->modify('+28 days');

        // Busca el stop en TODO el tramo disponible (hasta la valoracion
        // o hasta donde llegue el dato, lo que sea antes) -- el desenlace
        // del brazo GESTIONADO puede conocerse aunque el COMPARADOR siga
        // sin resolver.
        $searchUntil = min($valuationIndex, $lastIndex);
        $breach = $this->walkForStopBreach($history, $entryIndex, $searchUntil, $adoptedStop);

        if ($valuationIndex > $lastIndex) {
            $isMature = $projectedValuationDate <= $asOf;
            $exitReason = $isMature ? 'unresolved_gap' : 'pending_future';

            if ($breach !== null) {
                [$day, $exitPrice] = $breach;

                return [
                    'entry_date' => $entryDateIso,
                    'entry_index' => $entryIndex,
                    'entry_price' => round($entryPrice, 4),
                    'exit_date' => $history[$day]->getDate()->format('Y-m-d'),
                    'exit_index' => $day,
                    'exit_price' => round($exitPrice, 4),
                    'exit_reason' => 'stop_loss',
                    'pending' => true,
                    'holding_days' => $day - $entryIndex,
                    'managed_return' => $this->netReturn($entryPrice, $exitPrice),
                    'baseline_return' => null,
                    'baseline_exit_date' => null,
                    'baseline_pending' => true,
                    'revisar_tesis_events' => 0,
                ];
            }

            return [
                'entry_date' => $entryDateIso,
                'entry_index' => $entryIndex,
                'entry_price' => round($entryPrice, 4),
                'exit_date' => $entryDateIso,
                'exit_index' => $entryIndex,
                'exit_price' => round($entryPrice, 4),
                'exit_reason' => $exitReason,
                'pending' => true,
                'holding_days' => 0,
                'managed_return' => null,
                'baseline_return' => null,
                'baseline_exit_date' => null,
                'baseline_pending' => true,
                'revisar_tesis_events' => 0,
            ];
        }

        $valuationClose = $history[$valuationIndex]->getClose();
        $valuationDate = $history[$valuationIndex]->getDate()->format('Y-m-d');
        $baselineReturn = $this->netReturn($entryPrice, $valuationClose);

        if ($breach !== null) {
            [$day, $exitPrice] = $breach;

            return [
                'entry_date' => $entryDateIso,
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
            'entry_date' => $entryDateIso,
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
