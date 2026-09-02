<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Models\Holding;

/**
 * Aviso en la ficha de detalle que separa "no abrir posicion" (quien no
 * tiene el valor) de "mantener/reducir/vigilar" (quien ya lo tiene), cuando
 * la recomendacion es SELL o STRONG SELL (ver `roadmap.md`, "Cuarto
 * bloque: hacer util la recomendacion sin exagerar lo medido"). Hasta el
 * 2026-09-02 el badge mostraba literalmente el mismo texto en ambos casos:
 * un SELL tecnico no ordena por si solo liquidar una posicion sin
 * considerar precio de entrada, horizonte, impuestos, concentracion y
 * riesgo, algo que solo puede valorar quien ya tiene la posicion.
 *
 * No cambia el color/clase del badge (`Layout::recommendationClass()`, ya
 * distingue SELL de STRONG SELL desde `v2.104`) ni la puntuacion: solo el
 * texto que lo acompaña, y solo donde ya se sabe con certeza si hay una
 * posicion abierta (`PortfolioService::getPositionFor()`). BUY y HOLD no
 * llevan aviso: la ambigüedad "entrar o no entrar" que motiva este texto
 * solo existe para una señal de venta.
 */
class PositionRecommendationNotice
{
    public static function renderInline(string $recommendation, ?Holding $position): string
    {
        if (!in_array($recommendation, ['SELL', 'STRONG SELL'], true)) {
            return '';
        }

        $message = $position !== null
            ? 'Tienes una posicion abierta en este valor: esta recomendacion no es una orden automatica de liquidarla. Valora reducirla o vigilarla de cerca segun tu precio de entrada, horizonte, impuestos y el peso que tiene en tu cartera antes de decidir.'
            : 'No se recomienda abrir posicion en este valor.';

        return sprintf('<p class="muted panel-note">%s</p>', Layout::escape($message));
    }
}
