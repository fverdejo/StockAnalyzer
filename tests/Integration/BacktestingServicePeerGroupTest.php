<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Repository\TickerBacktestCacheRepository;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\RiskLevelsCalculator;
use StockAnalyzer\Tests\Services\MomentumPrefixFixture;
use StockAnalyzer\Tests\Services\PerTickerStockAndHistoryProvider;
use StockAnalyzer\Tests\Services\SyntheticStock;

/**
 * Cubre `BacktestingService::runForPeerGroup()` -- sin ningun test previo
 * (verificado con `grep` antes de escribir este fichero). Necesita
 * `TickerBacktestCacheRepository` real (`ON DUPLICATE KEY UPDATE` es
 * comportamiento de MySQL), de ahi que sea un test de integracion y no uno
 * de `tests/Services/`.
 *
 * Seguimiento de Astra (`2026-09-09`, caso 3): antes, la funcion devolvia
 * solo `buy_managed_samples`/`avg_buy_managed_return`, sin decir si ese
 * numero venia de TODO el grupo o de una parte -- la misma peticion podia
 * cambiar de valor sin aviso segun cuanta cache llevara calentada. Estos
 * tests fijan el desglose de cobertura nuevo (`tickers_pending`/`tickers_
 * failed`/`tickers_no_buy_signals`/`tickers_contributed`), no solo que la
 * cifra agregada siga siendo correcta.
 */
final class BacktestingServicePeerGroupTest extends IntegrationTestCase
{
    use MomentumPrefixFixture;

    private TickerBacktestCacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new TickerBacktestCacheRepository($this->connection());
    }

    private function service(PerTickerStockAndHistoryProvider $provider): BacktestingService
    {
        return new BacktestingService(
            $provider,
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0))
        );
    }

    /**
     * Mismo patron calibrado que `BacktestingServiceP0FixesTest::
     * calibratedSignalPattern()`: cierra en 104,0 con ATR14 exactamente 1,0
     * y recomendacion BUY en la señal, mas una entrada y un horizonte
     * planos (dentro de la banda de stop/objetivo) para que la muestra se
     * resuelva por "horizon", no por stop/objetivo -- lo unico que
     * importa aqui es que produzca una muestra BUY real y contable.
     *
     * @return list<HistoricalQuote>
     */
    private function buyerHistory(): array
    {
        $date = new DateTimeImmutable('2024-01-01');
        $quotes = $this->flatMomentumPrefix($date, 100.0);
        $close = 100.0;

        for ($i = 0; $i <= 80; $i++) {
            $volume = $i === 80 ? 3_000_000 : 1_000_000;
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, $volume);
            $date = $date->modify('+1 day');

            $close += match (true) {
                $i < 65 => 0.05,
                $i < 73 => -0.125,
                default => 0.25,
            };
        }

        // Entrada + horizonte (P0.1): planos, dentro de la banda de
        // stop/objetivo sobre ATR14=1,0 -- la muestra se resuelve por
        // "horizon", sin que importe aqui el motivo exacto de salida.
        for ($i = 0; $i < 6; $i++) {
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.3, $close - 0.3, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }

    /**
     * Espejo bajista: mismo prefijo plano, tendencia BAJISTA calibrada en
     * vez de alcista. Produce una muestra real (Momentum 12-1 no nulo,
     * igual margen que `buyerHistory()`) pero con recomendacion SELL/HOLD,
     * nunca BUY -- un resultado valido, no un fallo.
     *
     * @return list<HistoricalQuote>
     */
    private function neverBuysHistory(): array
    {
        $date = new DateTimeImmutable('2024-01-01');
        $quotes = $this->flatMomentumPrefix($date, 100.0);
        $close = 100.0;

        for ($i = 0; $i <= 80; $i++) {
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
            $close -= 0.05;
        }

        for ($i = 0; $i < 6; $i++) {
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.3, $close - 0.3, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }

    public function testUnGrupoCompletoYaCacheadoContribuyeConLosCuatroDesglosesEnCero(): void
    {
        $provider = new PerTickerStockAndHistoryProvider(
            ['BUYER' => SyntheticStock::create()],
            ['BUYER' => $this->buyerHistory()]
        );

        $result = $this->service($provider)->runForPeerGroup(['BUYER'], $this->cache, 5, 5);

        self::assertSame(1, $result['tickers_total']);
        self::assertSame(1, $result['tickers_contributed']);
        self::assertSame(0, $result['tickers_pending']);
        self::assertSame(0, $result['tickers_failed']);
        self::assertSame(0, $result['tickers_no_buy_signals']);
        self::assertGreaterThan(0, $result['buy_managed_samples']);
        self::assertNotNull($result['avg_buy_managed_return']);
    }

    public function testUnTickerSinSeñalesDeCompraCuentaComoNoBuySignalsNoComoFallo(): void
    {
        $provider = new PerTickerStockAndHistoryProvider(
            ['NEVERBUY' => SyntheticStock::create()],
            ['NEVERBUY' => $this->neverBuysHistory()]
        );

        $result = $this->service($provider)->runForPeerGroup(['NEVERBUY'], $this->cache, 5, 5);

        self::assertSame(1, $result['tickers_total']);
        self::assertSame(0, $result['tickers_contributed']);
        self::assertSame(0, $result['tickers_pending']);
        self::assertSame(0, $result['tickers_failed']);
        self::assertSame(1, $result['tickers_no_buy_signals']);
        self::assertSame(0, $result['buy_managed_samples']);
        self::assertNull($result['avg_buy_managed_return']);
    }

    public function testUnTickerSinDatosDeMercadoCuentaComoFallidoNoComoPendiente(): void
    {
        $provider = new PerTickerStockAndHistoryProvider([], []);

        $result = $this->service($provider)->runForPeerGroup(['SINDATOS'], $this->cache, 5, 5);

        self::assertSame(1, $result['tickers_total']);
        self::assertSame(0, $result['tickers_contributed']);
        self::assertSame(0, $result['tickers_pending']);
        self::assertSame(1, $result['tickers_failed']);
        self::assertSame(0, $result['tickers_no_buy_signals']);
        self::assertSame(0, $result['buy_managed_samples']);
    }

    /**
     * Reproduccion del hallazgo de Astra: con `$maxLiveComputations` mas
     * bajo que el numero de tickers sin cachear, el resto queda PENDIENTE
     * (nunca se intenta), no fallido. El agregado solo cuenta al que si se
     * llego a calcular.
     */
    public function testConElPresupuestoDeCalculoEnVivoAgotadoElRestoQuedaPendiente(): void
    {
        $provider = new PerTickerStockAndHistoryProvider(
            ['BUYER' => SyntheticStock::create(), 'BUYER2' => SyntheticStock::create()],
            ['BUYER' => $this->buyerHistory(), 'BUYER2' => $this->buyerHistory()]
        );

        $result = $this->service($provider)->runForPeerGroup(['BUYER', 'BUYER2'], $this->cache, 5, 5, 1);

        self::assertSame(2, $result['tickers_total']);
        self::assertSame(1, $result['tickers_contributed']);
        self::assertSame(1, $result['tickers_pending']);
        self::assertSame(0, $result['tickers_failed']);
        self::assertSame(0, $result['tickers_no_buy_signals']);

        // Al completar la cache (segunda llamada, presupuesto de sobra),
        // el pendiente pasa a contribuir y la cifra agregada cambia -- es
        // justo la diferencia que Astra pedia que quedara EXPUESTA, no
        // oculta detras de un mismo numero silencioso.
        $completeResult = $this->service($provider)->runForPeerGroup(['BUYER', 'BUYER2'], $this->cache, 5, 5, 5);

        self::assertSame(2, $completeResult['tickers_contributed']);
        self::assertSame(0, $completeResult['tickers_pending']);
        self::assertGreaterThan($result['buy_managed_samples'], $completeResult['buy_managed_samples']);
    }
}
