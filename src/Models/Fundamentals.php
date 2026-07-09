<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

class Fundamentals
{
    public function __construct(
        private readonly ?float $per,
        private readonly ?float $peg,
        private readonly ?float $roe,
        private readonly ?float $roic,
        private readonly ?float $eps,
        private readonly ?float $marketCap,
        private readonly ?float $debtToEquity,
        private readonly ?float $freeCashFlow
    ) {
    }

    public function getPer(): ?float
    {
        return $this->per;
    }

    public function getPeg(): ?float
    {
        return $this->peg;
    }

    public function getRoe(): ?float
    {
        return $this->roe;
    }

    public function getRoic(): ?float
    {
        return $this->roic;
    }

    public function getEps(): ?float
    {
        return $this->eps;
    }

    public function getMarketCap(): ?float
    {
        return $this->marketCap;
    }

    public function getDebtToEquity(): ?float
    {
        return $this->debtToEquity;
    }

    public function getFreeCashFlow(): ?float
    {
        return $this->freeCashFlow;
    }
}