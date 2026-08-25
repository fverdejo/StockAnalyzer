<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Repository\MarketDataCacheRepository;

/**
 * Antes de este TTL corto, getIntradayQuotes() pedia siempre al proveedor
 * externo (ver comentario ya corregido en Application::renderIntraday()).
 * Lo que fijan estos casos es que ahora reutiliza el mismo mecanismo de
 * cache que el historico diario (findHistory()/saveHistory()), con la
 * clave "intraday_{interval}" para no colisionar con las claves de rango
 * diario ('6mo', '1y'...) ni entre intervalos intradia distintos, y un TTL
 * propio mucho mas corto que el diario.
 */
final class CachedMarketDataProviderIntradayTest extends TestCase
{
    private function seconds(DateInterval $interval): int
    {
        $reference = new DateTimeImmutable('2026-01-01 00:00:00');

        return $reference->add($interval)->getTimestamp() - $reference->getTimestamp();
    }

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

    public function testElTtlPorDefectoDelIntradiaEs90Segundos(): void
    {
        $provider = new CachedMarketDataProvider(
            $this->throwingInner(),
            $this->createMock(MarketDataCacheRepository::class)
        );

        self::assertSame(90, $this->seconds($provider->getIntradayTtl()));
    }

    public function testUnHitDeCacheDevuelveLoCacheadoSinLlamarAlProveedor(): void
    {
        $quotes = [
            new HistoricalQuote(new DateTimeImmutable('2026-08-25 10:00:00'), 100.0, 101.0, 99.0, 100.5, 1000),
        ];

        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->expects(self::once())
            ->method('findHistory')
            ->with('AAPL', self::isInstanceOf(DateInterval::class), 'intraday_5m')
            ->willReturn($quotes);
        $cache->expects(self::never())->method('saveHistory');

        $provider = new CachedMarketDataProvider($this->throwingInner(), $cache);

        self::assertSame($quotes, $provider->getIntradayQuotes('aapl', '5m'));
    }

    public function testUnMissDeCachePideAlProveedorYGuardaConLaClaveDelIntervalo(): void
    {
        $quotes = [
            new HistoricalQuote(new DateTimeImmutable('2026-08-25 10:15:00'), 200.0, 201.0, 199.0, 200.5, 500),
        ];

        $cache = $this->createMock(MarketDataCacheRepository::class);
        $cache->method('findHistory')->willReturn(null);
        $cache->expects(self::once())
            ->method('saveHistory')
            ->with('AAPL', $quotes, 'intraday_15m');

        $inner = new class ($quotes) implements MarketDataProviderInterface {
            /** @var array<int,string> [$ticker, $interval] recibidos en la ultima llamada. */
            public array $received = [];

            /** @param list<HistoricalQuote> $quotes */
            public function __construct(private readonly array $quotes)
            {
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
                $this->received = [$ticker, $interval];

                return $this->quotes;
            }

            public function getDividendHistory(string $ticker): array
            {
                throw new \LogicException('No usado en este test.');
            }
        };

        $provider = new CachedMarketDataProvider($inner, $cache);

        self::assertSame($quotes, $provider->getIntradayQuotes('AAPL', '15m'));
        self::assertSame(['AAPL', '15m'], $inner->received);
    }

    /**
     * `Connection` conecta de forma perezosa, asi que instanciar el
     * repositorio real (sin mockear) aqui no toca MySQL: el proposito de
     * este caso es solo comprobar que la construccion con TTL explicito
     * sigue funcionando tras el parametro nuevo del constructor.
     */
    public function testUnTtlDeIntradiaExplicitoMandaSobreElValorPorDefecto(): void
    {
        $provider = new CachedMarketDataProvider(
            $this->throwingInner(),
            new MarketDataCacheRepository(new Connection()),
            '2y',
            new DateInterval('PT15M'),
            null,
            new DateInterval('P30D'),
            new DateInterval('PT30S')
        );

        self::assertSame(30, $this->seconds($provider->getIntradayTtl()));
    }
}
