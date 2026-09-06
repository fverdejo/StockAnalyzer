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
     */
    private const GAP_RISK_NOTE = 'El stop-loss es orientativo, no una orden real: en el histórico de 10 años medido en la app, el 15,77% de las salidas por este tipo de stop abrieron el mercado ya por debajo del nivel calculado (hueco de apertura), con una pérdida real un 22% peor que la teórica en esos casos.';
}
