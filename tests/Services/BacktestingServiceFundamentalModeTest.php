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
 * `REVISION_MOTOR_CODEX_2026-09-02.md`, seccion P3.3, con las correcciones
 * de `roadmap.md` "Prioridad cero-ter" del `2026-09-04`): el ranking de cada
 * fecha pasa a ser por `fundamental_score` (media de las tres familias
 * Valor/Calidad/Solidez -- CINCO factores desde el `2026-09-04`, no siete,
 * ver `BacktestingService::FUNDAMENTAL_FACTORS` --, cada una puntuada por
 * percentil dentro del propio sector el mismo dia) en vez de por
 * `percentage` (el score de 50 puntos) o por Momentum 12-1
 * (`mode='momentum'`, P3.4).
 *
 * A diferencia de `BacktestingServiceMomentumModeTest`, aqui NINGUN fixture
 * usa el prefijo largo de `MomentumPrefixFixture`: desde el `2026-09-04`
 * (punto 2 de "Prioridad cero-ter") `mode='fundamental'` ya NO exige
 * `momentum12m1` no-nulo, asi que un historico corto (sin los ~250 cierres
 * que Momentum 12-1 necesita) basta y ademas es el caso que hay que probar
 * -- con el prefijo largo, cada indice del bucle de `sampleHistory()` ANTES
 * de alcanzar esos ~250 cierres generaba una muestra adicional (momentum
 * null, pero ya no descartada), inflando `dates_evaluated` muy por encima
 * de la unica fecha de señal que el test quiere aislar. `historyWithForwardMove()`
 * deja `forward_return` en un valor EXACTO conocido en su unica fecha.
 */
final class BacktestingServiceFundamentalModeTest extends TestCase
{
    private const HORIZON_DAYS = 5;
    private const STEP = 5;

    /**
     * Historico CORTO de UN ticker (sin prefijo de `MomentumPrefixFixture`:
     * ver el docblock de la clase) con `forward_return` EXACTO en su unica
     * fecha de señal. 81 velas planas + entrada (P0.1) + horizonte
     * interpolado: con `$minimumLookback=80` y `$count - $horizonDays - 1`
     * como tope, solo el indice 80 cae dentro del bucle de `sampleHistory()`,
     * una unica muestra por ticker.
     *
     * @return list<HistoricalQuote>
     */
    private function historyWithForwardMove(float $flatClose, float $forwardMovePercent): array
    {
        $date = new DateTimeImmutable('2024-01-01');
        $quotes = [];

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
        self::assertSame(0, $result['samples_dropped_no_usable_factors']);
        self::assertCount(3, $result['dates'][0]['top_tickers']);
        self::assertNotContains('NOPIT', $result['dates'][0]['top_tickers']);
    }

    /**
     * Caso calculable a mano (P3.3, recalculado el `2026-09-04` para CINCO
     * factores tras "Prioridad cero-ter" punto 1): un sector "tech" de 10
     * tickers, 9 de ellos (`T00`..`T08`) con los cinco factores
     * fundamentales que SI participan en el ranking IDENTICOS entre si
     * (mediocres) y `WINNER` con los cinco ESTRICTAMENTE mejores (mayor FCF
     * yield/ROIC/margen operativo, menor EV/EBITDA/deuda-patrimonio). Los
     * fixtures siguen fijando tambien `earningsYield`/`cashConversion`
     * (ya no leidos por `FUNDAMENTAL_FACTORS`) para comprobar que su
     * presencia no cambia el resultado.
     *
     * `RelativeFundamentalScorer::percentileRank()` cuenta peers PEOR O
     * IGUAL que el valor evaluado, sobre el total de peers (9 en los dos
     * casos: 9 tickers menos el propio evaluado):
     *
     * - `WINNER`: los 9 peers (T00..T08) son peor en los cinco factores ->
     *   percentil = 9/9*100 = 100 en cada uno -> `fundamental_score` = 100.
     * - `T00` (y el resto, por simetria): de sus 9 peers, 8 son iguales
     *   (cuentan como "peor o igual") y 1 (`WINNER`) es mejor (no cuenta)
     *   -> percentil = 8/9*100 = 88,8888... en cada uno de los cinco
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
        self::assertSame(0, $result['samples_dropped_no_usable_factors']);
        self::assertSame(10, $result['dates'][0]['universe_size']);
        self::assertSame(['WINNER'], $result['dates'][0]['top_tickers']);
        self::assertSame(42.0, $result['dates'][0]['top_avg_forward_return']);
    }

    /**
     * roadmap.md, "Prioridad cero-ter" punto 1 (2026-09-04): una muestra con
     * fundamentales point-in-time reales (`fundamentals_is_point_in_time`)
     * pero SIN dato en ninguno de los cinco factores (`NODATA`, snapshot
     * vacio -> los cinco campos llegan `null` via
     * `FundamentalsHistoryRepository::fromArray()`) no puede aportar ninguna
     * `valor_familia`: se excluye de `eligible` (no solo de `selected`) y
     * queda contabilizada en `samples_dropped_no_usable_factors`, NO en
     * `samples_dropped_no_fundamentals_pit` (esa cuenta solo faltas de
     * point-in-time, no de dato utilizable).
     */
    public function testMuestraSinFactoresUtilizablesQuedaExcluidaDelElegibleYElContadorLoRefleja(): void
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
            ]);
        }

        // NODATA: snapshot VACIO -> fundamentals_is_point_in_time = true
        // (hubo snapshot, findAsOf() no devolvio null), pero los cinco
        // campos que participan en el ranking llegan null.
        $tickers[] = 'NODATA';
        $stocksByTicker['NODATA'] = $this->stockFor('NODATA', 'tech', $this->boringFundamentals());
        $historiesByTicker['NODATA'] = $this->historyWithForwardMove(100.0, 99.0);
        $fundamentalsHistory->withFundamentalsSnapshot('NODATA', []);

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
        self::assertSame(0, $result['samples_dropped_no_fundamentals_pit']);
        self::assertSame(1, $result['samples_dropped_no_usable_factors']);
        self::assertSame(10, $result['dates'][0]['universe_size']);
        self::assertCount(3, $result['dates'][0]['top_tickers']);
        self::assertNotContains('NODATA', $result['dates'][0]['top_tickers']);
    }

    /**
     * roadmap.md, "Prioridad cero-ter" punto 2 (2026-09-04): con menos de
     * 251 cierres, `TechnicalAnalyzer::momentumSkippingRecent()` devuelve
     * `null` (`getMomentum12m1() === null`) -- y el historico corto de
     * `historyWithForwardMove()` (81 velas) esta muy por debajo. Antes de
     * esta correccion `sampleHistory()` descartaba la muestra entera (P0.3)
     * para CUALQUIER modo, incluido 'fundamental', que nunca lee
     * momentum12m1.
     */
    public function testMuestraSinHistoricoSuficienteParaMomentumNoSeDescartaEnModoFundamental(): void
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
        // La pieza central de este test: CERO muestras descartadas por
        // momentum nulo, aunque los 87 velas de cada historico estan muy
        // por debajo de las >250 que exige Momentum 12-1.
        self::assertSame(0, $result['samples_dropped_momentum_null']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(['WINNER'], $result['dates'][0]['top_tickers']);
        self::assertSame(42.0, $result['dates'][0]['top_avg_forward_return']);
    }

    /**
     * roadmap.md, "Prioridad cero-ter" punto 5 (2026-09-04): la alpha
     * PRINCIPAL de cada fecha se mide contra el subconjunto ELEGIBLE (los
     * que sobrevivieron a `rankByFundamentalNeutral()`), no contra
     * `$daySamples` completo. `OUTLIER` no tiene snapshot point-in-time
     * (`fundamentals_is_point_in_time = false`), asi que NUNCA pudo competir
     * por el top-N -- pero su `forward_return` extremo (-100%) SI cuenta en
     * el universo completo. Los 9 tickers elegibles tienen fundamentales
     * IDENTICOS y `forward_return` = 1,0% exacto cada uno, asi que
     * cualquier top-3 que se elija entre ellos promedia tambien 1,0% exacto:
     * la alpha principal (contra elegibles) sale 0,0 EXACTA, mientras que la
     * alpha contra el universo completo se desplaza mucho por `OUTLIER`.
     */
    public function testAlphaSeMideContraElUniversoElegibleNoContraTodosLosDisponibles(): void
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
            ]);
        }

        // OUTLIER: SIN snapshot -> nunca elegible, pero su retorno extremo
        // participa en $daySamples completo.
        $tickers[] = 'OUTLIER';
        $stocksByTicker['OUTLIER'] = $this->stockFor('OUTLIER', 'tech', $this->boringFundamentals());
        $historiesByTicker['OUTLIER'] = $this->historyWithForwardMove(100.0, -100.0);

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
        self::assertSame(10, $result['dates'][0]['universe_size']);
        self::assertNotContains('OUTLIER', $result['dates'][0]['top_tickers']);

        self::assertSame(1.0, $result['dates'][0]['top_avg_forward_return']);
        self::assertSame(1.0, $result['dates'][0]['universe_avg_forward_return']);
        self::assertSame(0.0, $result['dates'][0]['alpha']);

        // Diagnostico secundario: SI se mueve por OUTLIER.
        self::assertEqualsWithDelta(-9.1, $result['dates'][0]['universe_avg_forward_return_all_available'], 0.01);
        self::assertEqualsWithDelta(10.1, $result['dates'][0]['alpha_vs_all_available'], 0.01);
    }
}
