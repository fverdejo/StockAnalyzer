<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use RuntimeException;
use StockAnalyzer\DTO\DividendPayment;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Stock;

/**
 * Variante de `PerTickerHistoryProvider` que ademas devuelve un `Stock`
 * DISTINTO por ticker (no uno unico compartido): hace falta para los tests
 * de `mode='momentum'` (`BacktestingServiceMomentumModeTest`), donde el
 * sector y el marketCap tienen que variar ticker a ticker para poder
 * comprobar la neutralizacion cross-sectional -- `PerTickerHistoryProvider`
 * (que reutiliza el resto de tests de `BacktestingService`) solo varia el
 * historico, no la Company/Fundamentals.
 */
final class PerTickerStockAndHistoryProvider implements MarketDataProviderInterface
{
    /**
     * @param array<string,Stock> $stocksByTicker
     * @param array<string,list<HistoricalQuote>> $historiesByTicker
     */
    public function __construct(
        private readonly array $stocksByTicker,
        private readonly array $historiesByTicker
    ) {
    }

    public function getStock(string $ticker): Stock
    {
        if (!array_key_exists($ticker, $this->stocksByTicker)) {
            throw new RuntimeException("PerTickerStockAndHistoryProvider no tiene Stock para '$ticker'.");
        }

        return $this->stocksByTicker[$ticker];
    }

    /**
     * @return list<HistoricalQuote>
     */
    public function getHistoricalQuotes(string $ticker): array
    {
        if (!array_key_exists($ticker, $this->historiesByTicker)) {
            throw new RuntimeException("PerTickerStockAndHistoryProvider no tiene historico para '$ticker'.");
        }

        return $this->historiesByTicker[$ticker];
    }

    public function getIntradayQuotes(string $ticker, string $interval): array
    {
        throw new RuntimeException('PerTickerStockAndHistoryProvider no soporta velas intradia.');
    }

    /**
     * @return list<DividendPayment>
     */
    public function getDividendHistory(string $ticker): array
    {
        return [];
    }
}
