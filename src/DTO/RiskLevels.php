<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * Stop-loss y objetivo de precio sugeridos, calculados a partir del ATR14
 * (volatilidad historica) del propio valor. Es una capa aparte que no
 * toca Score/ScoreCalculator/ScoreWeights ni recalcula ninguna
 * puntuacion (igual que Services\RecommendationExplainer no recalcula el
 * score): es una referencia de gestion de riesgo, no una nueva señal del
 * motor de puntuacion.
 *
 * Las comprobaciones de si hay datos suficientes para calcularlo
 * (ATR14 disponible, historico minimo, ATR no despreciable frente al
 * precio) viven en Services\RiskLevelsCalculator, no aqui: compute() es
 * una formula pura, sin logica de "cuando aplicarla".
 */
class RiskLevels
{
    private function __construct(
        private readonly float $stopLoss,
        private readonly float $target
    ) {
    }

    /**
     * stopLoss = precio - multiplicador * ATR14
     * objetivo = precio + ratioRiesgoBeneficio * multiplicador * ATR14
     */
    public static function compute(float $price, float $atr14, float $multiplier, float $rewardRatio): self
    {
        $risk = $multiplier * $atr14;

        return new self($price - $risk, $price + ($rewardRatio * $risk));
    }

    public function getStopLoss(): float
    {
        return $this->stopLoss;
    }

    public function getTarget(): float
    {
        return $this->target;
    }

    /**
     * Cantidad de acciones sugerida para no arriesgar mas de $riskPercent%
     * del valor de la cartera si el precio cae hasta el stop-loss ya
     * calculado (position sizing). Formula pura, mismo criterio que
     * compute(): ninguna comprobacion de "cuando tiene sentido mostrarla"
     * (eso, igual que con compute(), es responsabilidad de quien la llama,
     * no de este DTO).
     *
     * cantidad = (portfolioValue * riskPercent/100) / (price - stopLoss)
     *
     * Acotada por lo maximo que $portfolioValue permite comprar a $price:
     * esta app es un simulador sin saldo de efectivo real, asi que
     * "importe disponible" se interpreta como el valor total ya calculado
     * de la cartera (Portfolio::getMarketValue()), no una caja aparte que
     * no existe en el modelo de datos.
     *
     * Null si cualquier input no tiene sentido (portfolioValue, riskPercent
     * o price <= 0) o si el stop-loss esta al mismo nivel o por encima del
     * precio (riesgo por accion <= 0, division por cero o resultado sin
     * sentido): resiliente, mismo criterio que el resto de la app.
     */
    public function suggestedQuantity(float $portfolioValue, float $riskPercent, float $price): ?float
    {
        if ($portfolioValue <= 0 || $riskPercent <= 0 || $price <= 0) {
            return null;
        }

        $riskPerShare = $price - $this->stopLoss;

        if ($riskPerShare <= 0) {
            return null;
        }

        $quantity = ($portfolioValue * ($riskPercent / 100)) / $riskPerShare;
        $maxAffordableQuantity = $portfolioValue / $price;

        return min($quantity, $maxAffordableQuantity);
    }
}
