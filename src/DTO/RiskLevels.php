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
     * Cantidad de acciones sugerida para esta posicion (position sizing, ver
     * versions.md v2.50/v2.65/v2.66/v2.83). Formula pura, mismo criterio que
     * compute(): ninguna comprobacion de "cuando tiene sentido mostrarla"
     * (eso es responsabilidad de quien la llama, no de este DTO).
     *
     * $otherPositionsValueInTickerCurrency es el valor de las OTRAS
     * posiciones de la cartera, sin contar esta, expresado en la MISMA divisa
     * que $price (quien llama es responsable de convertirlo, ver
     * Services\SuggestedPositionCalculator; pasarle euros junto a un precio
     * en dolares da un resultado sin sentido, que es el defecto que corrigio
     * v2.66).
     *
     * Que el parametro excluya la propia posicion es lo que hace que la
     * sugerencia sea ESTABLE, y es el arreglo de v2.83. Con el valor total de
     * la cartera como base, comprar hasta la cantidad sugerida aumentaba esa
     * base y por tanto la propia sugerencia: el usuario compraba para cuadrar
     * y la app le pedia otro numero mayor, indefinidamente. Aqui se resuelve
     * el punto fijo, es decir se calcula la cantidad que cumple la condicion
     * DESPUES de comprarla, con la cartera ya crecida:
     *
     *   por peso:   cantidad*precio = m% * (otras + cantidad*precio)
     *               => cantidad = otras * m / (precio * (100 - m))
     *
     *   por riesgo: cantidad*(precio - stop) = r% * (otras + cantidad*precio)
     *               => cantidad = otras * r / (100*(precio - stop) - r*precio)
     *
     * Comprada esa cantidad, volver a calcular devuelve el mismo numero.
     *
     * La cantidad final es la menor de las dos. Sin acotar por peso, con un
     * stop de 2,5xATR el peso que pide la formula de riesgo es
     * riesgo% / (2,5 x ATR%), asi que cuanto MENOS volatil es el valor mayor
     * es la posicion sugerida, hasta pedir varias veces la cartera entera
     * repartida entre unas pocas posiciones (ver v2.65).
     *
     * No es una caja de efectivo: esta app es un simulador sin saldo real,
     * ese concepto no existe en el modelo de datos (mismo criterio que v2.50).
     *
     * Null si los inputs no tienen sentido (valor de las otras posiciones,
     * riskPercent o price <= 0, o un peso maximo fuera de (0, 100)):
     * resiliente, mismo criterio que el resto de la app. Ojo con el caso
     * limite legitimo: en una cartera de una sola posicion las "otras" valen
     * 0 y no hay respuesta que dar —ninguna cantidad distinta de cero puede
     * pesar el 20% de una cartera formada solo por ella misma—, asi que
     * tambien devuelve null y la interfaz no pinta sugerencia.
     */
    public function suggestedQuantity(float $otherPositionsValueInTickerCurrency, float $riskPercent, float $price, float $maxPositionPercent = 20.0): ?float
    {
        if (!self::areInputsUsable($otherPositionsValueInTickerCurrency, $riskPercent, $price, $maxPositionPercent)) {
            return null;
        }

        // Stop al mismo nivel o por encima del precio: los niveles de riesgo
        // no son utilizables y no hay cantidad que dar, ni acotada por peso
        // (mismo criterio que antes de v2.83; el badge no pinta sugerencia).
        if ($price - $this->stopLoss <= 0) {
            return null;
        }

        $byWeight = self::quantityByMaxWeight($otherPositionsValueInTickerCurrency, $price, $maxPositionPercent);
        $byRisk = $this->quantityByRisk($otherPositionsValueInTickerCurrency, $riskPercent, $price);

        // byRisk null aqui no es un input invalido ni un stop imposible (los
        // dos ya estan descartados), sino que el riesgo por operacion no tiene
        // solucion finita: pasa cuando la distancia al stop en porcentaje no
        // supera al riesgo permitido, y entonces manda el tope por peso.
        return $byRisk === null ? $byWeight : min($byRisk, $byWeight);
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
     * $otherPositionsValueInTickerCurrency tiene el mismo significado que en
     * suggestedQuantity(): valor de las otras posiciones en la MISMA divisa
     * que $price.
     */
    public function isLimitedByMaxPositionWeight(float $otherPositionsValueInTickerCurrency, float $riskPercent, float $price, float $maxPositionPercent = 20.0): bool
    {
        if (!self::areInputsUsable($otherPositionsValueInTickerCurrency, $riskPercent, $price, $maxPositionPercent)) {
            return false;
        }

        // Sin cantidad que dar no hay nada que explicar, mismo criterio que
        // suggestedQuantity() con un stop imposible.
        if ($price - $this->stopLoss <= 0) {
            return false;
        }

        $byRisk = $this->quantityByRisk($otherPositionsValueInTickerCurrency, $riskPercent, $price);

        // Sin solucion finita por riesgo, el peso es necesariamente quien
        // acota: no hay cantidad que el riesgo por operacion no permita.
        if ($byRisk === null) {
            return true;
        }

        return $byRisk > self::quantityByMaxWeight($otherPositionsValueInTickerCurrency, $price, $maxPositionPercent);
    }

    /**
     * Inputs con los que las dos formulas tienen sentido. Un peso maximo de
     * 100% o mas no lo tiene: la condicion "esta posicion pesa el 100% de una
     * cartera que la incluye" la cumple cualquier cantidad, y el punto fijo se
     * va a infinito (division por cero en quantityByMaxWeight()).
     */
    private static function areInputsUsable(float $otherPositionsValue, float $riskPercent, float $price, float $maxPositionPercent): bool
    {
        return $otherPositionsValue > 0
            && $riskPercent > 0
            && $price > 0
            && $maxPositionPercent > 0
            && $maxPositionPercent < 100;
    }

    /**
     * Cantidad que deja el riesgo hasta el stop en $riskPercent% de la cartera
     * resultante de comprarla (punto fijo, ver suggestedQuantity()). Null
     * cuando no existe una cantidad finita que lo cumpla: si el stop esta al
     * mismo nivel o por encima del precio (riesgo por accion <= 0), o si la
     * distancia al stop no supera en porcentaje al riesgo permitido, cada
     * accion comprada añade a la cartera mas presupuesto de riesgo del que
     * consume y la ecuacion no cierra.
     */
    private function quantityByRisk(float $otherPositionsValueInTickerCurrency, float $riskPercent, float $price): ?float
    {
        $riskPerShare = $price - $this->stopLoss;

        if ($riskPerShare <= 0) {
            return null;
        }

        $denominator = (100 * $riskPerShare) - ($riskPercent * $price);

        if ($denominator <= 0) {
            return null;
        }

        return ($otherPositionsValueInTickerCurrency * $riskPercent) / $denominator;
    }

    /**
     * Cantidad que deja esta posicion pesando exactamente
     * $maxPositionPercent% de la cartera resultante de comprarla (punto fijo,
     * ver suggestedQuantity()). Solo se llama con inputs ya validados por
     * areInputsUsable(), que garantiza el denominador distinto de cero.
     */
    private static function quantityByMaxWeight(float $otherPositionsValueInTickerCurrency, float $price, float $maxPositionPercent): float
    {
        return ($otherPositionsValueInTickerCurrency * $maxPositionPercent) / ($price * (100 - $maxPositionPercent));
    }
}
