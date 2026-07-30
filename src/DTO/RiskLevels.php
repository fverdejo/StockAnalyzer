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
}
