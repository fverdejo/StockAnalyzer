<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * Cantidad de acciones sugerida para una posicion (position sizing, ver
 * DTO\RiskLevels::suggestedQuantity()) junto al dato de si el que mando
 * fue el riesgo maximo por operacion o el peso maximo por posicion.
 *
 * Existe porque la cantidad sola no se puede explicar: cuando el tope de
 * peso es el que acota, la cantidad mostrada ya no cuadra con el
 * "riesgo por operacion" configurado y el usuario no sabe por que (ver
 * versions.md v2.65). Es un DTO de transporte entre Services\Application
 * y Web\RiskLevelsBadge, con el mismo criterio que el resto de DTO: no
 * calcula nada ni decide como se dice, solo lleva el dato.
 */
class SuggestedPosition
{
    public function __construct(
        private readonly float $quantity,
        private readonly bool $limitedByMaxWeight,
        private readonly float $maxPositionPercent
    ) {
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    /**
     * true si la cantidad viene acotada por el peso maximo por posicion y
     * no por el riesgo maximo por operacion (es decir, el riesgo por
     * operacion habria permitido comprar mas).
     */
    public function isLimitedByMaxWeight(): bool
    {
        return $this->limitedByMaxWeight;
    }

    public function getMaxPositionPercent(): float
    {
        return $this->maxPositionPercent;
    }
}
