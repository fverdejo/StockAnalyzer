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
     * cantidad = (portfolioValueInTickerCurrency * riskPercent/100) / (price - stopLoss)
     *
     * Acotada por el peso maximo que se admite para una sola posicion
     * ($maxPositionPercent% del valor de la cartera): con un stop de
     * 2,5xATR el peso que pide la formula de riesgo es
     * riesgo% / (2,5 x ATR%), asi que cuanto MENOS volatil es el valor
     * mayor es la posicion sugerida, hasta pedir varias veces la cartera
     * entera repartida entre unas pocas posiciones (ver versions.md
     * v2.65). El tope anterior (lo maximo comprable, portfolioValue/price)
     * no acotaba nada util en la practica: era el 100% de la cartera.
     *
     * cantidadPorPeso = (portfolioValueInTickerCurrency * maxPositionPercent/100) / price
     *
     * $portfolioValueInTickerCurrency es el valor total de la cartera
     * expresado en la MISMA divisa que $price: quien llama es responsable
     * de convertirlo (ver Services\SuggestedPositionCalculator, que lleva
     * el valor en euros de la cartera a la divisa del ticker antes de
     * llamar aqui). Este DTO es formula pura y no sabe nada de divisas,
     * igual que no sabe "cuando" tiene sentido aplicarla; pasarle un valor
     * en euros junto a un precio en dolares da un resultado sin sentido,
     * que es exactamente el defecto que corrigio v2.66.
     *
     * Tampoco es una caja de efectivo: esta app es un simulador sin saldo
     * real, ese concepto no existe en el modelo de datos (mismo criterio
     * que en v2.50).
     *
     * Null si cualquier input no tiene sentido
     * (portfolioValueInTickerCurrency, riskPercent o price <= 0) o si el
     * stop-loss esta al mismo nivel o por encima del precio (riesgo por
     * accion <= 0, division por cero o resultado sin sentido): resiliente,
     * mismo criterio que el resto de la app.
     */
    public function suggestedQuantity(float $portfolioValueInTickerCurrency, float $riskPercent, float $price, float $maxPositionPercent = 20.0): ?float
    {
        $quantityByRisk = $this->quantityByRisk($portfolioValueInTickerCurrency, $riskPercent, $price);

        if ($quantityByRisk === null) {
            return null;
        }

        return min($quantityByRisk, self::quantityByMaxWeight($portfolioValueInTickerCurrency, $price, $maxPositionPercent));
    }

    /**
     * true si el que acota la cantidad sugerida es el peso maximo por
     * posicion y no el riesgo maximo por operacion, es decir: el riesgo
     * por operacion habria permitido comprar mas acciones de las que
     * caben en $maxPositionPercent% de la cartera.
     *
     * Se expone aparte de suggestedQuantity() (en vez de devolver un
     * objeto compuesto) para que la formula siga devolviendo un numero:
     * quien lo necesite para explicarlo en la interfaz lo compone en un
     * DTO\SuggestedPosition, ver Services\SuggestedPositionCalculator.
     * false con los mismos inputs que hacen null a suggestedQuantity(): si
     * no hay cantidad, no hay nada que explicar.
     *
     * $portfolioValueInTickerCurrency tiene el mismo significado que en
     * suggestedQuantity(): valor total de la cartera en la MISMA divisa
     * que $price.
     */
    public function isLimitedByMaxPositionWeight(float $portfolioValueInTickerCurrency, float $riskPercent, float $price, float $maxPositionPercent = 20.0): bool
    {
        $quantityByRisk = $this->quantityByRisk($portfolioValueInTickerCurrency, $riskPercent, $price);

        if ($quantityByRisk === null) {
            return false;
        }

        return $quantityByRisk > self::quantityByMaxWeight($portfolioValueInTickerCurrency, $price, $maxPositionPercent);
    }

    /**
     * Cantidad que sale de arriesgar $riskPercent% del valor de la cartera
     * hasta el stop-loss, sin acotar por peso. Null con los inputs que no
     * tienen sentido (ver suggestedQuantity()).
     */
    private function quantityByRisk(float $portfolioValueInTickerCurrency, float $riskPercent, float $price): ?float
    {
        if ($portfolioValueInTickerCurrency <= 0 || $riskPercent <= 0 || $price <= 0) {
            return null;
        }

        $riskPerShare = $price - $this->stopLoss;

        if ($riskPerShare <= 0) {
            return null;
        }

        return ($portfolioValueInTickerCurrency * ($riskPercent / 100)) / $riskPerShare;
    }

    /**
     * Cantidad que cabe en $maxPositionPercent% del valor de la cartera al
     * precio actual. Solo se llama cuando quantityByRisk() ya ha validado
     * el valor de cartera y el precio.
     */
    private static function quantityByMaxWeight(float $portfolioValueInTickerCurrency, float $price, float $maxPositionPercent): float
    {
        return ($portfolioValueInTickerCurrency * ($maxPositionPercent / 100)) / $price;
    }
}
