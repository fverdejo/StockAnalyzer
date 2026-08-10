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
     * TTL del historico por rango, usado cuando no se pasa uno explicito.
     * Un rango largo solo se pide desde bin/backtest.php, donde la cola de
     * la serie es irrelevante: el backtest deja de muestrear $horizon dias
     * antes del final, asi que unos cierres de menos no cambian ni una
     * muestra. Lo que si cambia es el coste: con P1D, un backtest de 10y
     * vuelve a descargar ~22 MB cada dia que se ejecuta, y los cierres de
     * hace nueve años no se han movido. La web (2y) se queda en P1D, que
     * es donde la frescura si importa.
     */
    private const HISTORY_TTL_BY_RANGE = [
        '6mo' => 'P1D',
        '1y' => 'P1D',
        '2y' => 'P1D',
        '5y' => 'P7D',
        '10y' => 'P7D',
        'max' => 'P7D',
    ];

    private readonly DateInterval $historyTtl;

    /**
     * $historyRange NO cambia lo que se pide al proveedor (eso lo decide el
     * propio proveedor envuelto, p.ej. YahooFinanceProvider): es la etiqueta
     * con la que se guarda el historico en cache, y debe coincidir con el
     * rango que ese proveedor pide de verdad. Existe porque un historico de
     * 2 años y uno de 10 no son el mismo dato: si se guardasen bajo la misma
     * clave, una ejecucion de bin/backtest.php con rango largo envenenaria
     * el historico que sirve la web (y la web, el del backtest).
     *
     * $historyTtl a null (por defecto) deriva el TTL del rango segun
     * HISTORY_TTL_BY_RANGE. Pasarlo explicito sigue mandando sobre esa
     * tabla, para no quitarle a nadie el control de su propia cache.
     */
    public function __construct(
        private readonly MarketDataProviderInterface $inner,
        private readonly MarketDataCacheRepository $cache,
        private readonly string $historyRange = '2y',
        private readonly DateInterval $stockTtl = new DateInterval('PT15M'),
        ?DateInterval $historyTtl = null,
        private readonly DateInterval $dividendHistoryTtl = new DateInterval('P30D')
    ) {
        $this->historyTtl = $historyTtl ?? new DateInterval(
            self::HISTORY_TTL_BY_RANGE[$this->historyRange] ?? 'P1D'
        );
    }

    /**
     * Expuesto para poder verificar en un test que el TTL derivado del rango
     * es el esperado, sin tener que espiar la peticion al proveedor.
     */
    public function getHistoryTtl(): DateInterval
    {
        return $this->historyTtl;
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
