<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use RuntimeException;
use StockAnalyzer\DTO\DividendPayment;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Stock;

/**
 * Variante de FixedHistoryProvider para los tests del agregado por universo
 * (`BacktestingService::run()`, ver versions.md v2.59): devuelve un
 * historico DISTINTO por ticker, que es justo lo que hace falta para
 * comprobar que el agregado combina varios tickers y agrupa sus muestras por
 * mes. Un ticker sin historico configurado lanza en vez de devolver datos
 * silenciosamente incorrectos.
 */
final class PerTickerHistoryProvider implements MarketDataProviderInterface
{
    /**
     * @param array<string,list<HistoricalQuote>> $historiesByTicker
     */
    public function __construct(
        private readonly Stock $stock,
        private readonly array $historiesByTicker
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
        if (!array_key_exists($ticker, $this->historiesByTicker)) {
            throw new RuntimeException("PerTickerHistoryProvider no tiene historico para '$ticker'.");
        }

        return $this->historiesByTicker[$ticker];
    }

    public function getIntradayQuotes(string $ticker, string $interval): array
    {
        throw new RuntimeException('PerTickerHistoryProvider no soporta velas intradia.');
    }

    /**
     * Array vacio, igual que FixedHistoryProvider: estos tests no ejercen el
     * componente de crecimiento de dividendo.
     *
     * @return list<DividendPayment>
     */
    public function getDividendHistory(string $ticker): array
    {
        return [];
    }
}
