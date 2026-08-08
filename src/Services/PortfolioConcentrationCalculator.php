<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\DTO\PortfolioConcentration;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;

/**
 * Calcula el peso relativo de cada posicion, sector y divisa de una
 * cartera. Decide como convertir cada posicion a euros y cuando no hay
 * datos suficientes para pesar nada; las metricas derivadas (HHI,
 * posiciones efectivas, umbrales de aviso) viven en
 * DTO\PortfolioConcentration, igual que RiskLevelsCalculator decide
 * "cuando" y DTO\RiskLevels calcula "cuanto".
 *
 * Correctitud: Holding::getMarketValue() y Portfolio::getMarketValue()
 * estan en divisa nativa y suman EUR con USD sin convertir (deliberado,
 * ver versions.md v2.48: las metricas nativas no se tocan). Un peso
 * relativo calculado sobre esa suma mixta seria simplemente incorrecto,
 * asi que aqui todo se lleva antes a euros con el tipo de cambio de HOY
 * que PortfolioService ya obtuvo por divisa.
 */
class PortfolioConcentrationCalculator
{
    /**
     * Null (y la pagina no pinta el bloque) si la cartera no tiene
     * posiciones abiertas, si su valor total es cero, o si alguna posicion
     * no se puede expresar en euros (precio actual no disponible o tipo de
     * cambio no disponible): un reparto de pesos al que le falta una
     * posicion no es "casi correcto", es enganoso. Mismo criterio de
     * resiliencia que Portfolio::getMarketValue(), que ya devuelve null si
     * falta el precio de una sola posicion.
     *
     * @param array<string,string> $sectors ticker => sector (ver versions.md v2.47)
     */
    public function compute(Portfolio $portfolio, array $sectors = []): ?PortfolioConcentration
    {
        $holdings = $portfolio->getHoldings();

        if ($holdings === []) {
            return null;
        }

        $byPosition = [];
        $bySector = [];
        $byCurrency = [];
        $total = 0.0;

        foreach ($holdings as $holding) {
            $valueEur = $this->marketValueEur($portfolio, $holding);

            if ($valueEur === null) {
                return null;
            }

            $ticker = $holding->getTicker();
            $sector = trim($sectors[$ticker] ?? '');
            $sector = $sector === '' ? PortfolioConcentration::UNKNOWN_SECTOR : $sector;
            $currency = $this->currencyOf($portfolio, $holding);

            $byPosition[$ticker] = ($byPosition[$ticker] ?? 0.0) + $valueEur;
            $bySector[$sector] = ($bySector[$sector] ?? 0.0) + $valueEur;
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0) + $valueEur;
            $total += $valueEur;
        }

        if ($total <= 0.0) {
            return null;
        }

        return new PortfolioConcentration(
            $total,
            $this->toWeights($byPosition, $total),
            $this->toWeights($bySector, $total),
            $this->toWeights($byCurrency, $total)
        );
    }

    /**
     * Valor de mercado de una posicion en euros con el tipo de cambio de
     * hoy. La regla ("EUR tal cual porque su valor nativo ya esta en
     * euros, resto por el valor en euros que PortfolioService ya calculo")
     * vivia aqui desde v2.61 y se movio a Models\Portfolio en v2.66, sin
     * cambiar su semantica, para que la comparta el calculo de la cantidad
     * sugerida: son la misma pregunta ("cuanto vale esto en euros") hecha
     * desde dos sitios, y tenerla duplicada fue justo lo que dejo el
     * presupuesto de riesgo en divisa mixta hasta v2.66.
     */
    private function marketValueEur(Portfolio $portfolio, Holding $holding): ?float
    {
        return $portfolio->getMarketValueEurFor($holding->getTicker());
    }

    private function currencyOf(Portfolio $portfolio, Holding $holding): string
    {
        return strtoupper(trim($portfolio->getCurrencyFor($holding->getTicker())));
    }

    /**
     * Importes en euros convertidos a porcentaje del total, en orden
     * descendente (lo que concentra mas, primero). Sin redondear: el
     * redondeo es cosa de la presentacion, y asi los pesos siguen sumando
     * exactamente 100.
     *
     * @param array<string,float> $amounts
     * @return array<string,float>
     */
    private function toWeights(array $amounts, float $total): array
    {
        $weights = array_map(static fn (float $amount): float => ($amount / $total) * 100, $amounts);
        arsort($weights);

        return $weights;
    }
}
