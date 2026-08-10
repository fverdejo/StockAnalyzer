<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Repository\MarketDataCacheRepository;

/**
 * El TTL del historico dejo de ser `P1D` para todos los rangos: con un solo
 * dia, cada ejecucion de `bin/backtest.php --history=10y` volvia a bajar
 * ~22 MB de cierres que llevan nueve años sin moverse, con el riesgo de 429
 * que eso trae.
 *
 * Lo que estos casos fijan es la regla, no el numero concreto: los rangos
 * cortos (los que sirve la web) mantienen frescura diaria y los largos (que
 * solo pide el backtest) no. Que se pueda seguir forzando un TTL explicito
 * tambien es parte del contrato.
 *
 * `Connection` conecta de forma perezosa (solo en `getPdo()`), asi que
 * construir el repositorio de cache aqui no toca MySQL: ningun caso de este
 * fichero llega a consultar nada.
 */
final class CachedMarketDataProviderTtlTest extends TestCase
{
    private function provider(string $range, ?DateInterval $ttl = null): CachedMarketDataProvider
    {
        return new CachedMarketDataProvider(
            new class implements MarketDataProviderInterface {
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
            },
            new MarketDataCacheRepository(new Connection()),
            $range,
            new DateInterval('PT15M'),
            $ttl
        );
    }

    /**
     * Convierte el intervalo a segundos para poder compararlo: dos
     * DateInterval con los mismos dias no son iguales con assertEquals si
     * se construyeron desde cadenas distintas.
     */
    private function seconds(DateInterval $interval): int
    {
        $reference = new DateTimeImmutable('2026-01-01 00:00:00');

        return $reference->add($interval)->getTimestamp() - $reference->getTimestamp();
    }

    public function testElRangoDeLaWebMantieneFrescuraDiaria(): void
    {
        self::assertSame(86400, $this->seconds($this->provider('2y')->getHistoryTtl()));
    }

    public function testElRangoPorDefectoEsElDeLaWeb(): void
    {
        $default = new CachedMarketDataProvider(
            $this->provider('2y'),
            new MarketDataCacheRepository(new Connection())
        );

        self::assertSame(86400, $this->seconds($default->getHistoryTtl()));
    }

    /**
     * El caso que motiva el cambio: 10 años solo los pide el backtest, y
     * ahi la cola de la serie es irrelevante porque el muestreo se detiene
     * un horizonte antes del final.
     */
    public function testLosRangosLargosCacheanUnaSemana(): void
    {
        self::assertSame(604800, $this->seconds($this->provider('10y')->getHistoryTtl()));
        self::assertSame(604800, $this->seconds($this->provider('5y')->getHistoryTtl()));
        self::assertSame(604800, $this->seconds($this->provider('max')->getHistoryTtl()));
    }

    /**
     * Un rango que no este en la tabla no debe heredar el TTL largo por
     * accidente: ante la duda, frescura diaria.
     */
    public function testUnRangoDesconocidoCaeEnElTtlCorto(): void
    {
        self::assertSame(86400, $this->seconds($this->provider('3y')->getHistoryTtl()));
    }

    public function testUnTtlExplicitoMandaSobreLaTabla(): void
    {
        $provider = $this->provider('10y', new DateInterval('PT30M'));

        self::assertSame(1800, $this->seconds($provider->getHistoryTtl()));
    }
}
