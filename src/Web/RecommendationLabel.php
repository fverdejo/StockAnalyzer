<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

/**
 * Traducción al español de las recomendaciones que calcula `Score`.
 *
 * `Score::recommendationFor()` devuelve siempre `'BUY'`/`'HOLD'`/`'SELL'`/
 * `'STRONG SELL'` en inglés (2026-09-04: no existe tramo `'STRONG BUY'`,
 * ver el docblock de ese método). Esos valores en inglés son la clave que
 * usa el resto del sistema para comparar, filtrar, guardar en
 * `score_history` y servir en el JSON de la API: traducirlos ahí obligaría
 * a traducir también de vuelta para comparar, igual que ya explica
 * `SectorLabel`. La traducción se hace SOLO al pintar, con esta clase.
 */
class RecommendationLabel
{
    /**
     * @var array<string,string>
     */
    private const LABELS = [
        'BUY' => 'Comprar',
        'HOLD' => 'Mantener',
        'SELL' => 'Vender',
        'STRONG SELL' => 'Venta fuerte',
    ];

    /**
     * Un valor que no esté en la tabla se devuelve tal cual: es preferible
     * enseñar el texto en inglés a esconder la recomendación o inventarle
     * una traducción, mismo criterio que `SectorLabel::translate()`.
     */
    public static function translate(string $recommendation): string
    {
        return self::LABELS[$recommendation] ?? $recommendation;
    }
}
