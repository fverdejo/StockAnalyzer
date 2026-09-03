<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\RiskLevelsCalculator;

/**
 * Cubre `mode='momentum'` de `runCrossSectional()` (P3.4,
 * `REVISION_MOTOR_CODEX_2026-09-02.md`, seccion "3. Nuevo modo 'momentum'"):
 * el ranking de cada fecha deja de ser por `percentage` (el score) y pasa a
 * ser por Momentum 12-1 neutralizado por sector y por tamaño
 * (`BacktestingService::rankByMomentumNeutral()`).
 *
 * Cada ticker aqui tiene exactamente UNA fecha de señal (misma tecnica que
 * `momentumTickerHistory()`: 170 velas planas de prefijo -- ver
 * `MomentumPrefixFixture` -- mas un tramo controlado que deja
 * `momentum12m1` en un valor EXACTO y conocido, ver el docblock de
 * `momentumTickerHistory()`), asi que solo hace falta un `Stock` con sector
 * y marketCap propios por ticker (`PerTickerStockAndHistoryProvider`) y un
 * `FundamentalsHistoryRepository` en memoria
 * (`InMemoryFundamentalsHistoryRepository`) que decide, ticker a ticker, si
 * `market_cap_is_point_in_time` sale `true` o `false`.
 */
final class BacktestingServiceMomentumModeTest extends TestCase
{
    use MomentumPrefixFixture;

    private const HORIZON_DAYS = 5;
    private const STEP = 5;

    /**
     * Construye el historico de UN ticker con Momentum 12-1 y forward_return
     * EXACTOS: `TechnicalAnalyzer::momentumSkippingRecent($closes, 250, 21)`
     * calcula `(closes[n-1-21] - closes[n-1-250]) / closes[n-1-250] * 100`
     * sobre el historico visto hasta la señal. Con el prefijo de
     * `MomentumPrefixFixture` (170 velas planas a `$flatClose`) seguido de:
     *
     * - indice de patron 0: una vela a `$flatClose` exacto (evita el salto
     *   de precio que `MomentumPrefixFixture` advierte que distorsiona ATR).
     * - indices 1-59 (59 velas): interpolacion lineal de `$flatClose` a
     *   `$target = $flatClose * (1 + $momentumPercent/100)`.
     * - indices 60-80 (21 velas, la ventana que Momentum 12-1 salta): planas
     *   en `$target`.
     * - entrada (P0.1, indice 81): plana en `$target`.
     * - horizonte (5 velas): interpolacion lineal de `$target` a
     *   `$target * (1 + $forwardMovePercent/100)`.
     *
     * ... la señal cae exactamente en el indice bruto 250 (170 prefijo + 80),
     * donde `closes[0]` (primera vela del prefijo) = `$flatClose` y
     * `closes[229]` (170 prefijo + 59) = `$target`: Momentum 12-1 sale
     * `(($target-$flatClose)/$flatClose)*100 = $momentumPercent` exacto, y
     * `forward_return` sale `$forwardMovePercent` exacto (entrada = `$target`,
     * ultima vela del horizonte = `$target*(1+$forwardMovePercent/100)`).
     *
     * @return list<HistoricalQuote>
     */
    private function momentumTickerHistory(
        float $flatClose,
        float $momentumPercent,
        float $forwardMovePercent
    ): array {
        $date = new DateTimeImmutable('2024-01-01');
        $quotes = $this->flatMomentumPrefix($date, $flatClose);

        $quotes[] = new HistoricalQuote($date, $flatClose, $flatClose + 0.5, $flatClose - 0.5, $flatClose, 1_000_000);
        $date = $date->modify('+1 day');

        $target = $flatClose * (1 + ($momentumPercent / 100));

        foreach ($this->linearSegment($flatClose, $target, 59) as $segmentClose) {
            $quotes[] = new HistoricalQuote($date, $segmentClose, $segmentClose + 0.5, $segmentClose - 0.5, $segmentClose, 1_000_000);
            $date = $date->modify('+1 day');
        }

        for ($i = 0; $i < 21; $i++) {
            $quotes[] = new HistoricalQuote($date, $target, $target + 0.5, $target - 0.5, $target, 1_000_000);
            $date = $date->modify('+1 day');
        }

        // Entrada (P0.1): continua plana en $target.
        $quotes[] = new HistoricalQuote($date, $target, $target + 0.5, $target - 0.5, $target, 1_000_000);
        $date = $date->modify('+1 day');

        $future = $target * (1 + ($forwardMovePercent / 100));

        foreach ($this->linearSegment($target, $future, self::HORIZON_DAYS) as $segmentClose) {
            $quotes[] = new HistoricalQuote($date, $segmentClose, $segmentClose + 0.5, $segmentClose - 0.5, $segmentClose, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }

    /**
     * @return list<float>
     */
    private function linearSegment(float $from, float $to, int $steps): array
    {
        $delta = ($to - $from) / $steps;
        $result = [];

        for ($i = 1; $i <= $steps; $i++) {
            $result[] = $i === $steps ? $to : $from + ($i * $delta);
        }

        return $result;
    }

    /**
     * `Company::getTicker()`, NO el ticker por el que se pidio el `Stock` a
     * `PerTickerStockAndHistoryProvider`, es lo que `BacktestingService::stockAt()`
     * usa para consultar `fundamentalsAt()` (`$company->getTicker()`, ver el
     * codigo real). Tiene que coincidir con la clave usada en
     * `InMemoryFundamentalsHistoryRepository::withMarketCapSnapshot()` o el
     * snapshot nunca se encontraria y todas las muestras saldrian
     * `market_cap_is_point_in_time = false` sin excepcion.
     */
    private function stockFor(string $ticker, string $sector): Stock
    {
        return new Stock(
            new Company($ticker, 'Test Corp', $sector, 'software', 'NASDAQ', 'USD'),
            new Quote(100.0, 100.0, 100.0, 100.0, 100.0, 1_000_000, new DateTimeImmutable('2024-01-01')),
            new Fundamentals(
                per: 10.0,
                peg: 0.5,
                roe: 25.0,
                roic: null,
                eps: 5.0,
                marketCap: 1_000_000_000.0,
                debtToEquity: 0.1,
                freeCashFlow: 100_000_000.0,
                evToEbitda: 6.0,
                priceToBook: 1.0
            )
        );
    }

    public function testSectorConMenosDe20MuestrasQuedaExcluidoYElContadorLoRefleja(): void
    {
        $stocksByTicker = [];
        $historiesByTicker = [];
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();
        $tickers = [];

        // Sector elegible: 21 tickers (>= MIN_SECTOR_SAMPLES_MOMENTUM = 20),
        // momentum modesto (0%-20%).
        for ($i = 0; $i <= 20; $i++) {
            $ticker = sprintf('T%02d', $i);
            $tickers[] = $ticker;
            $stocksByTicker[$ticker] = $this->stockFor($ticker, 'tech');
            $historiesByTicker[$ticker] = $this->momentumTickerHistory(100.0, (float) $i, 1.0);
            $fundamentalsHistory->withMarketCapSnapshot($ticker, 1_000_000_000.0);
        }

        // Sector demasiado pequeño (5 < 20): momentum ENORME, para que si el
        // filtro de sector no se aplicase dominarian el top-N sin discusion.
        for ($i = 0; $i < 5; $i++) {
            $ticker = sprintf('E%02d', $i);
            $tickers[] = $ticker;
            $stocksByTicker[$ticker] = $this->stockFor($ticker, 'energy');
            $historiesByTicker[$ticker] = $this->momentumTickerHistory(100.0, 1000.0 + $i, -50.0);
            $fundamentalsHistory->withMarketCapSnapshot($ticker, 1_000_000_000.0);
        }

        $service = new BacktestingService(
            new PerTickerStockAndHistoryProvider($stocksByTicker, $historiesByTicker),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0)),
            fundamentalsHistory: $fundamentalsHistory
        );

        $result = $service->runCrossSectional($tickers, self::HORIZON_DAYS, self::STEP, 3, 'momentum');

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(26, $result['dates'][0]['universe_size']);
        self::assertSame(5, $result['samples_dropped_thin_sector']);
        self::assertSame(0, $result['samples_dropped_no_marketcap_pit']);
        self::assertCount(3, $result['dates'][0]['top_tickers']);

        foreach ($result['dates'][0]['top_tickers'] as $topTicker) {
            self::assertStringStartsNotWith('E', $topTicker);
        }
    }

    public function testMuestraSinMarketCapPointInTimeQuedaExcluidaYElContadorLoRefleja(): void
    {
        $stocksByTicker = [];
        $historiesByTicker = [];
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();
        $tickers = [];

        for ($i = 0; $i <= 20; $i++) {
            $ticker = sprintf('T%02d', $i);
            $tickers[] = $ticker;
            $stocksByTicker[$ticker] = $this->stockFor($ticker, 'tech');
            $historiesByTicker[$ticker] = $this->momentumTickerHistory(100.0, (float) $i, 1.0);

            // SINPIT se queda deliberadamente SIN registrar snapshot (cae al
            // fallback de "hoy", marketCapIsPointInTime = false). El resto,
            // con snapshot real.
            if ($ticker !== 'T20') {
                $fundamentalsHistory->withMarketCapSnapshot($ticker, 1_000_000_000.0);
            }
        }

        // T20 (i=20, el momentum RAW mas alto de todos) es justo el que se
        // queda sin snapshot: si el filtro de marketCap point-in-time no se
        // aplicase, ganaria el ranking sin discusion.
        $service = new BacktestingService(
            new PerTickerStockAndHistoryProvider($stocksByTicker, $historiesByTicker),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0)),
            fundamentalsHistory: $fundamentalsHistory
        );

        $result = $service->runCrossSectional($tickers, self::HORIZON_DAYS, self::STEP, 3, 'momentum');

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(0, $result['samples_dropped_thin_sector']);
        self::assertSame(1, $result['samples_dropped_no_marketcap_pit']);
        self::assertCount(3, $result['dates'][0]['top_tickers']);
        self::assertNotContains('T20', $result['dates'][0]['top_tickers']);
    }

    /**
     * Caso calculable a mano (P3.4), con margenes amplios a proposito (no
     * empates exactos) para no depender del ultimo decimal de coma flotante
     * de la interpolacion de `momentumTickerHistory()`:
     *
     * - Sector "tech" (20 tickers, A00..A19): momentum RAW identico, 50%
     *   para todos. Mediana del sector = 50 -> `momentum_sector_neutral` =
     *   50-50 = 0 para los 20.
     * - Sector "health" (20 tickers): 19 de ellos (B00..B18) con momentum RAW
     *   identico, 10%; el ultimo, `BSTAR`, con momentum RAW 30%. Mediana del
     *   sector (20 valores: diecinueve 10 + un 30, los dos valores centrales
     *   ordenados son ambos 10) = 10 -> `momentum_sector_neutral` = 0 para
     *   B00..B18, y 30-10 = **+20** para `BSTAR`.
     *
     * Terciles de tamaño: mismo marketCap para las 40, asi que el reparto es
     * por orden de insercion, pero es irrelevante para el resultado --
     * cualquier tercil en el que caiga `BSTAR` tiene mayoria abrumadora de
     * companeros en 0, asi que su mediana de tercil tambien sale 0. Todos los
     * `momentum_neutral` quedan en 0 EXCEPTO `BSTAR`, en +20: una diferencia
     * de dos ordenes de magnitud frente al ruido de coma flotante de la
     * interpolacion (~1e-13), nunca puede invertir el orden.
     *
     * La prueba real del punto de P3.4: sin neutralizar, el top-1 por
     * momentum RAW seria cualquier ticker de "tech" (50% > 30% de `BSTAR`).
     * Neutralizado, `BSTAR` gana -- supera a SU PROPIO sector por mucho mas
     * de lo que "tech" supera al suyo (que ni siquiera se separa de su
     * propia mediana). `forward_return` de `BSTAR` se fija en 42% exacto
     * para comprobar tambien que la muestra seleccionada es la correcta, no
     * solo que el nombre del ticker coincide.
     */
    public function testNeutralizacionPorSectorYTamanoProduceElRankingEsperado(): void
    {
        $stocksByTicker = [];
        $historiesByTicker = [];
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();
        $tickers = [];

        for ($i = 0; $i < 20; $i++) {
            $ticker = sprintf('A%02d', $i);
            $tickers[] = $ticker;
            $stocksByTicker[$ticker] = $this->stockFor($ticker, 'tech');
            $historiesByTicker[$ticker] = $this->momentumTickerHistory(100.0, 50.0, 0.0);
            $fundamentalsHistory->withMarketCapSnapshot($ticker, 1_000_000_000.0);
        }

        for ($i = 0; $i < 19; $i++) {
            $ticker = sprintf('B%02d', $i);
            $tickers[] = $ticker;
            $stocksByTicker[$ticker] = $this->stockFor($ticker, 'health');
            $historiesByTicker[$ticker] = $this->momentumTickerHistory(100.0, 10.0, 0.0);
            $fundamentalsHistory->withMarketCapSnapshot($ticker, 1_000_000_000.0);
        }

        $tickers[] = 'BSTAR';
        $stocksByTicker['BSTAR'] = $this->stockFor('BSTAR', 'health');
        $historiesByTicker['BSTAR'] = $this->momentumTickerHistory(100.0, 30.0, 42.0);
        $fundamentalsHistory->withMarketCapSnapshot('BSTAR', 1_000_000_000.0);

        $service = new BacktestingService(
            new PerTickerStockAndHistoryProvider($stocksByTicker, $historiesByTicker),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0)),
            fundamentalsHistory: $fundamentalsHistory
        );

        $result = $service->runCrossSectional($tickers, self::HORIZON_DAYS, self::STEP, 1, 'momentum');

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(0, $result['samples_dropped_thin_sector']);
        self::assertSame(0, $result['samples_dropped_no_marketcap_pit']);
        self::assertSame(40, $result['dates'][0]['universe_size']);
        self::assertSame(['BSTAR'], $result['dates'][0]['top_tickers']);
        self::assertSame(42.0, $result['dates'][0]['top_avg_forward_return']);
    }
}
