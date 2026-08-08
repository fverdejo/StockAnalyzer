<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\DTO\SuggestedPosition;

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
     * $suggestedPosition es opcional (position sizing, ver versions.md):
     * solo tiene sentido en Cartera, donde se conoce el valor real de la
     * cartera (DTO\RiskLevels::suggestedQuantity()); Watchlist sigue
     * llamando a render() sin ese tercer argumento.
     */
    public static function render(?RiskLevels $riskLevels, string $currency, ?SuggestedPosition $suggestedPosition = null): string
    {
        if ($riskLevels === null) {
            return '<span class="muted">-</span>';
        }

        return sprintf(
            '<span class="risk-badge-compact"><span class="risk-badge-stop">SL %s</span><span class="risk-badge-target">Obj %s</span>%s</span>',
            Layout::escape(Layout::formatMoney($riskLevels->getStopLoss(), $currency)),
            Layout::escape(Layout::formatMoney($riskLevels->getTarget(), $currency)),
            self::renderSuggestedQuantity($suggestedPosition)
        );
    }

    /**
     * Cuando el que acota la cantidad es el peso maximo por posicion y no
     * el riesgo por operacion (ver versions.md v2.65), se dice en el
     * propio badge: si no, la cantidad mostrada no cuadra con el riesgo
     * por operacion configurado y no hay forma de saber por que.
     */
    private static function renderSuggestedQuantity(?SuggestedPosition $suggestedPosition): string
    {
        if ($suggestedPosition === null) {
            return '';
        }

        $quantity = Layout::escape(self::formatQuantity($suggestedPosition->getQuantity()));

        if (!$suggestedPosition->isLimitedByMaxWeight()) {
            return sprintf('<span class="risk-badge-quantity">Sugerido %s acc.</span>', $quantity);
        }

        $maxPercent = self::formatPercent($suggestedPosition->getMaxPositionPercent());

        return sprintf(
            '<span class="risk-badge-quantity" title="%s">Sugerido %s acc. (max. %s)</span>',
            Layout::escape(sprintf('Limitado al %s maximo por posicion: el riesgo por operacion permitiria comprar mas.', $maxPercent)),
            $quantity,
            Layout::escape($maxPercent)
        );
    }

    /**
     * Mismo criterio que formatQuantity(): sin ceros decimales sobrantes,
     * para que un 20,0% se lea "20%".
     */
    private static function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . '%';
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
