<?php

declare(strict_types=1);

namespace StockAnalyzer\Interfaces;

use StockAnalyzer\Models\Stock;

interface MarketDataProviderInterface
{
    public function getStock(string $ticker): Stock;

    /**
     * @return list<\StockAnalyzer\Models\HistoricalQuote>
     */
    public function getHistoricalQuotes(string $ticker): array;
}
