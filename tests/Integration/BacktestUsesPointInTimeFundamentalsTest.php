<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\DividendGrowthCalculator;
use StockAnalyzer\Services\RiskLevelsCalculator;
use StockAnalyzer\Tests\Services\FixedHistoryProvider;
use StockAnalyzer\Tests\Services\SyntheticStock;

/**
 * Que `findAsOf()` devuelva lo correcto no basta: hay que demostrar que el
 * backtest **lo usa**, y que dice honestamente cuanto de el se apoya en
 * datos de la fecha y cuanto en los de hoy (`v2.91`).
 *
 * Es la diferencia entre haber arreglado el sesgo de anticipacion y creer
 * haberlo arreglado. El fallo que estos casos vigilan no da ningun error:
 * el backtest sigue funcionando y sale con mejor pinta de la que le
 * corresponde.
 *
 * El historico sintetico y el Stock son los mismos que usa
 * `BacktestingServiceTest` (81 velas alcistas, fundamentales excelentes),
 * para no inventar un segundo escenario que habria que mantener aparte.
 */
final class BacktestUsesPointInTimeFundamentalsTest extends IntegrationTestCase
{
    private const TICKER = 'TST';
    private const ENTRY_INDEX = 80;

    private FundamentalsHistoryRepository $history;

    protected function setUp(): void
    {
        parent::setUp();

        $this->history = new FundamentalsHistoryRepository($this->connection());
    }

    /**
     * @return list<HistoricalQuote>
     */
    private function quotes(): array
    {
        $quotes = [];
        $close = 100.0;
        $date = new DateTimeImmutable('2024-01-01');

        for ($i = 0; $i <= self::ENTRY_INDEX + 20; $i++) {
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
            $close += 0.05;
        }

        return $quotes;
    }

    private function service(bool $conHistorico): BacktestingService
    {
        return new BacktestingService(
            new FixedHistoryProvider(SyntheticStock::create(), $this->quotes()),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0)),
            new DividendGrowthCalculator(),
            new BacktestingConfig(10.0),
            $conHistorico ? $this->history : null
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function backtest(bool $conHistorico): array
    {
        /** @var array<string,mixed> $result */
        $result = $this->service($conHistorico)->run([self::TICKER], 10, 5);

        /** @var list<array<string,mixed>> $results */
        $results = $result['results'];

        return $results[0];
    }

    /**
     * Fundamentales pesimos: PER y EV/EBITDA por las nubes, margenes y ROE
     * por los suelos. Frente a los excelentes de `SyntheticStock`, tienen
     * que mover la puntuacion de forma inequivoca.
     */
    private function malos(): Fundamentals
    {
        return new Fundamentals(
            per: 95.0,
            peg: 6.0,
            roe: 1.0,
            roic: 0.5,
            eps: 0.1,
            marketCap: 1_000_000_000.0,
            debtToEquity: 4.5,
            freeCashFlow: -50_000_000.0,
            evToEbitda: 60.0,
            priceToBook: 12.0,
            dividendYield: null,
            payoutRatio: null,
            grossMargin: 5.0,
            operatingMargin: 1.0,
            netMargin: 0.5,
            revenueGrowth: -20.0,
            currentRatio: 0.4
        );
    }

    /**
     * Sin repositorio conectado el comportamiento es exactamente el de
     * antes de `v2.91`, y la cobertura es `null` (no se llego a preguntar),
     * que no es lo mismo que 0,0.
     */
    public function testSinRepositorioSeComportaComoAntesYNoInventaCobertura(): void
    {
        $resultado = $this->backtest(false);

        self::assertNull($resultado['fundamentals_point_in_time_pct']);
        self::assertGreaterThan(0, $resultado['samples']);
    }

    /**
     * Con repositorio pero sin ningun snapshot, la cobertura es 0,0: se
     * pregunto y no habia nada. Las muestras siguen saliendo (se cae en los
     * fundamentales de hoy) en vez de quedarse sin backtest.
     */
    public function testConRepositorioVacioLaCoberturaEsCeroYNoSePierdenMuestras(): void
    {
        $sinRepo = $this->backtest(false);
        $conRepo = $this->backtest(true);

        self::assertSame(0.0, $conRepo['fundamentals_point_in_time_pct']);
        self::assertSame($sinRepo['samples'], $conRepo['samples'], 'El fallback no descarta muestras.');
        self::assertSame($sinRepo['buy_signals'], $conRepo['buy_signals']);
    }

    /**
     * El caso que demuestra que el arreglo sirve: con snapshots que cubren
     * todo el recorrido, la cobertura sube al 100%.
     */
    public function testConSnapshotsQueCubrenTodoElRecorridoLaCoberturaEsTotal(): void
    {
        // Un snapshot anterior a la primera fecha muestreada basta: findAsOf
        // devuelve el mas reciente en esa fecha o antes.
        $this->history->recordSnapshot(self::TICKER, $this->malos(), new DateTimeImmutable('2023-12-01'));

        $resultado = $this->backtest(true);

        self::assertSame(100.0, $resultado['fundamentals_point_in_time_pct']);
    }

    /**
     * Y que los datos usados son los del snapshot y no los de hoy: con
     * fundamentales pesimos en el historico, las señales BUY que producian
     * los excelentes de hoy desaparecen.
     */
    public function testLosFundamentalesDelSnapshotCambianElResultadoDelBacktest(): void
    {
        // En feature/solo-tecnico FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND
        // pesan 0 (ver ScoreCategory::maxScore()): la premisa de este test
        // —que los fundamentales cambian el resultado— es falsa a
        // proposito en esta rama, no un fallo. Se salta en vez de borrarse
        // ni forzarse a pasar de otra manera: en cuanto los pesos vuelvan a
        // ser positivos (la rama se abandone o se fusione con el bloque
        // fundamental reactivado), vuelve a ejecutarse sin tocar nada mas.
        self::markTestSkipped(
            'feature/solo-tecnico: FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND pesan 0, asi que este test no aplica mientras dure la rama.'
        );

        $conLosDeHoy = $this->backtest(true);

        $this->history->recordSnapshot(self::TICKER, $this->malos(), new DateTimeImmutable('2023-12-01'));
        $conLosDeSuFecha = $this->backtest(true);

        self::assertGreaterThan(
            0,
            $conLosDeHoy['buy_signals'],
            'Con los fundamentales excelentes de hoy el escenario genera compras.'
        );
        self::assertSame(
            0,
            $conLosDeSuFecha['buy_signals'],
            'Con los fundamentales reales de aquella fecha, esas compras no existian.'
        );
    }

    /**
     * Cobertura parcial: un snapshot que solo cubre la segunda mitad del
     * recorrido tiene que dar un porcentaje estrictamente entre 0 y 100. Es
     * el caso real de los proximos meses —serie recien empezada— y el que
     * hace imprescindible publicar la cifra: sin ella, un backtest con el
     * 2% de cobertura se lee igual que uno con el 100%.
     */
    public function testLaCoberturaParcialSeReportaComoTal(): void
    {
        // El muestreo no empieza en la primera vela: BacktestingService exige
        // 80 sesiones de calentamiento. Con horizonte 10 y paso 5, las
        // muestras caen en los indices 80, 85 y 90, asi que un snapshot
        // fechado en el 85 deja la primera fuera y cubre las otras dos.
        $quotes = $this->quotes();
        $this->history->recordSnapshot(self::TICKER, $this->malos(), $quotes[85]->getDate());

        $cobertura = $this->backtest(true)['fundamentals_point_in_time_pct'];

        self::assertIsFloat($cobertura);
        self::assertGreaterThan(0.0, $cobertura);
        self::assertLessThan(100.0, $cobertura);
    }

    /**
     * La cobertura es de cada ticker, no un acumulado de la ejecucion: los
     * contadores se reinician en cada `backtestTicker()`. Sin eso, el
     * segundo ticker heredaria el porcentaje del primero.
     */
    public function testLaCoberturaSeCalculaPorTickerYNoSeAcumula(): void
    {
        $this->history->recordSnapshot('TST', $this->malos(), new DateTimeImmutable('2023-12-01'));

        /** @var array<string,mixed> $result */
        $result = $this->service(true)->run(['TST', 'TST'], 10, 5);
        /** @var list<array<string,mixed>> $results */
        $results = $result['results'];

        self::assertCount(2, $results);
        self::assertSame(100.0, $results[0]['fundamentals_point_in_time_pct']);
        self::assertSame(
            100.0,
            $results[1]['fundamentals_point_in_time_pct'],
            'El segundo ticker no arrastra los contadores del primero.'
        );
    }
}
