<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use RuntimeException;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Stock;

/**
 * MarketDataProviderInterface fake para BacktestingServiceTest: siempre
 * devuelve el mismo Stock y el mismo historico sintetico, sea cual sea el
 * ticker pedido (los tests solo usan un ticker cada vez). getIntradayQuotes()
 * no lo usa BacktestingService, asi que lanza en vez de devolver datos
 * silenciosamente incorrectos si algun dia se llegase a llamar.
 */
final class FixedHistoryProvider implements MarketDataProviderInterface
{
    /**
     * @param list<HistoricalQuote> $history
     */
    public function __construct(
        private readonly Stock $stock,
        private readonly array $history
    ) {
    }

    public function getStock(string $ticker): Stock
    {
        return $this->stock;
    }

    /**
     * @return list<HistoricalQuote>
     */
    public function getHistoricalQuotes(string $ticker): array
    {
        return $this->history;
    }

    public function getIntradayQuotes(string $ticker, string $interval): array
    {
        throw new RuntimeException('FixedHistoryProvider no soporta velas intradia.');
    }
}
