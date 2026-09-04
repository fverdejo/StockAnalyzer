<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Interfaces\SymbolSearchProviderInterface;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Repository\MarketDataCacheRepository;

/**
 * `CachedMarketDataProvider::searchSymbol()` (ver roadmap.md, "Buscador del
 * Home", 2026-09-04): capacidad opcional reenviada al proveedor envuelto
 * solo si implementa `SymbolSearchProviderInterface`, mismo patron que
 * `IndexMembershipCheckerInterface` en `BacktestingService`. Deliberadamente
 * SIN cache (a diferencia del resto de metodos de esta clase, ver
 * CachedMarketDataProviderIntradayTest.php para el estilo de doble usado).
 */
final class CachedMarketDataProviderSymbolSearchTest extends TestCase
{
    private function throwingInner(): MarketDataProviderInterface
    {
        return new class implements MarketDataProviderInterface {
            public function getStock(string $ticker): Stock
            {
                throw new \LogicException('No se debe pedir nada al proveedor en este test.');
            }

            public function getHistoricalQuotes(string $ticker): array
            {
                throw new \LogicException('No se debe pedir nada al proveedor en este test.');
            }

            public function getIntradayQuotes(string $ticker, string $interval): array
            {
                throw new \LogicException('No se debe pedir nada al proveedor en este test.');
            }

            public function getDividendHistory(string $ticker): array
            {
                throw new \LogicException('No se debe pedir nada al proveedor en este test.');
            }
        };
    }

    public function testDelegaAlInnerCuandoImplementaLaInterfazDeBusqueda(): void
    {
        $inner = new class implements MarketDataProviderInterface, SymbolSearchProviderInterface {
            /** @var list<string> */
            public array $received = [];

            public function searchSymbol(string $query): string
            {
                $this->received[] = $query;

                return '0K8D.L';
            }

            public function getStock(string $ticker): Stock
            {
                throw new \LogicException('No usado en este test.');
            }

            public function getHistoricalQuotes(string $ticker): array
            {
                throw new \LogicException('No usado en este test.');
            }

            public function getIntradayQuotes(string $ticker, string $interval): array
            {
                throw new \LogicException('No usado en este test.');
            }

            public function getDividendHistory(string $ticker): array
            {
                throw new \LogicException('No usado en este test.');
            }
        };

        $provider = new CachedMarketDataProvider($inner, $this->createMock(MarketDataCacheRepository::class));

        self::assertSame('0K8D.L', $provider->searchSymbol('Nokia'));
        self::assertSame(['Nokia'], $inner->received);
    }

    public function testDevuelveNullSinTocarLaCacheCuandoElInnerNoImplementaLaInterfaz(): void
    {
        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->expects(self::never())->method('findStock');
        $cache->expects(self::never())->method('saveStock');
        $cache->expects(self::never())->method('findHistory');
        $cache->expects(self::never())->method('saveHistory');

        $provider = new CachedMarketDataProvider($this->throwingInner(), $cache);

        self::assertNull($provider->searchSymbol('Nokia'));
    }
}
