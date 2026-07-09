<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use RuntimeException;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\MarketSnapshot;

class YahooFinanceProvider implements MarketDataProviderInterface
{
    public function getSnapshot(string $ticker): MarketSnapshot
    {
        throw new RuntimeException('Not implemented');
    }
}