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
            '<span class="risk-badge-compact"><span class="risk-badge-stop">SL %s</span><span class="risk-badge-target">Obj %s</span></span>',
            Layout::escape(Layout::formatMoney($riskLevels->getStopLoss(), $currency)),
            Layout::escape(Layout::formatMoney($riskLevels->getTarget(), $currency))
        );
    }
}
