<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateInterval;
use DateTimeImmutable;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Repository\MarketDataCacheRepository;

/**
 * La cache de historico esta indexada por `(ticker, history_range)` desde
 * `v2.79`. Antes la clave era solo el ticker, y eso tenia una consecuencia
 * concreta y silenciosa: `bin/backtest.php --history=10y` sobrescribia el
 * historico de 2 años que sirve la web, y la siguiente visita a una ficha
 * de detalle se encontraba 10 años cacheados (y al reves, la web devolvia
 * el backtest a 2 años sin que nadie se enterara). Ninguno de los dos
 * consumidores podia fiarse de lo que encontraba en cache.
 *
 * Eso es una propiedad de la clave primaria de la tabla, asi que se
 * comprueba contra el motor. `CachedMarketDataProviderTtlTest` ya cubre la
 * otra mitad del cambio (que rango largo y rango corto tienen TTL
 * distinto), pero con dobles: nunca toca la tabla.
 */
final class MarketHistoryCacheRangeTest extends IntegrationTestCase
{
    private MarketDataCacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new MarketDataCacheRepository($this->connection());
    }

    /**
     * @param int $days cuantas sesiones tiene la serie, para distinguir una de otra
     * @return list<HistoricalQuote>
     */
    private function serie(int $days, float $close): array
    {
        $quotes = [];

        for ($i = 0; $i < $days; ++$i) {
            $quotes[] = new HistoricalQuote(
                new DateTimeImmutable(sprintf('2026-01-01 +%d days', $i)),
                $close,
                $close,
                $close,
                $close,
                1_000
            );
        }

        return $quotes;
    }

    private function ttl(): DateInterval
    {
        return new DateInterval('P1D');
    }

    public function testCadaRangoSeGuardaYSeLeePorSeparado(): void
    {
        $this->cache->saveHistory('AAPL', $this->serie(2, 100.0), '2y');
        $this->cache->saveHistory('AAPL', $this->serie(5, 200.0), '10y');

        $corto = $this->cache->findHistory('AAPL', $this->ttl(), '2y');
        $largo = $this->cache->findHistory('AAPL', $this->ttl(), '10y');

        self::assertNotNull($corto);
        self::assertNotNull($largo);
        self::assertCount(2, $corto);
        self::assertCount(5, $largo);
        self::assertSame(100.0, $corto[0]->getClose());
        self::assertSame(200.0, $largo[0]->getClose());
    }

    /**
     * El caso que motivo el cambio, escrito tal cual ocurria: primero la
     * web deja su historico de 2 años, luego pasa el backtest con 10.
     */
    public function testUnBacktestConRangoLargoNoPisaElHistoricoDeLaWeb(): void
    {
        $this->cache->saveHistory('AAPL', $this->serie(2, 100.0), '2y');

        $this->cache->saveHistory('AAPL', $this->serie(9, 999.0), '10y');

        $webDespues = $this->cache->findHistory('AAPL', $this->ttl(), '2y');

        self::assertNotNull($webDespues);
        self::assertCount(2, $webDespues, 'La web sigue viendo su serie de 2 años.');
        self::assertSame(100.0, $webDespues[0]->getClose());
    }

    public function testGuardarDosVecesElMismoRangoActualizaEnVezDeDuplicar(): void
    {
        $this->cache->saveHistory('AAPL', $this->serie(2, 100.0), '2y');
        $this->cache->saveHistory('AAPL', $this->serie(3, 150.0), '2y');

        $filas = (int) $this->pdoOrSkip()
            ->query('SELECT COUNT(*) FROM market_history_cache WHERE ticker = "AAPL"')
            ->fetchColumn();
        $serie = $this->cache->findHistory('AAPL', $this->ttl(), '2y');

        self::assertSame(1, $filas, 'ON DUPLICATE KEY UPDATE, no una fila nueva.');
        self::assertNotNull($serie);
        self::assertCount(3, $serie);
        self::assertSame(150.0, $serie[0]->getClose());
    }

    public function testUnRangoQueNoSeHaPedidoNuncaNoDevuelveElDeOtro(): void
    {
        $this->cache->saveHistory('AAPL', $this->serie(2, 100.0), '2y');

        self::assertNull($this->cache->findHistory('AAPL', $this->ttl(), '5y'));
    }

    /**
     * Una serie caducada es como no tenerla: se devuelve `null` para que el
     * proveedor la vuelva a pedir. Se envejece la fila con SQL porque
     * `history_cached_at` lo pone `NOW()` el propio INSERT.
     */
    public function testUnaSerieCaducadaNoSeSirve(): void
    {
        $this->cache->saveHistory('AAPL', $this->serie(2, 100.0), '2y');

        $this->pdoOrSkip()->exec(
            'UPDATE market_history_cache SET history_cached_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE ticker = "AAPL"'
        );

        self::assertNull($this->cache->findHistory('AAPL', new DateInterval('P1D'), '2y'));
        self::assertNotNull(
            $this->cache->findHistory('AAPL', new DateInterval('P7D'), '2y'),
            'Con el TTL largo de los rangos de backtesting, la misma fila si vale.'
        );
    }

    public function testElTickerSeNormalizaAMayusculasEnLaCache(): void
    {
        $this->cache->saveHistory('aapl', $this->serie(2, 100.0), '2y');

        self::assertNotNull($this->cache->findHistory('AAPL', $this->ttl(), '2y'));
        self::assertNotNull($this->cache->findHistory('aapl', $this->ttl(), '2y'));
    }
}
