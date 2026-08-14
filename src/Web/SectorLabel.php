<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\DTO\PortfolioConcentration;

/**
 * Traduccion al español de los sectores que devuelve el proveedor.
 *
 * Yahoo sirve `assetProfile.sector` (ver `v2.41`/`v2.47`) con la taxonomia
 * de Morningstar, que son exactamente estos once nombres y siempre en
 * ingles. La traduccion se hace SOLO al pintar: el valor en ingles es la
 * clave con la que agrupan `PortfolioConcentrationCalculator` y
 * `RankingSectorConcentrationCalculator`, y traducirlo antes obligaria a
 * traducir tambien de vuelta para comparar.
 *
 * Un sector que no este en la tabla se devuelve tal cual: es preferible
 * enseñar un nombre en ingles que Yahoo haya añadido despues, a esconder
 * la posicion o inventarle una traduccion.
 */
class SectorLabel
{
    /**
     * Los once sectores de la taxonomia, en el orden en que los enumero el
     * usuario. El orden no lo usa nadie para pintar (el reparto va siempre
     * ordenado por peso), pero deja constancia de que la lista esta
     * completa y de que no falta ninguno.
     *
     * @var array<string,string>
     */
    private const LABELS = [
        'Utilities' => 'Servicios Publicos',
        'Healthcare' => 'Salud',
        'Consumer Defensive' => 'Consumo Defensivo',
        'Technology' => 'Tecnologia',
        'Industrials' => 'Industria',
        'Basic Materials' => 'Materiales Basicos',
        'Consumer Cyclical' => 'Consumo Ciclico',
        'Financial Services' => 'Servicios Financieros',
        'Real Estate' => 'Inmobiliario',
        'Communication Services' => 'Servicios de Comunicacion',
        'Energy' => 'Energia',
    ];

    public static function translate(string $sector): string
    {
        // "Sin sector" ya viene en español desde el DTO: es una etiqueta
        // nuestra para las posiciones sin dato, no un sector del proveedor.
        if ($sector === PortfolioConcentration::UNKNOWN_SECTOR) {
            return $sector;
        }

        return self::LABELS[$sector] ?? $sector;
    }
}
