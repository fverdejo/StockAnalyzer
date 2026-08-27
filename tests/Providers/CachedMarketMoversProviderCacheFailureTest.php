<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StockAnalyzer\Interfaces\MarketMoversProviderInterface;
use StockAnalyzer\Providers\CachedMarketMoversProvider;
use StockAnalyzer\Repository\MarketMoversCacheRepository;

/**
 * Sin tests previos para esta clase. Cubre el comportamiento normal
 * (hit/miss) y, sobre todo, que un fallo real del repositorio de cache
 * (PDO caido, JSON corrupto) siga sin propagarse -- eso ya lo garantizaba
 * el try/catch existente, lo unico que cambia aqui es que ahora deja
 * rastro en STDERR (mismo patron que CachedMarketDataProvider::
 * logCacheFailure(), ver v2.102) en vez de desaparecer en silencio.
 */
final class CachedMarketMoversProviderCacheFailureTest extends TestCase
{
    private function innerReturning(array $gainers, array $losers): MarketMoversProviderInterface
    {
        return new class ($gainers, $losers) implements MarketMoversProviderInterface {
            public function __construct(private readonly array $gainers, private readonly array $losers)
            {
            }

            public function getTopGainers(int $count): array
            {
                return array_slice($this->gainers, 0, $count);
            }

            public function getTopLosers(int $count): array
            {
                return array_slice($this->losers, 0, $count);
            }
        };
    }

    public function testUnHitDeCacheConSuficientesTickersDevuelveLoCacheadoSinPedirAlInner(): void
    {
        $cache = $this->createMock(MarketMoversCacheRepository::class);
        $cache->method('find')->willReturn(['AAA', 'BBB', 'CCC']);
        $cache->expects(self::never())->method('save');

        $inner = $this->createMock(MarketMoversProviderInterface::class);
        $inner->expects(self::never())->method('getTopGainers');

        $provider = new CachedMarketMoversProvider($inner, $cache);

        self::assertSame(['AAA', 'BBB'], $provider->getTopGainers(2));
    }

    public function testUnMissDeCachePideAlInnerYGuarda(): void
    {
        $cache = $this->createMock(MarketMoversCacheRepository::class);
        $cache->method('find')->willReturn(null);
        $cache->expects(self::once())->method('save')->with('losers', ['XXX', 'YYY']);

        $provider = new CachedMarketMoversProvider($this->innerReturning([], ['XXX', 'YYY']), $cache);

        self::assertSame(['XXX', 'YYY'], $provider->getTopLosers(5));
    }

    public function testUnFalloDeLecturaDeCacheCaeAlInnerEnVezDePropagar(): void
    {
        $cache = $this->createMock(MarketMoversCacheRepository::class);
        $cache->method('find')->willThrowException(new RuntimeException('PDO caido'));
        $cache->method('save');

        $provider = new CachedMarketMoversProvider($this->innerReturning(['ZZZ'], []), $cache);

        self::assertSame(['ZZZ'], $provider->getTopGainers(1));
    }

    public function testUnFalloDeEscrituraDeCacheNoPropagaYDevuelveElDatoEnVivo(): void
    {
        $cache = $this->createMock(MarketMoversCacheRepository::class);
        $cache->method('find')->willReturn(null);
        $cache->method('save')->willThrowException(new RuntimeException('disco lleno'));

        $provider = new CachedMarketMoversProvider($this->innerReturning(['QQQ'], []), $cache);

        self::assertSame(['QQQ'], $provider->getTopGainers(1));
    }
}
