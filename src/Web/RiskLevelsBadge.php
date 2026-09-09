<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\DTO\RiskLevels;

/**
 * Version compacta del stop-loss/objetivo sugerido, para usar dentro de
 * una celda de tabla densa (Watchlist, Cartera) en vez del `value-box`
 * grande de StockDetailPage (ver versions.md v2.19 para el calculo
 * basado en ATR14, idea original anotada como "Stop/objetivo compactos
 * en Watchlist y Cartera"). Mismo patron reutilizable que WatchlistStar.
 */
class RiskLevelsBadge
{
    public static function render(?RiskLevels $riskLevels, string $currency): string
    {
        if ($riskLevels === null) {
            return '<span class="muted">-</span>';
        }

        return sprintf(
            '<span class="risk-badge-compact"><span class="risk-badge-stop" title="%s">SL %s</span><span class="risk-badge-target">Obj %s</span></span>',
            Layout::escape(self::GAP_RISK_NOTE),
            Layout::escape(Layout::formatMoney($riskLevels->getStopLoss(), $currency)),
            Layout::escape(Layout::formatMoney($riskLevels->getTarget(), $currency))
        );
    }

    /**
     * Texto fijo, sin variacion por sector/ticker: medido con
     * `BacktestingService::simulateManagedExit()` sobre 10 años y 6
     * sectores (`versions.md`, 2026-08-23), el riesgo de hueco de apertura
     * es transversal, no concentrado en un sector concreto — no hay base
     * para segmentar el aviso.
     *
     * **Cifra pendiente de remedir (seguimiento de Astra, `2026-09-09`):**
     * la medicion original del `2026-08-23` es ANTERIOR al P0 de la
     * auditoria del `2026-09-08` (`versions.md`, segunda entrada de esa
     * fecha) que corrigio `simulateManagedExit()` para mirar la vela de
     * ENTRADA (antes ignorada) al resolver un hueco de apertura -- ese
     * cambio afecta directamente a este mismo mecanismo, asi que el
     * 15,77%/22% de aqui podria no coincidir con lo que mediria el motor
     * de hoy. No se sustituye por una cifra nueva sin remedir de verdad
     * (Astra: "no inventar un porcentaje nuevo") -- pendiente de repetir
     * la medicion de `gestor-riesgo` con el mecanismo actual.
     */
    private const GAP_RISK_NOTE = 'El stop-loss es orientativo, no una orden real: en el histórico de 10 años medido en la app, el 15,77% de las salidas por este tipo de stop abrieron el mercado ya por debajo del nivel calculado (hueco de apertura), con una pérdida real un 22% peor que la teórica en esos casos.';
}
