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
 * Cubre `mode='fundamental'` de `runCrossSectional()` (P3.3,
 * `REVISION_MOTOR_CODEX_2026-09-02.md`, seccion P3.3): el ranking de cada
 * fecha pasa a ser por `fundamental_score` (media de las tres familias
 * Valor/Calidad/Solidez, cada una puntuada por percentil dentro del propio
 * sector el mismo dia) en vez de por `percentage` (el score de 50 puntos) o
 * por Momentum 12-1 (`mode='momentum'`, P3.4).
 *
 * Mismo patron que `BacktestingServiceMomentumModeTest`: cada ticker tiene
 * una unica fecha de señal (`historyWithForwardMove()`, misma tecnica de
 * `MomentumPrefixFixture` + un tramo interpolado que deja `forward_return`
 * en un valor EXACTO conocido -- el Momentum 12-1 en si se deja plano
 * (0%) en todos los tickers a proposito, porque `mode='fundamental'` no lo
 * usa para nada: solo tiene que ser no-nulo para que P0.3 no descarte la
 * muestra).
 */
final class BacktestingServiceFundamentalModeTest extends TestCase
{
    use MomentumPrefixFixture;

    private const HORIZON_DAYS = 5;
    private const STEP = 5;

    /**
     * Historico de UN ticker con Momentum 12-1 plano (0%, solo para que no
     * sea `null`, ver el docblock de la clase) y `forward_return` EXACTO:
     * mismo esqueleto que `BacktestingServiceMomentumModeTest::momentumTickerHistory()`
     * (170 velas de prefijo + 60 planas + 21 de salto + entrada + horizonte
     * interpolado), con el tramo de momentum sin movimiento.
     *
     * @return list<HistoricalQuote>
     */
    private function historyWithForwardMove(float $flatClose, float $forwardMovePercent): array
    {
        $date = new DateTimeImmutable('2024-01-01');
        $quotes = $this->flatMomentumPrefix($date, $flatClose);

        for ($i = 0; $i < 81; $i++) {
            $quotes[] = new HistoricalQuote($date, $flatClose, $flatClose + 0.5, $flatClose - 0.5, $flatClose, 1_000_000);
            $date = $date->modify('+1 day');
        }

        // Entrada (P0.1): continua plana en $flatClose.
        $quotes[] = new HistoricalQuote($date, $flatClose, $flatClose + 0.5, $flatClose - 0.5, $flatClose, 1_000_000);
        $date = $date->modify('+1 day');

        $future = $flatClose * (1 + ($forwardMovePercent / 100));
        $delta = ($future - $flatClose) / self::HORIZON_DAYS;

        for ($i = 1; $i <= self::HORIZON_DAYS; $i++) {
            $close = $i === self::HORIZON_DAYS ? $future : $flatClose + ($i * $delta);
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }

    /**
     * `Company::getTicker()` es la clave que usa `fundamentalsAt()`, ver el
     * mismo comentario en `BacktestingServiceMomentumModeTest::stockFor()`.
     */
    private function stockFor(string $ticker, string $sector, Fundamentals $fundamentals): Stock
    {
        return new Stock(
            new Company($ticker, 'Test Corp', $sector, 'software', 'NASDAQ', 'USD'),
            new Quote(100.0, 100.0, 100.0, 100.0, 100.0, 1_000_000, new DateTimeImmutable('2024-01-01')),
            $fundamentals
        );
    }

    private function boringFundamentals(): Fundamentals
    {
        return new Fundamentals(
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
        );
    }

    public function testMuestraSinFundamentalesPointInTimeQuedaExcluidaYElContadorLoRefleja(): void
    {
        $stocksByTicker = [];
        $historiesByTicker = [];
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();
        $tickers = [];

        // 9 tickers CON snapshot point-in-time, factores fundamentales
        // mediocres pero identicos entre si.
        for ($i = 0; $i < 9; $i++) {
            $ticker = sprintf('T%02d', $i);
            $tickers[] = $ticker;
            $stocksByTicker[$ticker] = $this->stockFor($ticker, 'tech', $this->boringFundamentals());
            $historiesByTicker[$ticker] = $this->historyWithForwardMove(100.0, 1.0);
            $fundamentalsHistory->withFundamentalsSnapshot($ticker, [
                'freeCashFlow' => 50_000_000.0,
                'marketCap' => 1_000_000_000.0,
                'evToEbitda' => 15.0,
                'roic' => 8.0,
                'operatingMargin' => 10.0,
                'debtToEquity' => 1.5,
                'earningsYield' => 0.02,
                'cashConversion' => 0.8,
            ]);
        }

        // NOPIT: SIN snapshot -> cae al fallback de "hoy", con factores
        // fundamentales SUPERIORES a los de todos los demas en los siete
        // campos. Si el filtro point-in-time no se aplicase, ganaria el
        // ranking sin discusion.
        $tickers[] = 'NOPIT';
        $stocksByTicker['NOPIT'] = $this->stockFor('NOPIT', 'tech', new Fundamentals(
            per: 10.0,
            peg: 0.5,
            roe: 25.0,
            roic: 40.0,
            eps: 5.0,
            marketCap: 1_000_000_000.0,
            debtToEquity: 0.05,
            freeCashFlow: 400_000_000.0,
            evToEbitda: 3.0,
            priceToBook: 1.0,
            dividendYield: null,
            payoutRatio: null,
            grossMargin: null,
            operatingMargin: 50.0,
            netMargin: null,
            revenueGrowth: null,
            currentRatio: null,
            dividendGrowth5y: null,
            earningsYield: 0.20,
            cashConversion: 2.0
        ));
        $historiesByTicker['NOPIT'] = $this->historyWithForwardMove(100.0, -50.0);

        $service = new BacktestingService(
            new PerTickerStockAndHistoryProvider($stocksByTicker, $historiesByTicker),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0)),
            fundamentalsHistory: $fundamentalsHistory
        );

        $result = $service->runCrossSectional($tickers, self::HORIZON_DAYS, self::STEP, 3, 'fundamental');

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(1, $result['samples_dropped_no_fundamentals_pit']);
        self::assertCount(3, $result['dates'][0]['top_tickers']);
        self::assertNotContains('NOPIT', $result['dates'][0]['top_tickers']);
    }

    /**
     * Caso calculable a mano (P3.3): un sector "tech" de 10 tickers, 9 de
     * ellos (`T00`..`T08`) con los siete factores fundamentales IDENTICOS
     * entre si (mediocres) y `WINNER` con los siete ESTRICTAMENTE mejores
     * (mayor FCF yield/earnings yield/ROIC/margen operativo/conversion de
     * caja, menor EV/EBITDA/deuda-patrimonio).
     *
     * `RelativeFundamentalScorer::percentileRank()` cuenta peers PEOR O
     * IGUAL que el valor evaluado, sobre el total de peers (9 en los dos
     * casos: 9 tickers menos el propio evaluado):
     *
     * - `WINNER`: los 9 peers (T00..T08) son peor en los siete factores ->
     *   percentil = 9/9*100 = 100 en cada uno -> `fundamental_score` = 100.
     * - `T00` (y el resto, por simetria): de sus 9 peers, 8 son iguales
     *   (cuentan como "peor o igual") y 1 (`WINNER`) es mejor (no cuenta)
     *   -> percentil = 8/9*100 = 88,8888... en cada uno de los siete
     *   factores -> `fundamental_score` = 88,8888...
     *
     * Diferencia de ~11,1 puntos en las tres familias por igual: muy por
     * encima del ruido de coma flotante, `WINNER` gana el top-1 sin
     * ambiguedad. `forward_return` de `WINNER` se fija en 42% exacto para
     * comprobar tambien que la muestra seleccionada es la correcta, no solo
     * que el nombre del ticker coincide.
     */
    public function testRankingPorFactoresFundamentalesEligeAlDeMejorPercentilEnLasTresFamilias(): void
    {
        $stocksByTicker = [];
        $historiesByTicker = [];
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();
        $tickers = [];

        for ($i = 0; $i < 9; $i++) {
            $ticker = sprintf('T%02d', $i);
            $tickers[] = $ticker;
            $stocksByTicker[$ticker] = $this->stockFor($ticker, 'tech', $this->boringFundamentals());
            $historiesByTicker[$ticker] = $this->historyWithForwardMove(100.0, 1.0);
            $fundamentalsHistory->withFundamentalsSnapshot($ticker, [
                'freeCashFlow' => 50_000_000.0,
                'marketCap' => 1_000_000_000.0,
                'evToEbitda' => 15.0,
                'roic' => 8.0,
                'operatingMargin' => 10.0,
                'debtToEquity' => 1.5,
                'earningsYield' => 0.02,
                'cashConversion' => 0.8,
            ]);
        }

        $tickers[] = 'WINNER';
        $stocksByTicker['WINNER'] = $this->stockFor('WINNER', 'tech', $this->boringFundamentals());
        $historiesByTicker['WINNER'] = $this->historyWithForwardMove(100.0, 42.0);
        $fundamentalsHistory->withFundamentalsSnapshot('WINNER', [
            'freeCashFlow' => 200_000_000.0,
            'marketCap' => 1_000_000_000.0,
            'evToEbitda' => 5.0,
            'roic' => 25.0,
            'operatingMargin' => 30.0,
            'debtToEquity' => 0.2,
            'earningsYield' => 0.10,
            'cashConversion' => 1.2,
        ]);

        $service = new BacktestingService(
            new PerTickerStockAndHistoryProvider($stocksByTicker, $historiesByTicker),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0)),
            fundamentalsHistory: $fundamentalsHistory
        );

        $result = $service->runCrossSectional($tickers, self::HORIZON_DAYS, self::STEP, 1, 'fundamental');

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(0, $result['samples_dropped_no_fundamentals_pit']);
        self::assertSame(10, $result['dates'][0]['universe_size']);
        self::assertSame(['WINNER'], $result['dates'][0]['top_tickers']);
        self::assertSame(42.0, $result['dates'][0]['top_avg_forward_return']);
    }
}
