<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StockAnalyzer\DTO\DividendPayment;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Providers\OfflineOnlyMarketDataProvider;
use StockAnalyzer\Repository\MarketDataCacheRepository;

/**
 * `OfflineOnlyMarketDataProvider` (Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B5): a diferencia
 * de `CachedMarketDataProvider`, NUNCA cae a un proveedor real. Distingue
 * ausencia legitima (`MarketDataException`) de fallo tecnico
 * (`RuntimeException`) -- confundirlas fue el hallazgo real de Astra
 * (91 errores de lectura de cache seguidos, en todos los casos, de una
 * peticion real a Yahoo dentro de un estudio que se declaraba offline).
 */
final class OfflineOnlyMarketDataProviderTest extends TestCase
{
    private function provider(MarketDataCacheRepository $cache): OfflineOnlyMarketDataProvider
    {
        return new OfflineOnlyMarketDataProvider($cache, '10y');
    }

    public function testGetHistoricalQuotesDevuelveElHistoricoCacheado(): void
    {
        $quotes = [$this->createMock(HistoricalQuote::class)];
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findHistory')->willReturn($quotes);

        self::assertSame($quotes, $this->provider($cache)->getHistoricalQuotes('AAPL'));
    }

    public function testGetHistoricalQuotesSinCacheLanzaAusenciaLegitima(): void
    {
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findHistory')->willReturn(null);

        $this->expectException(MarketDataException::class);

        $this->provider($cache)->getHistoricalQuotes('AAPL');
    }

    /**
     * Hallazgo real de Astra: un fallo TECNICO leyendo la cache no puede
     * tratarse igual que "sin dato" -- debe distinguirse con otro tipo de
     * excepcion para que un orquestador pueda detenerse en vez de seguir
     * al siguiente ticker como si el ausente fuera legitimo.
     */
    public function testGetHistoricalQuotesConFalloTecnicoLanzaRuntimeExceptionDistinta(): void
    {
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findHistory')->willThrowException(new \PDOException('MySQL server has gone away'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Fallo TECNICO/');

        $this->provider($cache)->getHistoricalQuotes('AAPL');
    }

    public function testGetStockDevuelveLaFichaCacheada(): void
    {
        $stock = $this->createMock(Stock::class);
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findStock')->willReturn($stock);

        self::assertSame($stock, $this->provider($cache)->getStock('AAPL'));
    }

    public function testGetStockSinCacheLanzaAusenciaLegitima(): void
    {
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findStock')->willReturn(null);

        $this->expectException(MarketDataException::class);

        $this->provider($cache)->getStock('AAPL');
    }

    public function testGetStockConFalloTecnicoLanzaRuntimeException(): void
    {
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findStock')->willThrowException(new \PDOException('MySQL server has gone away'));

        $this->expectException(RuntimeException::class);

        $this->provider($cache)->getStock('AAPL');
    }

    /**
     * Sin dividendos es un array vacio LEGITIMO (mismo criterio que
     * `MarketDataProviderInterface`), no una excepcion -- solo un fallo
     * tecnico debe distinguirse aqui.
     */
    public function testGetDividendHistorySinCacheDevuelveArrayVacioSinLanzar(): void
    {
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findDividendHistory')->willReturn(null);

        self::assertSame([], $this->provider($cache)->getDividendHistory('AAPL'));
    }

    public function testGetDividendHistoryDevuelveLosPagosCacheados(): void
    {
        $payments = [$this->createMock(DividendPayment::class)];
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findDividendHistory')->willReturn($payments);

        self::assertSame($payments, $this->provider($cache)->getDividendHistory('AAPL'));
    }

    public function testGetDividendHistoryConFalloTecnicoLanzaRuntimeException(): void
    {
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findDividendHistory')->willThrowException(new \PDOException('MySQL server has gone away'));

        $this->expectException(RuntimeException::class);

        $this->provider($cache)->getDividendHistory('AAPL');
    }

    public function testGetIntradayQuotesNoEstaSoportado(): void
    {
        $cache = $this->createMock(MarketDataCacheRepository::class);

        $this->expectException(RuntimeException::class);

        $this->provider($cache)->getIntradayQuotes('AAPL', '5m');
    }
}
