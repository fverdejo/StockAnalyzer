<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use Throwable;

/**
 * Tipo de cambio de una divisa a euros, para mostrar (no para calcular
 * rentabilidad, ver versions.md v2.25) los precios de operaciones en
 * tickers que Yahoo devuelve en una divisa distinta del euro.
 *
 * Truco reutilizado sin proveedor nuevo: Yahoo Finance sirve los pares de
 * divisas por el mismo endpoint `v8/finance/chart` que ya usa
 * `YahooFinanceProvider::getStock()` (ej. el ticker `USDEUR=X` devuelve
 * `regularMarketPrice` como euros por cada dolar). Como ya es un ticker
 * mas para ese proveedor, pasa tambien por `CachedMarketDataProvider`
 * (TTL 15 min) sin tocar la capa de cache ni añadir un proveedor HTTP
 * nuevo.
 */
class ExchangeRateService
{
    public function __construct(
        private readonly MarketDataProviderInterface $marketDataProvider
    ) {
    }

    public function getRateToEur(string $currency): ?float
    {
        $currency = strtoupper(trim($currency));

        if ($currency === '' || $currency === 'EUR') {
            return 1.0;
        }

        try {
            $price = $this->marketDataProvider->getStock($currency . 'EUR=X')->getQuote()->getPrice();
        } catch (Throwable) {
            return null;
        }

        return $price > 0 ? $price : null;
    }
}
