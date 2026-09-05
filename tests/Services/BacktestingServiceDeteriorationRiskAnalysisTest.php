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
 * E1 ("Deterioro fundamental", `PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`
 * Bloque E): cubre `BacktestingService::runDeteriorationRiskAnalysis()`, la
 * pregunta de RIESGO DE COLA (proporcion de retornos por debajo de un
 * umbral fijo, pareada por fecha) entre el grupo `deterioro=true` y el
 * universo elegible completo -- arquitectonicamente distinta de
 * `runCrossSectional()` (top-N por puntuacion), aunque reutiliza su misma
 * infraestructura (`collectSamplesWithHistory()`, calendario de sesiones,
 * filtro point-in-time real).
 */
final class BacktestingServiceDeteriorationRiskAnalysisTest extends TestCase
{
    private const HORIZON_DAYS = 5;
    private const STEP = 5;

    /**
     * Copia deliberada de `BacktestingServiceFundamentalModeTest::historyWithForwardMove()`
     * (mismo criterio de "cada test calcula su propio fixture" que
     * `median()` duplicado en varias clases del proyecto): 81 velas planas
     * + entrada (P0.1) + horizonte interpolado hasta `$forwardMovePercent`
     * EXACTO. Con `$minimumLookback=80` y `$count - $horizonDays - 1` como
     * tope de `sampleHistory()`, solo el indice 80 entra en el bucle: una
     * unica fecha de señal por ticker, en `self::signalDate()`.
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
     * Unica fecha de señal que genera `historyWithForwardMove()` (indice
     * 80 del historico, ver su docblock): `2024-01-01` + 80 dias.
     */
    private function signalDate(): DateTimeImmutable
    {
        return (new DateTimeImmutable('2024-01-01'))->modify('+80 days');
    }

    private function yearAgoDate(): DateTimeImmutable
    {
        return $this->signalDate()->modify('-365 days');
    }

    private function stockFor(string $ticker, Fundamentals $fundamentals): Stock
    {
        return new Stock(
            new Company($ticker, 'Test Corp', 'tech', 'software', 'NASDAQ', 'USD'),
            new Quote(100.0, 100.0, 100.0, 100.0, 100.0, 1_000_000, new DateTimeImmutable('2024-01-01')),
            $fundamentals
        );
    }

    private function service(
        array $stocksByTicker,
        array $historiesByTicker,
        InMemoryFundamentalsHistoryRepository $fundamentalsHistory,
        ?\StockAnalyzer\Interfaces\IndexMembershipCheckerInterface $indexMembership = null
    ): BacktestingService {
        return new BacktestingService(
            new PerTickerStockAndHistoryProvider($stocksByTicker, $historiesByTicker),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0)),
            fundamentalsHistory: $fundamentalsHistory,
            indexMembership: $indexMembership
        );
    }

    /**
     * Fundamentales cualquiera para `Stock` (los valores REALES que rankea
     * el metodo vienen siempre del snapshot point-in-time, nunca de este
     * objeto "de hoy" -- por eso da igual que sean identicos para todos los
     * tickers).
     */
    private function placeholderFundamentals(): Fundamentals
    {
        return new Fundamentals(
            per: 10.0,
            peg: 0.5,
            roe: 25.0,
            roic: null,
            eps: 5.0,
            marketCap: 1_000_000_000.0,
            debtToEquity: 0.1,
            freeCashFlow: 100_000_000.0
        );
    }

    /**
     * Grupo `deterioro=true` (un unico ticker, con evento de cola) contra
     * un universo elegible de 3: proporcion de cola 100% en el grupo
     * deterioro frente a 33,33% en el universo completo (que INCLUYE al
     * propio ticker deteriorado, mismo criterio "top vs eligible" que
     * `runCrossSectional()`).
     */
    public function testGrupoDeterioroConEventoDeColaFrenteAlUniversoElegibleCompleto(): void
    {
        $signalDate = $this->signalDate()->format('Y-m-d');
        $yearAgoDate = $this->yearAgoDate()->format('Y-m-d');

        $stocksByTicker = [];
        $historiesByTicker = [];
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();

        foreach (['T01', 'T02'] as $ticker) {
            $stocksByTicker[$ticker] = $this->stockFor($ticker, $this->placeholderFundamentals());
            $historiesByTicker[$ticker] = $this->historyWithForwardMove(100.0, 1.0);
            $fields = [
                'operatingMargin' => 10.0,
                'roic' => 8.0,
                'debtToEquity' => 1.0,
                'freeCashFlow' => 50_000_000.0,
                'marketCap' => 1_000_000_000.0,
            ];
            $fundamentalsHistory->withFundamentalsSnapshotAt($ticker, $signalDate, $fields);
            $fundamentalsHistory->withFundamentalsSnapshotAt($ticker, $yearAgoDate, $fields);
        }

        // DETERIORATING: margen, ROIC y FCF yield bajan los tres frente a
        // hace un año (clausula A), y ademas cae un -20% (evento de cola
        // por debajo del umbral por defecto de -10%).
        $stocksByTicker['DETERIORATING'] = $this->stockFor('DETERIORATING', $this->placeholderFundamentals());
        $historiesByTicker['DETERIORATING'] = $this->historyWithForwardMove(100.0, -20.0);
        $fundamentalsHistory->withFundamentalsSnapshotAt('DETERIORATING', $signalDate, [
            'operatingMargin' => 6.0,
            'roic' => 4.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 10_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);
        $fundamentalsHistory->withFundamentalsSnapshotAt('DETERIORATING', $yearAgoDate, [
            'operatingMargin' => 12.0,
            'roic' => 10.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 50_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);

        $result = $this->service($stocksByTicker, $historiesByTicker, $fundamentalsHistory)
            ->runDeteriorationRiskAnalysis(['T01', 'T02', 'DETERIORATING'], self::HORIZON_DAYS, self::STEP);

        self::assertSame([], $result['errors']);
        self::assertSame(0, $result['samples_dropped_no_fundamentals_pit']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(1.0, $result['avg_deteriorating_tickers_per_date']);
        self::assertSame(3.0, $result['avg_universe_size_per_date']);

        $day = $result['dates'][0];
        self::assertSame(3, $day['universe_size']);
        self::assertSame(1, $day['deteriorating_count']);
        self::assertSame(100.0, $day['deterioration_tail_rate']);
        self::assertEqualsWithDelta(33.33, $day['universe_tail_rate'], 0.01);
        self::assertEqualsWithDelta(66.67, $day['tail_rate_diff'], 0.01);

        self::assertEqualsWithDelta(-20.0, $result['p10_deteriorating'], 0.01);
    }

    /**
     * Un ticker con TTM actual point-in-time real pero SIN snapshot de
     * hace un año (`findAsOf()` de hace 365 dias devuelve null) nunca
     * puede marcarse `deterioro=true` -- pero SIGUE en el universo
     * elegible, con su retorno real contando en la proporcion de cola del
     * universo (aunque el, por si solo, tambien sea un evento de cola).
     */
    public function testTickerSinSnapshotDeHaceUnAñoNoSeMarcaPeroSigueEnElUniverso(): void
    {
        $signalDate = $this->signalDate()->format('Y-m-d');
        $yearAgoDate = $this->yearAgoDate()->format('Y-m-d');

        $stocksByTicker = [];
        $historiesByTicker = [];
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();

        foreach (['T01', 'T02'] as $ticker) {
            $stocksByTicker[$ticker] = $this->stockFor($ticker, $this->placeholderFundamentals());
            $historiesByTicker[$ticker] = $this->historyWithForwardMove(100.0, 1.0);
            $fields = [
                'operatingMargin' => 10.0,
                'roic' => 8.0,
                'debtToEquity' => 1.0,
                'freeCashFlow' => 50_000_000.0,
                'marketCap' => 1_000_000_000.0,
            ];
            $fundamentalsHistory->withFundamentalsSnapshotAt($ticker, $signalDate, $fields);
            $fundamentalsHistory->withFundamentalsSnapshotAt($ticker, $yearAgoDate, $fields);
        }

        $stocksByTicker['DETERIORATING'] = $this->stockFor('DETERIORATING', $this->placeholderFundamentals());
        $historiesByTicker['DETERIORATING'] = $this->historyWithForwardMove(100.0, -20.0);
        $fundamentalsHistory->withFundamentalsSnapshotAt('DETERIORATING', $signalDate, [
            'operatingMargin' => 6.0,
            'roic' => 4.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 10_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);
        $fundamentalsHistory->withFundamentalsSnapshotAt('DETERIORATING', $yearAgoDate, [
            'operatingMargin' => 12.0,
            'roic' => 10.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 50_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);

        // NOHIST: TTM actual point-in-time real (registrado en signalDate),
        // pero SIN ningun snapshot de hace un año -> findAsOf() de esa
        // fecha no encuentra nada anterior o igual y devuelve null.
        $stocksByTicker['NOHIST'] = $this->stockFor('NOHIST', $this->placeholderFundamentals());
        $historiesByTicker['NOHIST'] = $this->historyWithForwardMove(100.0, -50.0);
        $fundamentalsHistory->withFundamentalsSnapshotAt('NOHIST', $signalDate, [
            'operatingMargin' => 1.0,
            'roic' => 1.0,
            'debtToEquity' => 5.0,
            'freeCashFlow' => -10_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);

        $result = $this->service($stocksByTicker, $historiesByTicker, $fundamentalsHistory)
            ->runDeteriorationRiskAnalysis(['T01', 'T02', 'DETERIORATING', 'NOHIST'], self::HORIZON_DAYS, self::STEP);

        self::assertSame([], $result['errors']);
        self::assertSame(0, $result['samples_dropped_no_fundamentals_pit']);
        self::assertSame(1, $result['dates_evaluated']);

        $day = $result['dates'][0];
        // NOHIST cuenta en la amplitud del universo...
        self::assertSame(4, $day['universe_size']);
        // ... pero NUNCA en el grupo deterioro, aunque su TTM "actual" sea
        // pesimo y su retorno tambien sea un evento de cola.
        self::assertSame(1, $day['deteriorating_count']);
        self::assertSame(100.0, $day['deterioration_tail_rate']);
        // Cola del universo: DETERIORATING (-20%) y NOHIST (-50%) por
        // debajo de -10%, T01/T02 no -> 2/4 = 50%.
        self::assertSame(50.0, $day['universe_tail_rate']);
    }

    /**
     * Sin fundamentales point-in-time reales para el TTM actual, la
     * muestra no puede ni siquiera entrar en el universo elegible (mismo
     * criterio que el paso (a) de `rankByFundamentalNeutral()`).
     */
    public function testMuestraSinFundamentalesPointInTimeQuedaExcluidaDelUniverso(): void
    {
        $signalDate = $this->signalDate()->format('Y-m-d');
        $yearAgoDate = $this->yearAgoDate()->format('Y-m-d');

        $stocksByTicker = [];
        $historiesByTicker = [];
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();

        $stocksByTicker['T01'] = $this->stockFor('T01', $this->placeholderFundamentals());
        $historiesByTicker['T01'] = $this->historyWithForwardMove(100.0, 1.0);
        $fundamentalsHistory->withFundamentalsSnapshotAt('T01', $signalDate, [
            'operatingMargin' => 10.0,
            'roic' => 8.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 50_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);
        $fundamentalsHistory->withFundamentalsSnapshotAt('T01', $yearAgoDate, [
            'operatingMargin' => 10.0,
            'roic' => 8.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 50_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);

        $stocksByTicker['DETERIORATING'] = $this->stockFor('DETERIORATING', $this->placeholderFundamentals());
        $historiesByTicker['DETERIORATING'] = $this->historyWithForwardMove(100.0, -20.0);
        $fundamentalsHistory->withFundamentalsSnapshotAt('DETERIORATING', $signalDate, [
            'operatingMargin' => 6.0,
            'roic' => 4.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 10_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);
        $fundamentalsHistory->withFundamentalsSnapshotAt('DETERIORATING', $yearAgoDate, [
            'operatingMargin' => 12.0,
            'roic' => 10.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 50_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);

        // NOPIT: nunca registrado en el repositorio -> findAsOf() siempre
        // null -> cae al fallback de "hoy" -> fundamentals_is_point_in_time
        // = false.
        $stocksByTicker['NOPIT'] = $this->stockFor('NOPIT', $this->placeholderFundamentals());
        $historiesByTicker['NOPIT'] = $this->historyWithForwardMove(100.0, 1.0);

        $result = $this->service($stocksByTicker, $historiesByTicker, $fundamentalsHistory)
            ->runDeteriorationRiskAnalysis(['T01', 'DETERIORATING', 'NOPIT'], self::HORIZON_DAYS, self::STEP);

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['samples_dropped_no_fundamentals_pit']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(2, $result['dates'][0]['universe_size']);
    }

    public function testPasoMenorQueHorizonteLanzaExcepcion(): void
    {
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();
        $service = $this->service([], [], $fundamentalsHistory);

        $this->expectException(\InvalidArgumentException::class);
        $service->runDeteriorationRiskAnalysis(['T01'], 20, 5);
    }

    /**
     * Sin `FundamentalsHistoryRepository` conectado no hay forma de
     * consultar el TTM de hace un año -- mitad de la comparacion -- asi que
     * el metodo no puede degradarse en silencio como si midiera algo:
     * lanza en vez de devolver un resultado vacio que parezca valido.
     */
    public function testSinFundamentalsHistoryConectadoLanzaExcepcion(): void
    {
        $service = new BacktestingService(
            new PerTickerStockAndHistoryProvider([], []),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0))
        );

        $this->expectException(\LogicException::class);
        $service->runDeteriorationRiskAnalysis(['T01'], self::HORIZON_DAYS, self::STEP);
    }

    /**
     * Filtro point-in-time de universo (mismo mecanismo que
     * `runCrossSectional()`, ver `BacktestingServicePointInTimeUniverseTest`):
     * un ticker que ya no era miembro del indice en la fecha de señal se
     * descarta antes incluso de mirar sus fundamentales.
     */
    public function testFiltroDeMembresiaPointInTimeDescartaTickersQueYaNoEranMiembros(): void
    {
        $signalDate = $this->signalDate()->format('Y-m-d');
        $yearAgoDate = $this->yearAgoDate()->format('Y-m-d');

        $stocksByTicker = [];
        $historiesByTicker = [];
        $fundamentalsHistory = new InMemoryFundamentalsHistoryRepository();

        foreach (['T01', 'T02'] as $ticker) {
            $stocksByTicker[$ticker] = $this->stockFor($ticker, $this->placeholderFundamentals());
            $historiesByTicker[$ticker] = $this->historyWithForwardMove(100.0, 1.0);
            $fields = [
                'operatingMargin' => 10.0,
                'roic' => 8.0,
                'debtToEquity' => 1.0,
                'freeCashFlow' => 50_000_000.0,
                'marketCap' => 1_000_000_000.0,
            ];
            $fundamentalsHistory->withFundamentalsSnapshotAt($ticker, $signalDate, $fields);
            $fundamentalsHistory->withFundamentalsSnapshotAt($ticker, $yearAgoDate, $fields);
        }

        $stocksByTicker['DETERIORATING'] = $this->stockFor('DETERIORATING', $this->placeholderFundamentals());
        $historiesByTicker['DETERIORATING'] = $this->historyWithForwardMove(100.0, -20.0);
        $fundamentalsHistory->withFundamentalsSnapshotAt('DETERIORATING', $signalDate, [
            'operatingMargin' => 6.0,
            'roic' => 4.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 10_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);
        $fundamentalsHistory->withFundamentalsSnapshotAt('DETERIORATING', $yearAgoDate, [
            'operatingMargin' => 12.0,
            'roic' => 10.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 50_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);

        // SALIENTE dejo el indice antes de la fecha de señal: nunca debe
        // contar en el universo, ni siquiera para llegar a mirar si
        // deterioro.
        $stocksByTicker['SALIENTE'] = $this->stockFor('SALIENTE', $this->placeholderFundamentals());
        $historiesByTicker['SALIENTE'] = $this->historyWithForwardMove(100.0, 1.0);
        $fundamentalsHistory->withFundamentalsSnapshotAt('SALIENTE', $signalDate, [
            'operatingMargin' => 10.0,
            'roic' => 8.0,
            'debtToEquity' => 1.0,
            'freeCashFlow' => 50_000_000.0,
            'marketCap' => 1_000_000_000.0,
        ]);

        $indexMembership = new ArrayIndexMembershipChecker([
            ['ticker' => 'T01', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => null],
            ['ticker' => 'T02', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => null],
            ['ticker' => 'DETERIORATING', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => null],
            ['ticker' => 'SALIENTE', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => $this->signalDate()->modify('-1 day')->format('Y-m-d')],
        ]);

        $result = $this->service($stocksByTicker, $historiesByTicker, $fundamentalsHistory, $indexMembership)
            ->runDeteriorationRiskAnalysis(['T01', 'T02', 'DETERIORATING', 'SALIENTE'], self::HORIZON_DAYS, self::STEP, 'GSPC');

        self::assertTrue($result['point_in_time_universe']);
        self::assertSame('GSPC', $result['index_code']);
        self::assertSame(1, $result['samples_dropped_not_member']);
        self::assertSame(3, $result['dates'][0]['universe_size']);
    }
}
