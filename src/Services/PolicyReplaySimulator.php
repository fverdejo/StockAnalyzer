<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Enums\PositionDecisionAction;
use StockAnalyzer\Enums\StopLossCheckState;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\HistoricalQuote;

/**
 * Replay fiel de `PositionDecisionAdvisor::decide()` sobre historico real de
 * UN ticker (Entrega 3 de `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`).
 *
 * Por que existe: `BacktestingService::simulateManagedExit()` simula una
 * politica de stop-loss/objetivo con una heuristica ATR PROPIA, que NO es
 * la logica que usa la aplicacion en produccion. `PositionDecisionAdvisor`
 * (la politica REAL que ve un usuario en la ficha) no tiene objetivo de
 * precio ni salida forzada por horizonte -- solo vende cuando el stop-loss
 * ADOPTADO se cruza, y solo avisa (nunca fuerza venta) cuando el
 * diagnostico fundamental interanual empeora. Medir "cuanto rinde seguir
 * la ficha" con la heuristica antigua estaba midiendo una politica
 * DISTINTA a la que la aplicacion realmente recomienda.
 *
 * Decisiones de diseño consultadas en consenso el `2026-09-13`
 * (`analista-mercado`/`gestor-riesgo`/`auditor-estadistico`, ver
 * versions.md misma fecha):
 *
 * - **Sin salida por objetivo ni horizonte, tal cual `decide()` la define
 *   hoy.** Una posicion se mantiene potencialmente meses o años si el
 *   precio nunca cruza el stop -- confirmado por los tres agentes que esto
 *   es fiel a la politica real, no un defecto a corregir aqui. El gestor
 *   de riesgo señala que esto expone un hallazgo GENUINO sobre la politica
 *   de produccion (el stop nunca sube, ver `versions.md`/"Ideas
 *   adicionales sugeridas", entrada de `analista-mercado` del
 *   `2026-09-13`): no se corrige en este replay porque el objetivo es
 *   medir la politica actual, no una version mejorada de ella.
 * - **Aceptar la primera CANDIDATA estando fuera, sin filtro de
 *   persistencia.** Los tres agentes coinciden en no introducir logica de
 *   confirmacion (exigir que el BUY persista N reevaluaciones) sin
 *   evidencia de que aporte algo -- séria una variante nueva sin
 *   predeclarar, justo lo que la Entrega 4 quiere evitar.
 * - **Stop-loss vigilado a diario, fijado UNA SOLA VEZ al entrar.** Igual
 *   que `AlertService::checkStopLossBreach()` en produccion: el nivel se
 *   calcula con el precio/ATR14 del dia de la señal y no se recalcula
 *   mientras la misma posicion siga abierta.
 * - **Posiciones abiertas en la fecha de corte se marcan "pendientes", NO
 *   se les atribuye ganancia ni perdida** (ni al asesor ni al comparador):
 *   la politica nunca las cerro, asi que no tienen un desenlace que
 *   contar en la metrica principal (ver `Services\PolicyReplayStatistics`,
 *   que las excluye del contraste primario y las reporta aparte).
 *
 * `PositionDecisionAdvisor::decide()` solo lee `$recommendation` cuando NO
 * hay posicion abierta (`decideWithoutPosition()`); con posicion abierta
 * ignora ese parametro por completo (ver su codigo), asi que aqui se le
 * pasa un valor fijo ('HOLD') mientras se esta dentro -- no afecta a
 * ninguna rama real.
 */
final class PolicyReplaySimulator
{
    /**
     * Horizonte fijo del comparador (Entrega 3): "mantener cada entrada
     * durante veinte sesiones" es el horizonte estandar ya usado en todo
     * el proyecto (`BacktestingService`, `config/measured_edge.php`), no
     * un numero nuevo elegido para este experimento.
     */
    private const BASELINE_HORIZON_DAYS = 20;

    public function __construct(
        private readonly PositionDecisionAdvisor $advisor = new PositionDecisionAdvisor(),
        private readonly BacktestingConfig $backtestingConfig = new BacktestingConfig()
    ) {
    }

    /**
     * @param list<array{date: string, index: int, recommendation: string, stop_loss: ?float, fundamental_change: ?\StockAnalyzer\DTO\FundamentalChangeAssessment, entry_price: ?float}> $timeline ver BacktestingService::replayTimeline()
     * @param list<HistoricalQuote> $history ver BacktestingService::historyFor(), MISMO $asOf que generó $timeline
     * @return array{ticker: string, trades: list<array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: float, baseline_return: ?float, baseline_pending: bool, revisar_tesis_events: int}>, entries_total: int, entries_closed: int, entries_pending: int}
     */
    public function replay(string $ticker, array $timeline, array $history): array
    {
        $trades = [];
        $historyCount = count($history);

        $inPosition = false;
        $lastCheckedIndex = -1;
        $entryIndex = null;
        $entryDate = null;
        $entryPrice = null;
        $adoptedStop = null;
        $revisarTesisEvents = 0;

        foreach ($timeline as $point) {
            if ($inPosition) {
                $breach = $this->walkForStopBreach($history, $lastCheckedIndex + 1, min($point['index'], $historyCount - 1), $adoptedStop);

                if ($breach !== null) {
                    [$day, $exitPrice] = $breach;
                    $trades[] = $this->closeTrade(
                        (string) $entryDate,
                        (int) $entryIndex,
                        (float) $entryPrice,
                        $history[$day]->getDate()->format('Y-m-d'),
                        $day,
                        $exitPrice,
                        'stop_loss',
                        false,
                        $history,
                        $revisarTesisEvents
                    );

                    $inPosition = false;
                    $adoptedStop = null;
                    $lastCheckedIndex = $day;

                    continue;
                }

                $lastCheckedIndex = $point['index'];
                $decision = $this->advisor->decide(
                    'HOLD',
                    $this->placeholderHolding($ticker),
                    StopLossCheckState::DENTRO,
                    $point['fundamental_change']
                );

                if ($decision->action === PositionDecisionAction::REVISAR_TESIS) {
                    $revisarTesisEvents++;
                }

                continue;
            }

            if ($point['recommendation'] !== 'BUY' || $point['stop_loss'] === null || $point['entry_price'] === null) {
                continue;
            }

            $candidateEntryIndex = $point['index'] + 1;

            if ($candidateEntryIndex >= $historyCount) {
                // Ultima vela disponible: la candidata no llega a tener
                // sesion siguiente donde ejecutarse. No es un error, solo
                // se acaba el historico antes de poder actuar.
                continue;
            }

            $entryIndex = $candidateEntryIndex;
            $entryDate = $history[$entryIndex]->getDate()->format('Y-m-d');
            $entryPrice = $point['entry_price'];
            $adoptedStop = $point['stop_loss'];
            $inPosition = true;
            $lastCheckedIndex = $entryIndex - 1;
            $revisarTesisEvents = 0;
        }

        // El ultimo punto del timeline puede quedar $step sesiones por
        // debajo del final real de $history (replayTimeline() avanza de
        // $step en $step): sin este tramo final, una rotura de stop
        // ocurrida DESPUES del ultimo punto reevaluado nunca se detectaria
        // y la operacion se marcaria "pendiente" por error.
        if ($inPosition) {
            $breach = $this->walkForStopBreach($history, $lastCheckedIndex + 1, $historyCount - 1, $adoptedStop);

            if ($breach !== null) {
                [$day, $exitPrice] = $breach;
                $trades[] = $this->closeTrade(
                    (string) $entryDate,
                    (int) $entryIndex,
                    (float) $entryPrice,
                    $history[$day]->getDate()->format('Y-m-d'),
                    $day,
                    $exitPrice,
                    'stop_loss',
                    false,
                    $history,
                    $revisarTesisEvents
                );
            } else {
                $lastIndex = $historyCount - 1;
                $trades[] = $this->closeTrade(
                    (string) $entryDate,
                    (int) $entryIndex,
                    (float) $entryPrice,
                    $history[$lastIndex]->getDate()->format('Y-m-d'),
                    $lastIndex,
                    $history[$lastIndex]->getClose(),
                    'pending_at_cutoff',
                    true,
                    $history,
                    $revisarTesisEvents
                );
            }
        }

        return [
            'ticker' => strtoupper($ticker),
            'trades' => $trades,
            'entries_total' => count($trades),
            'entries_closed' => count(array_filter($trades, static fn (array $trade): bool => !$trade['pending'])),
            'entries_pending' => count(array_filter($trades, static fn (array $trade): bool => $trade['pending'])),
        ];
    }

    /**
     * @param list<HistoricalQuote> $history
     * @return array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: float, baseline_return: ?float, baseline_pending: bool, revisar_tesis_events: int}
     */
    private function closeTrade(
        string $entryDate,
        int $entryIndex,
        float $entryPrice,
        string $exitDate,
        int $exitIndex,
        float $exitPrice,
        string $exitReason,
        bool $pending,
        array $history,
        int $revisarTesisEvents
    ): array {
        $baselineIndex = $entryIndex + self::BASELINE_HORIZON_DAYS;
        $baselinePending = $baselineIndex >= count($history);
        $baselineReturn = $baselinePending
            ? null
            : $this->netReturn($entryPrice, $history[$baselineIndex]->getClose());

        return [
            'entry_date' => $entryDate,
            'entry_index' => $entryIndex,
            'entry_price' => round($entryPrice, 4),
            'exit_date' => $exitDate,
            'exit_index' => $exitIndex,
            'exit_price' => round($exitPrice, 4),
            'exit_reason' => $exitReason,
            'pending' => $pending,
            'holding_days' => $exitIndex - $entryIndex,
            'managed_return' => $this->netReturn($entryPrice, $exitPrice),
            'baseline_return' => $baselineReturn,
            // Distinto de `pending` (la operacion GESTIONADA sigue
            // abierta): esto marca que el comparador de 20 sesiones
            // tampoco tiene desenlace todavia porque el historico
            // congelado no llega tan lejos -- puede darse incluso en una
            // operacion gestionada YA cerrada por stop-loss, si esta
            // cerca del corte.
            'baseline_pending' => $baselinePending,
            'revisar_tesis_events' => $revisarTesisEvents,
        ];
    }

    /**
     * Retorno neto de costes de una operacion completa (misma formula que
     * `BacktestingService::netManagedReturn()`: coste en la compra Y en la
     * venta). Para una operacion `pending_at_cutoff` esto es una
     * valoracion a mercado, no una venta real -- por eso
     * `Services\PolicyReplayStatistics` excluye las pendientes del
     * contraste principal aunque este campo si se calcule para ellas
     * (diagnostico, ver el hallazgo de `gestor-riesgo` sobre la
     * distribucion de pendientes cerca del stop).
     */
    private function netReturn(float $entryPrice, float $exitPrice): float
    {
        $cost = $this->backtestingConfig->getCostRate();
        $netEntry = $entryPrice * (1 + $cost);
        $netExit = $exitPrice * (1 - $cost);

        return round((($netExit / $netEntry) - 1) * 100, 2);
    }

    /**
     * Version de un solo lado de `BacktestingService::resolveDayExit()`:
     * aqui no existe "objetivo", solo stop-loss. Mismo criterio de huecos
     * (v2.73): una apertura que ya cae en o por debajo del stop se
     * ejecuta a ESA apertura, no al nivel del stop -- cobrar el stop en un
     * hueco bajista seria la forma mas silenciosa de inflar el resultado.
     */
    private function stopBreachedOn(HistoricalQuote $day, float $stopLoss): ?float
    {
        $open = $day->getOpen();

        if ($open <= $stopLoss) {
            return $open;
        }

        if ($day->getLow() <= $stopLoss) {
            return $stopLoss;
        }

        return null;
    }

    /**
     * Recorre `$history[$fromIndex..$toIndex]` (ambos inclusive) buscando
     * el primer dia que cruza `$stopLoss`. `null` si ningun dia del rango
     * lo cruza -- un rango vacio (`$fromIndex > $toIndex`) tambien
     * devuelve `null` sin iterar nada.
     *
     * @param list<HistoricalQuote> $history
     * @return array{0: int, 1: float}|null indice del dia y precio de salida
     */
    private function walkForStopBreach(array $history, int $fromIndex, int $toIndex, float $stopLoss): ?array
    {
        for ($day = $fromIndex; $day <= $toIndex; $day++) {
            $exitPrice = $this->stopBreachedOn($history[$day], $stopLoss);

            if ($exitPrice !== null) {
                return [$day, $exitPrice];
            }
        }

        return null;
    }

    /**
     * `PositionDecisionAdvisor::decide()` solo necesita saber que HAY
     * posicion (comprueba `$position === null` y nada mas de `Holding` --
     * ver su codigo): los valores concretos de cantidad/precio medio no
     * afectan a ninguna rama, asi que un placeholder minimo es correcto,
     * no una aproximacion.
     */
    private function placeholderHolding(string $ticker): Holding
    {
        return new Holding($ticker, 1.0, 1.0, 1.0);
    }
}
