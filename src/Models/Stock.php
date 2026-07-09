<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

class Stock
{
    public function __construct(
        private readonly Company $company,
        private readonly Quote $quote,
        private readonly Fundamentals $fundamentals
    ) {
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getQuote(): Quote
    {
        return $this->quote;
    }

    public function getFundamentals(): Fundamentals
    {
        return $this->fundamentals;
    }
}