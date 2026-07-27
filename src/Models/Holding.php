<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

class Holding
{
    public function __construct(
        private readonly string $ticker,
        private readonly float $quantity,
        private readonly float $averagePrice,
        private readonly ?float $currentPrice,
        private readonly ?string $marketError = null
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
}
