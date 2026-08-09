<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateInterval;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Repository\MarketDataCacheRepository;

class CachedMarketDataProvider implements MarketDataProviderInterface
{
    /**
     * $historyRange NO cambia lo que se pide al proveedor (eso lo decide el
     * propio proveedor envuelto, p.ej. YahooFinanceProvider): es la etiqueta
     * con la que se guarda el historico en cache, y debe coincidir con el
     * rango que ese proveedor pide de verdad. Existe porque un historico de
     * 2 años y uno de 10 no son el mismo dato: si se guardasen bajo la misma
     * clave, una ejecucion de bin/backtest.php con rango largo envenenaria
     * el historico que sirve la web (y la web, el del backtest).
     */
    public function __construct(
        private readonly MarketDataProviderInterface $inner,
        private readonly MarketDataCacheRepository $cache,
        private readonly string $historyRange = '2y',
        private readonly DateInterval $stockTtl = new DateInterval('PT15M'),
        private readonly DateInterval $historyTtl = new DateInterval('P1D'),
        private readonly DateInterval $dividendHistoryTtl = new DateInterval('P30D')
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
            $cached = $this->cache->findHistory($ticker, $this->historyTtl, $this->historyRange);
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $quotes = $this->inner->getHistoricalQuotes($ticker);

        try {
            $this->cache->saveHistory($ticker, $quotes, $this->historyRange);
        } catch (\Throwable) {
        }

        return $quotes;
    }

    /**
     * Sin cache: las velas intradia (v2.9) pierden su valor si se sirven
     * con retraso, y su volumen de peticiones es mucho menor que el
     * ranking diario (solo se piden cuando alguien abre la temporalidad
     * intradia en la ficha de detalle).
     */
    public function getIntradayQuotes(string $ticker, string $interval): array
    {
        return $this->inner->getIntradayQuotes(strtoupper(trim($ticker)), $interval);
    }

    /**
     * TTL mucho mas largo que stockTtl/historyTtl (30 dias por defecto): el
     * historial de dividendos reales no cambia intradia ni siquiera de un
     * dia para otro, a diferencia del resto de datos que cachea esta clase
     * (ver Services\DividendGrowthCalculator, que es quien consume este
     * historial).
     */
    public function getDividendHistory(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));
        $cached = null;

        try {
            $cached = $this->cache->findDividendHistory($ticker, $this->dividendHistoryTtl);
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $payments = $this->inner->getDividendHistory($ticker);

        try {
            $this->cache->saveDividendHistory($ticker, $payments);
        } catch (\Throwable) {
        }

        return $payments;
    }
}
