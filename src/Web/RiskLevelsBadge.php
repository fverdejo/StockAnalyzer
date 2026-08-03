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
    /**
     * $suggestedQuantity es opcional (position sizing, ver versions.md):
     * solo tiene sentido en Cartera, donde se conoce el valor real de la
     * cartera (DTO\RiskLevels::suggestedQuantity()); Watchlist sigue
     * llamando a render() sin ese tercer argumento.
     */
    public static function render(?RiskLevels $riskLevels, string $currency, ?float $suggestedQuantity = null): string
    {
        if ($riskLevels === null) {
            return '<span class="muted">-</span>';
        }

        return sprintf(
            '<span class="risk-badge-compact"><span class="risk-badge-stop">SL %s</span><span class="risk-badge-target">Obj %s</span>%s</span>',
            Layout::escape(Layout::formatMoney($riskLevels->getStopLoss(), $currency)),
            Layout::escape(Layout::formatMoney($riskLevels->getTarget(), $currency)),
            self::renderSuggestedQuantity($suggestedQuantity)
        );
    }

    private static function renderSuggestedQuantity(?float $suggestedQuantity): string
    {
        if ($suggestedQuantity === null) {
            return '';
        }

        return sprintf(
            '<span class="risk-badge-quantity">Sugerido %s acc.</span>',
            Layout::escape(self::formatQuantity($suggestedQuantity))
        );
    }

    /**
     * Mismo formato que PortfolioPage::number() (acciones fraccionarias,
     * hasta 6 decimales sin ceros sobrantes, ver versions.md v2.6): una
     * cantidad sugerida se muestra con el mismo criterio que cualquier
     * otra cantidad de acciones ya visible en la cartera.
     */
    private static function formatQuantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, ',', '.'), '0'), ',');
    }
}
