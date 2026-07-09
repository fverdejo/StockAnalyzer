<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

class Company
{
    public function __construct(
        private readonly string $ticker,
        private readonly string $name,
        private readonly string $sector,
        private readonly string $industry,
        private readonly string $market,
        private readonly string $currency
    ) {
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSector(): string
    {
        return $this->sector;
    }

    public function getIndustry(): string
    {
        return $this->industry;
    }

    public function getMarket(): string
    {
        return $this->market;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}