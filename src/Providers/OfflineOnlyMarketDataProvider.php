<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateInterval;
use RuntimeException;
use StockAnalyzer\DTO\DividendPayment;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use Throwable;

/**
 * Proveedor de SOLO CACHE para estudios que se declaran offline (Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B5): a diferencia
 * de `CachedMarketDataProvider` (usado por la web, donde caer al
 * proveedor real ante un cache-miss es el comportamiento CORRECTO), este
 * NUNCA llama a la red bajo ninguna circunstancia.
 *
 * Hallazgo real que motiva esta clase: el intento de medicion completa
 * del `2026-09-16`/`17` usaba `CachedMarketDataProvider` dentro de lo que
 * se documentaba como un estudio "sin llamadas de red" -- Astra confirmo
 * en el log real **91 errores de lectura de cache y 88 de escritura**
 * seguidos, en todos los casos, de una llamada real al proveedor
 * interno. Un estudio que se declara offline no puede degradarse en
 * silencio a peticiones de red bajo presion de memoria/DB justo cuando
 * mas importa que no lo haga.
 *
 * Distingue dos situaciones que `CachedMarketDataProvider` trataba igual
 * (ambas devolvian "sigue a la red"):
 *
 * - **Ausencia legitima**: el ticker no tiene cache para el rango pedido
 *   (nunca se archivo, o esta mas caducado que el TTL permisivo de este
 *   estudio). Lanza `MarketDataException` -- el llamante puede tratarlo
 *   como "sin dato para este ticker", una situacion de NEGOCIO.
 * - **Fallo tecnico**: una excepcion real leyendo el repositorio de
 *   cache (conexion caida, error de deserializacion). Lanza
 *   `RuntimeException` -- un fallo de INFRAESTRUCTURA que un orquestador
 *   debe tratar como razon para detenerse, no para continuar al
 *   siguiente ticker como si nada.
 *
 * `$historyTtl` por defecto es deliberadamente permisivo (aceptar cache
 * de cualquier antiguedad): el objetivo de un estudio congelado es leer
 * EXACTAMENTE lo que ya esta archivado, no negociar frescura.
 */
final class OfflineOnlyMarketDataProvider implements MarketDataProviderInterface
{
    public function __construct(
        private readonly MarketDataCacheRepository $cache,
        private readonly string $historyRange,
        private readonly DateInterval $historyTtl = new DateInterval('P100Y'),
        private readonly DateInterval $stockTtl = new DateInterval('P100Y'),
        private readonly DateInterval $dividendHistoryTtl = new DateInterval('P100Y')
    ) {
    }

    public function getStock(string $ticker): Stock
    {
        try {
            $cached = $this->cache->findStock($ticker, $this->stockTtl);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Fallo TECNICO leyendo cache offline (stock) para %s: %s', $ticker, $exception->getMessage()),
                0,
                $exception
            );
        }

        if (!$cached instanceof Stock) {
            throw new MarketDataException(sprintf(
                '%s: sin ficha en cache -- ausencia legitima bajo modo offline estricto, no se consulta a ningun proveedor real.',
                $ticker
            ));
        }

        return $cached;
    }

    public function getHistoricalQuotes(string $ticker): array
    {
        try {
            $cached = $this->cache->findHistory($ticker, $this->historyTtl, $this->historyRange);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Fallo TECNICO leyendo cache offline (historico) para %s: %s', $ticker, $exception->getMessage()),
                0,
                $exception
            );
        }

        if (!is_array($cached) || $cached === []) {
            throw new MarketDataException(sprintf(
                '%s: sin historico en cache para el rango "%s" -- ausencia legitima bajo modo offline estricto, no se consulta a ningun proveedor real.',
                $ticker,
                $this->historyRange
            ));
        }

        return $cached;
    }

    /**
     * No lo usa ningun estudio de replay/backtesting (velas intradia,
     * fuera de alcance de un estudio de sesiones diarias) -- falla
     * explicitamente en vez de fingir soporte.
     */
    public function getIntradayQuotes(string $ticker, string $interval): array
    {
        throw new RuntimeException('OfflineOnlyMarketDataProvider no soporta velas intradia: fuera de alcance de un estudio offline diario.');
    }

    /**
     * `DividendGrowthCalculator`/`BacktestingService::enrichWithDividendGrowth()`
     * ya tratan "sin dividendos" como un array vacio legitimo (ver
     * `MarketDataProviderInterface`) -- un fallo TECNICO leyendo la cache
     * de dividendos si debe distinguirse, igual que en los otros dos
     * metodos.
     *
     * @return list<DividendPayment>
     */
    public function getDividendHistory(string $ticker): array
    {
        try {
            $cached = $this->cache->findDividendHistory($ticker, $this->dividendHistoryTtl);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Fallo TECNICO leyendo cache offline (dividendos) para %s: %s', $ticker, $exception->getMessage()),
                0,
                $exception
            );
        }

        return is_array($cached) ? $cached : [];
    }
}
