<?php

declare(strict_types=1);

namespace StockAnalyzer\Interfaces;

use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\MarketSnapshot;

interface MarketDataProviderInterface
{
    public function getSnapshot(string $ticker): MarketSnapshot;
}