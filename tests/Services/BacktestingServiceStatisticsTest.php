<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\RiskLevelsCalculator;

/**
 * Cubre las dos capas de estadistica agregada de BacktestingService, ambas
 * observabilidad pura (no cambian ninguna recomendacion real):
 *
 * - v2.58, significancia estadistica por ticker: `buy_return_stddev`,
 *   `buy_return_stderr`, `buy_return_ci95_low`/`_high`, `buy_alpha_stderr`
 *   (Welch) y `buy_alpha_t_stat`.
 * - v2.59, agregado por universo y episodios de mercado: bloque `aggregate`
 *   de `run()` y campo `buy_samples` por ticker que lo alimenta.
 *
 * Igual que `BacktestingServiceTest`, no se usa ningun doble de
 * TechnicalAnalyzer/ScoreCalculator: se usan las clases reales sobre
 * historicos sinteticos, y los valores esperados de cada test estan
 * calculados a mano (ver el comentario de cada caso) sobre los
 * `forward_return` que produce cada fixture, no copiados de la salida del
 * codigo bajo prueba.
 */
final class BacktestingServiceStatisticsTest extends TestCase
{
    private const HORIZON_DAYS = 5;
    private const STEP = 5;

    /**
     * @param array<string,list<HistoricalQuote>> $historiesByTicker
     */
    private function service(array $historiesByTicker): BacktestingService
    {
        return new BacktestingService(
            new PerTickerHistoryProvider(SyntheticStock::create(), $historiesByTicker),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0))
        );
    }

    /**
     * Historico sintetico: 81 velas de arranque (indices 0..80) con rango
     * diario constante R=1,0 e incremento de cierre constante d=+0,05 (el
     * mismo tramo alcista suave que `BacktestingServiceTest` documenta: en
     * el indice 80, el primero que muestrea `backtestTicker()`, el cierre es
     * exactamente 104,0 y la recomendacion BUY), seguidas de una vela por
     * cada incremento de `$increments`.
     *
     * @param list<float> $increments
     * @return list<HistoricalQuote>
     */
    private function history(string $startDate, array $increments, float $startClose = 100.0, float $baselineStep = 0.05): array
    {
        $quotes = [];
        $close = $startClose;
        $date = new DateTimeImmutable($startDate);

        for ($i = 0; $i < 81; $i++) {
            if ($i > 0) {
                $close += $baselineStep;
            }

            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        foreach ($increments as $increment) {
            $close += $increment;
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }

    /**
     * 111 velas: tras el tramo alcista de arranque, 15 dias de subidas de
     * ritmo variable y despues un desplome sostenido. Con horizonte=5/paso=5
     * produce 6 muestras, 4 de ellas BUY con `forward_return` distintos
     * entre si (+1,92 / +0,47 / +3,76 / -18,10) y 2 HOLD (-16,57 / -13,25),
     * que es justo lo que necesita el calculo de dispersion y de alpha: si
     * todas las muestras fuesen BUY, la alpha seria 0 por construccion.
     *
     * @return list<HistoricalQuote>
     */
    private function riseThenCrashHistory(string $startDate = '2024-01-01'): array
    {
        return $this->history($startDate, array_merge(
            array_fill(0, 5, 0.40),
            array_fill(0, 5, 0.10),
            array_fill(0, 5, 0.80),
            array_fill(0, 5, -4.0),
            array_fill(0, 5, -3.0),
            array_fill(0, 5, -2.0)
        ));
    }

    /**
     * 106 velas con el MISMO incremento constante de +0,05 durante todo el
     * recorrido: las 5 muestras son BUY y sus 5 `forward_return` valen
     * exactamente +0,24% (0,25 sobre un precio de ~104-105, redondeado a dos
     * decimales), asi que la desviacion tipica es exactamente 0. Fixture del
     * caso limite de division por cero.
     *
     * @return list<HistoricalQuote>
     */
    private function steadyRiseHistory(string $startDate = '2024-01-01'): array
    {
        return $this->history($startDate, array_fill(0, 25, 0.05));
    }

    /**
     * 91 velas: tramo alcista de arranque y despues un desplome inmediato.
     * Produce 2 muestras (indices 80 y 85), UNA sola BUY: fixture del caso
     * n < 2, en el que no hay dispersion calculable.
     *
     * @return list<HistoricalQuote>
     */
    private function singleBuySampleHistory(): array
    {
        return $this->history('2024-01-01', [-9.0, -5.0, -5.0, -5.0, -5.0, -2.0, -2.0, -2.0, -2.0, -2.0]);
    }

    /**
     * 91 velas en tendencia bajista suave y constante durante todo el
     * periodo (d=-0,05 desde 200,0, sin ningun tramo alcista): ninguna de
     * las 2 muestras es BUY. Mismo fixture de espiritu que
     * `BacktestingServiceTest::steadyDeclineHistory()`.
     *
     * @return list<HistoricalQuote>
     */
    private function steadyDeclineHistory(): array
    {
        return $this->history('2024-01-01', array_fill(0, 10, -0.05), 200.0, -0.05);
    }

    /**
     * @return array<string,mixed>
     */
    private function runSingleTicker(array $history): array
    {
        $result = $this->service(['TST' => $history])->run(['TST'], self::HORIZON_DAYS, self::STEP);

        self::assertSame([], $result['errors']);

        return $result['results'][0];
    }

    /**
     * v2.58, caso principal. Las 4 muestras BUY de `riseThenCrashHistory()`
     * son +1,92 / +0,47 / +3,76 / -18,10 (media -2,99) y las 6 muestras
     * totales añaden -16,57 y -13,25 (media -6,96), de donde:
     *
     * - sd muestral BUY (n-1 = 3): sqrt(309,9544/3) = 10,1646 -> 10,16
     * - error estandar BUY: 10,1646/sqrt(4) = 5,0823 -> 5,08
     * - IC 95%: -2,99 ± 1,96*5,0823 = [-12,95 ; +6,97]
     * - sd muestral de todas (n-1 = 5): sqrt(504,9945/5) = 10,0499
     * - error estandar Welch: sqrt(103,3181/4 + 100,9989/6) = 6,5317 -> 6,53
     * - t = alpha/stderr = 3,97/6,5317 = 0,61
     *
     * |t| = 0,61 < 1,96: con estas 4 muestras la alpha positiva de este
     * fixture NO es distinguible del azar, que es exactamente la lectura que
     * esta version añade.
     */
    public function testDispersionYTStatDeLaAlphaConMezclaDeSenalesBuyYNoBuy(): void
    {
        $ticker = $this->runSingleTicker($this->riseThenCrashHistory());

        self::assertSame(6, $ticker['samples']);
        self::assertSame(4, $ticker['buy_signals']);
        self::assertSame(-2.99, $ticker['avg_buy_forward_return']);
        self::assertSame(-6.96, $ticker['avg_all_days_forward_return']);
        self::assertSame(3.97, $ticker['buy_alpha_vs_all_days']);

        self::assertSame(10.16, $ticker['buy_return_stddev']);
        self::assertSame(5.08, $ticker['buy_return_stderr']);
        self::assertSame(-12.95, $ticker['buy_return_ci95_low']);
        self::assertSame(6.97, $ticker['buy_return_ci95_high']);
        self::assertSame(6.53, $ticker['buy_alpha_stderr']);
        self::assertSame(0.61, $ticker['buy_alpha_t_stat']);
    }

    /**
     * v2.58, caso n < 2: con una sola muestra BUY no hay dispersion
     * calculable, asi que los cinco campos de significancia deben ser null
     * (mismo criterio de resiliencia que `average()`/`winRate()`), sin que
     * eso afecte a los campos ya existentes, que se siguen calculando.
     */
    public function testConUnaSolaMuestraBuyLosCamposDeSignificanciaSonNulos(): void
    {
        $ticker = $this->runSingleTicker($this->singleBuySampleHistory());

        self::assertSame(1, $ticker['buy_signals']);
        self::assertNotNull($ticker['avg_buy_forward_return']);
        self::assertNotNull($ticker['buy_alpha_vs_all_days']);

        self::assertNull($ticker['buy_return_stddev']);
        self::assertNull($ticker['buy_return_stderr']);
        self::assertNull($ticker['buy_return_ci95_low']);
        self::assertNull($ticker['buy_return_ci95_high']);
        self::assertNull($ticker['buy_alpha_stderr']);
        self::assertNull($ticker['buy_alpha_t_stat']);
    }

    /**
     * v2.58, caso limite de division por cero: con todas las muestras BUY e
     * identicas (+0,24%), la desviacion tipica es exactamente 0 y el
     * intervalo de confianza colapsa sobre la media. El error estandar de la
     * alpha tambien es 0, asi que el t-stat debe ser null (no hay division
     * por cero ni un t infinito), aunque la alpha si exista (0,0).
     */
    public function testConDesviacionTipicaCeroNoHayDivisionPorCeroEnElTStat(): void
    {
        $ticker = $this->runSingleTicker($this->steadyRiseHistory());

        self::assertSame(5, $ticker['buy_signals']);
        self::assertSame(0.24, $ticker['avg_buy_forward_return']);
        self::assertSame(0.0, $ticker['buy_return_stddev']);
        self::assertSame(0.0, $ticker['buy_return_stderr']);
        self::assertSame(0.24, $ticker['buy_return_ci95_low']);
        self::assertSame(0.24, $ticker['buy_return_ci95_high']);
        self::assertSame(0.0, $ticker['buy_alpha_stderr']);
        self::assertNull($ticker['buy_alpha_t_stat']);
    }

    /**
     * v2.59, caso principal. Universo de dos tickers: `AAA` con
     * `riseThenCrashHistory()` (6 muestras, 4 BUY: +1,92 / +0,47 / +3,76 /
     * -18,10, media de todos los dias -6,96) y `BBB` con
     * `steadyRiseHistory()` (5 muestras, 5 BUY de +0,24 cada una, media de
     * todos los dias +0,24). Ambos historicos empiezan el mismo dia, asi que
     * sus muestras BUY caen en los mismos dos meses (2024-03 y 2024-04):
     * justo el solape entre tickers que motiva la vista por episodios.
     *
     * Cifras calculadas a mano:
     *
     * - muestras: 6 + 5 = 11; señales BUY: 4 + 5 = 9 (de 2 tickers)
     * - retorno medio BUY: (-11,95 + 1,20)/9 = -1,19
     * - media de todos los dias, ponderada por muestras de cada ticker:
     *   (6*-6,96 + 5*0,24)/11 = -3,69
     * - alpha: -1,19 - (-3,69) = +2,50
     * - win rate BUY: 8 de 9 positivas = 88,89%
     * - 2024-03: (1,92+0,47+3,76+0,24+0,24+0,24)/6 = +1,15
     *   2024-04: (-18,10+0,24+0,24)/3 = -5,87
     *   media de medias mensuales: (1,15 - 5,87)/2 = -2,36, muy por debajo
     *   del -1,19 por muestra: el resultado esta dominado por un unico mes
     *   malo, que es exactamente lo que esta vista sirve para detectar.
     */
    public function testAgregadoDeUniversoConDosTickersQueComparteMeses(): void
    {
        $result = $this->service([
            'AAA' => $this->riseThenCrashHistory(),
            'BBB' => $this->steadyRiseHistory(),
        ])->run(['AAA', 'BBB'], self::HORIZON_DAYS, self::STEP);

        self::assertSame([], $result['errors']);
        $aggregate = $result['aggregate'];

        self::assertSame(11, $aggregate['samples']);
        self::assertSame(9, $aggregate['buy_signals']);
        self::assertSame(-1.19, $aggregate['avg_buy_forward_return']);
        self::assertSame(-3.69, $aggregate['avg_all_days_forward_return']);
        self::assertSame(2.5, $aggregate['buy_alpha_vs_all_days']);
        self::assertSame(88.89, $aggregate['win_rate_buy']);
        self::assertSame(2, $aggregate['distinct_buy_tickers']);
        self::assertSame(2, $aggregate['distinct_buy_months']);
        self::assertSame(-2.36, $aggregate['avg_of_monthly_avgs']);
        self::assertSame(['month' => '2024-04', 'avg_forward_return' => -5.87], $aggregate['worst_month']);
    }

    /**
     * v2.59: `buy_samples` (la lista por ticker que alimenta el agregado)
     * debe contener exactamente las muestras BUY con su fecha, ni una mas
     * (las HOLD del final de `riseThenCrashHistory()` quedan fuera), y su
     * numero debe coincidir siempre con `buy_signals`.
     */
    public function testBuySamplesContieneSoloLasMuestrasDeCompraConSuFecha(): void
    {
        $ticker = $this->runSingleTicker($this->riseThenCrashHistory());

        self::assertSame($ticker['buy_signals'], count($ticker['buy_samples']));
        self::assertSame([
            ['date' => '2024-03-21', 'forward_return' => 1.92],
            ['date' => '2024-03-26', 'forward_return' => 0.47],
            ['date' => '2024-03-31', 'forward_return' => 3.76],
            ['date' => '2024-04-05', 'forward_return' => -18.1],
        ], $ticker['buy_samples']);
    }

    /**
     * v2.59, resiliencia: un universo entero sin ninguna señal BUY sigue
     * agregando muestras y media de todos los dias, pero deja en null todo
     * lo que dependeria de dividir entre cero señales (retorno medio BUY,
     * alpha, win rate, media de medias mensuales, peor mes), nunca 0.
     */
    public function testAgregadoSinNingunaSenalDeCompraDevuelveNullSinDividirPorCero(): void
    {
        $result = $this->service([
            'AAA' => $this->steadyDeclineHistory(),
            'BBB' => $this->steadyDeclineHistory(),
        ])->run(['AAA', 'BBB'], self::HORIZON_DAYS, self::STEP);

        $aggregate = $result['aggregate'];

        self::assertSame(4, $aggregate['samples']);
        self::assertSame(0, $aggregate['buy_signals']);
        self::assertSame(0, $aggregate['distinct_buy_tickers']);
        self::assertSame(0, $aggregate['distinct_buy_months']);
        self::assertNotNull($aggregate['avg_all_days_forward_return']);
        self::assertNull($aggregate['avg_buy_forward_return']);
        self::assertNull($aggregate['buy_alpha_vs_all_days']);
        self::assertNull($aggregate['win_rate_buy']);
        self::assertNull($aggregate['avg_of_monthly_avgs']);
        self::assertNull($aggregate['worst_month']);
    }
}
