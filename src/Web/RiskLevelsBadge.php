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
        $why = $suggestedPosition->isLimitedByMaxWeight()
            ? sprintf(
                'Referencia orientativa, no una cantidad exacta: se mueve con el precio y con el valor del resto de la cartera. Limitado al %s maximo por posicion (el riesgo por operacion permitiria comprar mas).',
                self::formatPercent($suggestedPosition->getMaxPositionPercent())
            )
            : 'Referencia orientativa, no una cantidad exacta: se mueve con el precio, con el stop-loss y con el valor del resto de la cartera.';

        // El tope se explica al pasar por encima, no en el propio texto: dentro
        // de una tabla densa el "(max. 20%)" se leia como si formase parte de
        // la cantidad sugerida y hacia la celda mas larga sin aportar nada que
        // no diga ya el tooltip.
        return sprintf(
            '<span class="risk-badge-quantity" title="%s">Sugerido ~%s acc.</span>',
            Layout::escape($why),
            $quantity
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
     * Dos decimales, a diferencia de los 6 de PortfolioPage::number() (ver
     * v2.6) que se usan para las acciones que de verdad se tienen.
     *
     * La diferencia es deliberada (v2.84): una cantidad poseida es un dato
     * exacto, pero una cantidad SUGERIDA es una referencia calculada a partir
     * del precio actual, del stop-loss y del valor del resto de la cartera,
     * y las tres cosas se mueven con el mercado. Mostrarla con 6 decimales
     * fingia una precision que no existe e invitaba a perseguir un numero que
     * cambia en la siguiente recarga; el "~" del badge dice lo mismo.
     */
    private static function formatQuantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
