<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateInterval;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Interfaces\SymbolSearchProviderInterface;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Repository\MarketDataCacheRepository;

class CachedMarketDataProvider implements MarketDataProviderInterface, SymbolSearchProviderInterface
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
        private readonly DateInterval $dividendHistoryTtl = new DateInterval('P30D'),
        /**
         * TTL corto (90s por defecto, dentro del rango 60-120s pedido):
         * las velas intradia (v2.9) no se cacheaban nunca porque perder
         * frescura frente al mercado en vivo les quita valor, pero pedirlas
         * a Yahoo en cada refresco de la ficha de detalle sin ningun margen
         * es innecesario cuando varias peticiones caen en la misma ventana
         * de menos de dos minutos (p.ej. el usuario cambiando de rango de
         * fechas sobre el mismo grafico). 90s sigue siendo "casi en vivo":
         * la vela mas fina que se pide es de 1 minuto.
         */
        private readonly DateInterval $intradayTtl = new DateInterval('PT90S')
    ) {
        $this->historyTtl = $historyTtl ?? new DateInterval(
            self::HISTORY_TTL_BY_RANGE[$this->historyRange] ?? 'P1D'
        );
    }

    /**
     * Expuesto por el mismo motivo que getHistoryTtl(): poder verificar en
     * un test el TTL corto del intradia sin espiar la peticion al proveedor.
     */
    public function getIntradayTtl(): DateInterval
    {
        return $this->intradayTtl;
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
        } catch (\Throwable $exception) {
            self::logCacheFailure('getStock (lectura)', $ticker, $exception);
            $cached = null;
        }

        if ($cached instanceof Stock) {
            return $cached;
        }

        $stock = $this->inner->getStock($ticker);

        try {
            $this->cache->saveStock($ticker, $stock);
        } catch (\Throwable $exception) {
            self::logCacheFailure('getStock (escritura)', $ticker, $exception);
        }

        return $stock;
    }

    public function getHistoricalQuotes(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));
        $cached = null;

        try {
            $cached = $this->cache->findHistory($ticker, $this->historyTtl, $this->historyRange);
        } catch (\Throwable $exception) {
            self::logCacheFailure('getHistoricalQuotes (lectura)', $ticker, $exception);
            $cached = null;
        }

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $quotes = $this->inner->getHistoricalQuotes($ticker);

        try {
            $this->cache->saveHistory($ticker, $quotes, $this->historyRange);
        } catch (\Throwable $exception) {
            self::logCacheFailure('getHistoricalQuotes (escritura)', $ticker, $exception);
        }

        return $quotes;
    }

    /**
     * TTL corto (intradayTtl, 90s por defecto), no sin cache: las velas
     * intradia (v2.9) pierden su valor si se sirven con retraso, pero un
     * margen de minuto y medio sigue siendo "casi en vivo" (la vela mas
     * fina es de 1 minuto) y evita volver a pedir a Yahoo en cada
     * interaccion que cae dentro de esa ventana. Reutiliza el mismo
     * mecanismo que el historico diario (MarketDataCacheRepository::
     * findHistory()/saveHistory()), con el intervalo como parte de la
     * clave de rango (p.ej. "intraday_5m") para no colisionar con las
     * claves de historico diario ('6mo', '1y', '2y'...) ni entre
     * intervalos intradia distintos.
     */
    public function getIntradayQuotes(string $ticker, string $interval): array
    {
        $ticker = strtoupper(trim($ticker));
        $cacheKey = 'intraday_' . $interval;
        $cached = null;

        try {
            $cached = $this->cache->findHistory($ticker, $this->intradayTtl, $cacheKey);
        } catch (\Throwable $exception) {
            self::logCacheFailure('getIntradayQuotes (lectura)', $ticker, $exception);
            $cached = null;
        }

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $quotes = $this->inner->getIntradayQuotes($ticker, $interval);

        try {
            $this->cache->saveHistory($ticker, $quotes, $cacheKey);
        } catch (\Throwable $exception) {
            self::logCacheFailure('getIntradayQuotes (escritura)', $ticker, $exception);
        }

        return $quotes;
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
        } catch (\Throwable $exception) {
            self::logCacheFailure('getDividendHistory (lectura)', $ticker, $exception);
            $cached = null;
        }

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $payments = $this->inner->getDividendHistory($ticker);

        try {
            $this->cache->saveDividendHistory($ticker, $payments);
        } catch (\Throwable $exception) {
            self::logCacheFailure('getDividendHistory (escritura)', $ticker, $exception);
        }

        return $payments;
    }

    /**
     * Capacidad opcional reenviada al proveedor envuelto (ver roadmap.md,
     * "Buscador del Home", 2026-09-04): solo si `$this->inner` implementa
     * `SymbolSearchProviderInterface`, si no `null`, mismo patron ya usado
     * para `IndexMembershipCheckerInterface` en `BacktestingService`. A
     * diferencia del resto de metodos de esta clase, DELIBERADAMENTE no pasa
     * por `$this->cache`: es una busqueda rara, pedida por el usuario en el
     * momento (buscador de texto libre del Home), no un dato de mercado que
     * se repita entre peticiones -- cachearla no aporta nada.
     */
    public function searchSymbol(string $query): ?string
    {
        if (!$this->inner instanceof SymbolSearchProviderInterface) {
            return null;
        }

        return $this->inner->searchSymbol($query);
    }

    /**
     * Todos los catch (\Throwable) de esta clase envuelven una llamada al
     * repositorio de cache (PDO/JSON), nunca al proveedor externo: un
     * cache-miss normal (fila ausente o caducada) ya se resuelve devolviendo
     * null/[] sin lanzar nada (ver MarketDataCacheRepository::isFresh()), asi
     * que llegar a uno de estos catch significa siempre un fallo real (PDO
     * caido, fila corrupta, error de serializacion JSON al guardar), no
     * ruido esperado. El proyecto no tiene infraestructura de logging propia
     * (sin Logger/Monolog en uso, ver bin/*.php), asi que error_log() es el
     * mismo mecanismo que ya usa el resto de la aplicacion para avisos: a
     * diferencia de STDERR (constante que solo existe bajo el SAPI cli,
     * ver `versions.md` 2026-09-02), error_log() funciona igual en
     * bin/*.php que bajo php-fpm. El flujo de control no cambia: se sigue
     * cayendo al dato en vivo exactamente igual que antes de este aviso.
     */
    private static function logCacheFailure(string $operation, string $ticker, \Throwable $exception): void
    {
        error_log(
            sprintf(
                '[CachedMarketDataProvider] %s fallo para %s (%s): %s',
                $operation,
                $ticker,
                $exception::class,
                $exception->getMessage()
            )
        );
    }
}
