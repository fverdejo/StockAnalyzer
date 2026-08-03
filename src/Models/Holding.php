<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

class Holding
{
    /**
     * @param ?float $investedAmountEur coste base en euros con el tipo de cambio historico de cada compra (null
     *     si la divisa nativa ya es EUR, es desconocida, o algun tipo de cambio historico no se pudo obtener;
     *     ver versions.md rentabilidad en EUR con efecto de cambio de divisa)
     * @param ?float $marketValueEur valor de mercado actual en euros con el tipo de cambio de HOY (mismo criterio
     *     de nulabilidad que $investedAmountEur)
     */
    public function __construct(
        private readonly string $ticker,
        private readonly float $quantity,
        private readonly float $averagePrice,
        private readonly ?float $currentPrice,
        private readonly ?string $marketError = null,
        private readonly ?float $investedAmountEur = null,
        private readonly ?float $marketValueEur = null
    ) {
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getAveragePrice(): float
    {
        return $this->averagePrice;
    }

    public function getCurrentPrice(): ?float
    {
        return $this->currentPrice;
    }

    public function getMarketError(): ?string
    {
        return $this->marketError;
    }

    public function getInvestedAmount(): float
    {
        return $this->quantity * $this->averagePrice;
    }

    public function getMarketValue(): ?float
    {
        return $this->currentPrice === null ? null : $this->quantity * $this->currentPrice;
    }

    public function getUnrealizedProfit(): ?float
    {
        return $this->currentPrice === null
            ? null
            : $this->quantity * ($this->currentPrice - $this->averagePrice);
    }

    public function getUnrealizedProfitPercent(): ?float
    {
        if ($this->currentPrice === null || $this->averagePrice <= 0) {
            return null;
        }

        return (($this->currentPrice / $this->averagePrice) - 1) * 100;
    }

    public function getInvestedAmountEur(): ?float
    {
        return $this->investedAmountEur;
    }

    public function getMarketValueEur(): ?float
    {
        return $this->marketValueEur;
    }

    /**
     * Rentabilidad latente en euros incluyendo el efecto del tipo de
     * cambio desde cada compra, a diferencia de getUnrealizedProfit()
     * (siempre en divisa nativa, sin tocar). Null si falta cualquiera de
     * las dos partes (ver constructor).
     */
    public function getUnrealizedProfitEur(): ?float
    {
        if ($this->investedAmountEur === null || $this->marketValueEur === null) {
            return null;
        }

        return $this->marketValueEur - $this->investedAmountEur;
    }

    public function getUnrealizedProfitEurPercent(): ?float
    {
        $profit = $this->getUnrealizedProfitEur();

        if ($profit === null || $this->investedAmountEur === null || $this->investedAmountEur <= 0) {
            return null;
        }

        return ($profit / $this->investedAmountEur) * 100;
    }
}
