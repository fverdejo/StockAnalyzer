<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\DTO\PortfolioHeat;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Models\Portfolio;

/**
 * Calcula cuanto se perderia en total si TODOS los stop-loss sugeridos de
 * la cartera saltaran a la vez, como % del valor total en euros. Reutiliza
 * exactamente los mismos datos que ya calcula
 * Application::analyzeHoldingsForAlerts() (mismo array de RiskLevels que
 * consume SuggestedPositionCalculator), sin ninguna llamada nueva a
 * mercado.
 *
 * A diferencia de PortfolioConcentrationCalculator (que devuelve null si
 * falta el dato de UNA sola posicion, porque un reparto de pesos al que le
 * falta una posicion es enganoso), aqui una posicion sin RiskLevels/precio/
 * tipo de cambio se EXCLUYE de la suma en vez de invalidar el calculo
 * entero: un calor parcial sigue siendo una cota inferior util del riesgo
 * real, no una cifra enganosa. Solo se devuelve null si no hay ninguna
 * posicion con datos suficientes.
 */
class PortfolioHeatCalculator
{
    /**
     * @param array<string,?RiskLevels> $riskLevels ticker => stop-loss/objetivo, mismo array que ya usa SuggestedPositionCalculator
     */
    public function compute(Portfolio $portfolio, array $riskLevels): ?PortfolioHeat
    {
        $holdings = $portfolio->getHoldings();

        if ($holdings === []) {
            return null;
        }

        $totalValueEur = $portfolio->getMarketValueEur();

        if ($totalValueEur === null || $totalValueEur <= 0.0) {
            return null;
        }

        $weights = [];
        $excluded = [];

        foreach ($holdings as $holding) {
            $ticker = $holding->getTicker();
            $levels = $riskLevels[$ticker] ?? null;
            $price = $portfolio->getCurrentPriceFor($ticker);
            $rate = $portfolio->getRateToEurFor($ticker);

            if ($levels === null || $price === null || $rate === null || $rate <= 0.0) {
                $excluded[] = $ticker;

                continue;
            }

            $riskEur = $holding->getQuantity() * max(0.0, $price - $levels->getStopLoss()) * $rate;
            $weights[$ticker] = ($riskEur / $totalValueEur) * 100;
        }

        if ($weights === []) {
            return null;
        }

        arsort($weights);

        return new PortfolioHeat($totalValueEur, $weights, $excluded);
    }
}
