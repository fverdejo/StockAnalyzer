<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

class TechnicalSnapshot
{
    public function __construct(
        private readonly ?float $sma20,
        private readonly ?float $sma50,
        private readonly ?float $rsi14,
        private readonly ?float $momentum30,
        private readonly ?float $volatility20,
        private readonly int $historyCount
    ) {
    }

    public function getSma20(): ?float
    {
        return $this->sma20;
    }

    public function getSma50(): ?float
    {
        return $this->sma50;
    }

    public function getRsi14(): ?float
    {
        return $this->rsi14;
    }

    public function getMomentum30(): ?float
    {
        return $this->momentum30;
    }

    public function getVolatility20(): ?float
    {
        return $this->volatility20;
    }

    public function getHistoryCount(): int
    {
        return $this->historyCount;
    }
}
