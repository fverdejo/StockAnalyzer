<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\DTO\SuggestedPosition;
use StockAnalyzer\Models\Portfolio;

/**
 * Cantidad de acciones sugerida por posicion abierta (position sizing, ver
 * versions.md v2.50/v2.65/v2.66) a partir del riesgo maximo por operacion
 * y del peso maximo por posicion configurados.
 *
 * Mismo reparto de responsabilidades que PortfolioConcentrationCalculator
 * frente a DTO\PortfolioConcentration: aqui viven el "cuando" y el
 * conocimiento de divisas, y en DTO\RiskLevels la formula pura.
 *
 * Divisas (v2.66): el presupuesto de riesgo y el peso maximo son
 * propiedades de la CARTERA, no del instrumento, asi que se miden en euros
 * (Portfolio::getMarketValueEur()); el precio, el stop-loss y el ATR del
 * que sale son propiedades del INSTRUMENTO y se quedan en su divisa
 * nativa, sin convertir (el stop es un nivel de precio: la orden de un
 * valor en dolares se coloca en dolares). La conversion se hace una sola
 * vez en la frontera, llevando el presupuesto en euros a la divisa del
 * ticker; de ahi en adelante toda la aritmetica de precios es nativa.
 *
 * Fuera de alcance a proposito: el efecto de segundo orden del movimiento
 * del propio tipo de cambio entre hoy y el momento en que salte el stop.
 * Se mide todo con el cambio de HOY, mismo criterio que usa v2.61 para los
 * pesos de concentracion: vale mas que las dos pantallas sean coherentes
 * entre si que afinar ese segundo orden en una sola de ellas.
 */
class SuggestedPositionCalculator
{
    public function __construct(private readonly RiskLevelsConfig $config)
    {
    }

    /**
     * Reutiliza el valor de cartera y el precio actual ya calculados en
     * $portfolio, sin ninguna llamada nueva a mercado, junto al stop-loss
     * ya calculado en $riskLevels para ese mismo ticker.
     *
     * Devuelve un DTO\SuggestedPosition y no un float suelto porque la
     * interfaz necesita saber cual de los dos topes mando para poder
     * explicarlo (v2.65).
     *
     * @param array<string,?RiskLevels> $riskLevels ticker => stop-loss/objetivo sugeridos
     * @return array<string,?SuggestedPosition> ticker => posicion sugerida (null si no se puede calcular)
     */
    public function compute(Portfolio $portfolio, array $riskLevels): array
    {
        // Denominador: todo o nada. Sin valor de cartera en euros no hay
        // presupuesto que repartir, y ese presupuesto es el de TODAS las
        // posiciones: si faltase una por convertir, el total seria otro
        // numero mas pequeño y las demas sugerencias quedarian
        // infradimensionadas en silencio. No es una regresion respecto a
        // v2.65: ya se devolvia [] cuando faltaba el precio de una sola
        // posicion.
        $portfolioValueEur = $portfolio->getMarketValueEur();

        if ($portfolioValueEur === null) {
            return [];
        }

        $riskPercent = $this->config->getPositionRiskPercent();
        $maxPositionPercent = $this->config->getMaxPositionPercent();
        $positions = [];

        foreach ($portfolio->getHoldings() as $holding) {
            $ticker = $holding->getTicker();
            $levels = $riskLevels[$ticker] ?? null;
            $price = $portfolio->getCurrentPriceFor($ticker);
            // Numerador: por ticker. Modo de fallo independiente del
            // anterior y tratado aparte a proposito: si el total en euros
            // existe pero la divisa de ESTE ticker es desconocida, solo
            // esta fila se queda sin sugerencia (el badge ya sabe pintar
            // una sugerencia ausente) y las demas conservan la suya.
            $rate = $portfolio->getRateToEurFor($ticker);

            if ($levels === null || $price === null || $rate === null || $rate <= 0) {
                $positions[$ticker] = null;

                continue;
            }

            $portfolioValueInTickerCurrency = $portfolioValueEur / $rate;
            $quantity = $levels->suggestedQuantity(
                $portfolioValueInTickerCurrency,
                $riskPercent,
                $price,
                $maxPositionPercent
            );

            $positions[$ticker] = $quantity === null
                ? null
                : new SuggestedPosition(
                    $quantity,
                    $levels->isLimitedByMaxPositionWeight(
                        $portfolioValueInTickerCurrency,
                        $riskPercent,
                        $price,
                        $maxPositionPercent
                    ),
                    $maxPositionPercent
                );
        }

        return $positions;
    }
}
