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

    /**
     * Velas intradia (ver versions.md v2.9), para temporalidades inferiores
     * a un dia. $interval acepta los valores que soporta Yahoo Finance
     * ("1m", "5m", "15m", "1h"...); cada implementacion elige el rango que
     * pide al proveedor para ese intervalo.
     *
     * @return list<\StockAnalyzer\Models\HistoricalQuote>
     */
    public function getIntradayQuotes(string $ticker, string $interval): array;
}
