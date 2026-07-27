<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateInterval;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Repository\MarketDataCacheRepository;

class CachedMarketDataProvider implements MarketDataProviderInterface
{
    public function __construct(
        private readonly MarketDataProviderInterface $inner,
        private readonly MarketDataCacheRepository $cache,
        private readonly DateInterval $stockTtl = new DateInterval('PT15M'),
        private readonly DateInterval $historyTtl = new DateInterval('P1D')
    ) {
    }

    public function getStock(string $ticker): Stock
    {
        $ticker = strtoupper(trim($ticker));
        $cached = null;

        try {
            $cached = $this->cache->findStock($ticker, $this->stockTtl);
        } catch (\Throwable) {
            $cached = null;
        }

        if ($cached instanceof Stock) {
            return $cached;
        }

        $stock = $this->inner->getStock($ticker);

        try {
            $this->cache->saveStock($ticker, $stock);
        } catch (\Throwable) {
        }

        return $stock;
    }

    public function getHistoricalQuotes(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));
        $cached = null;

        try {
            $cached = $this->cache->findHistory($ticker, $this->historyTtl);
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $quotes = $this->inner->getHistoricalQuotes($ticker);

        try {
            $this->cache->saveHistory($ticker, $quotes);
        } catch (\Throwable) {
        }

        return $quotes;
    }
}
