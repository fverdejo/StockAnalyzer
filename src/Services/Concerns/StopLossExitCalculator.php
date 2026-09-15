<?php

declare(strict_types=1);

namespace StockAnalyzer\Services\Concerns;

use StockAnalyzer\Models\HistoricalQuote;

/**
 * Mecanica de vigilancia de stop-loss y coste de operar, COMPARTIDA entre
 * `Services\PolicyReplaySimulator` y `Services\PolicyReplayEpisodeSimulator`
 * (Entrega 3/caso 6 de `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`/
 * `REVISION_REPLAY_MOTOR_ASTRA_2026-09-14.md`): ambos simulan la MISMA
 * politica de salida (stop-loss adoptado, vigilado a diario contra el
 * rango completo de cada vela, mismo criterio de huecos que v2.73), solo
 * difieren en CUANDO se detiene esa vigilancia (indefinidamente hasta el
 * corte, o acotada a veinte sesiones) -- duplicar esta mecanica en las dos
 * clases arriesgaria que un futuro arreglo (como el del `2026-09-08`,
 * huecos alcistas/bajistas) se aplique en una y se olvide en la otra.
 *
 * La clase que use este trait debe exponer `getCostRate(): float` (el
 * coste por lado, ya sea via `BacktestingConfig::getCostRate()` o
 * cualquier otra fuente).
 */
trait StopLossExitCalculator
{
    abstract protected function getCostRate(): float;

    /**
     * Recorre `$history[$fromIndex..$toIndex]` (ambos inclusive) buscando
     * el primer dia que cruza `$stopLoss`. `null` si ningun dia del rango
     * lo cruza -- un rango vacio (`$fromIndex > $toIndex`) tambien
     * devuelve `null` sin iterar nada.
     *
     * Mismo criterio de huecos que `BacktestingService::resolveDayExit()`
     * (v2.73, solo el lado del stop, aqui no existe "objetivo"): una
     * apertura que ya cae en o por debajo del stop se ejecuta a ESA
     * apertura, no al nivel del stop -- cobrar el stop en un hueco bajista
     * seria la forma mas silenciosa de inflar el resultado.
     *
     * @param list<HistoricalQuote> $history
     * @return array{0: int, 1: float}|null indice del dia y precio de salida
     */
    private function walkForStopBreach(array $history, int $fromIndex, int $toIndex, float $stopLoss): ?array
    {
        for ($day = $fromIndex; $day <= $toIndex; $day++) {
            $open = $history[$day]->getOpen();

            if ($open <= $stopLoss) {
                return [$day, $open];
            }

            if ($history[$day]->getLow() <= $stopLoss) {
                return [$day, $stopLoss];
            }
        }

        return null;
    }

    /**
     * Retorno neto de costes de una operacion completa: coste en la
     * compra Y en la venta (mismo criterio que
     * `BacktestingService::netManagedReturn()`).
     */
    private function netReturn(float $entryPrice, float $exitPrice): float
    {
        $cost = $this->getCostRate();
        $netEntry = $entryPrice * (1 + $cost);
        $netExit = $exitPrice * (1 - $cost);

        return round((($netExit / $netEntry) - 1) * 100, 2);
    }
}
