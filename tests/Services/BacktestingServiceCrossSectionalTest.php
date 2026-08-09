<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\RiskLevelsCalculator;

/**
 * Cubre `BacktestingService::runCrossSectional()`, el backtest TRANSVERSAL:
 * en cada fecha compara el retorno del top-N por puntuacion con la media de
 * todo el universo ese mismo dia, en vez de comparar cada ticker consigo
 * mismo como hace `backtestTicker()`.
 *
 * Igual que el resto de tests de BacktestingService, no se usa ningun doble
 * de TechnicalAnalyzer/ScoreCalculator: se usan las clases reales sobre
 * historicos sinteticos. Todos los fixtures estan construidos para que los
 * `forward_return` sean porcentajes exactos (+5,00 / +3,00 / -5,00 / -3,00)
 * y las cifras esperadas se puedan calcular a mano; lo unico que se delega
 * al ScoreCalculator real es el ORDEN (que una serie claramente alcista
 * puntue por encima de una claramente bajista), y eso se comprueba
 * explicitamente en `top_tickers`.
 */
final class BacktestingServiceCrossSectionalTest extends TestCase
{
    private const HORIZON_DAYS = 5;
    private const STEP = 5;
    private const TOP_N = 2;

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
     * 81 velas de arranque (indices 0..80) con incremento de cierre
     * constante `$baselineStep` desde `$startClose`, y despues una vela por
     * cada elemento de `$increments`. Mismo esqueleto que usan los demas
     * tests de BacktestingService: en el indice 80, el primero que muestrea
     * el servicio, el cierre es exactamente `$startClose + 80*$baselineStep`.
     *
     * @param list<float> $increments
     * @return list<HistoricalQuote>
     */
    private function history(string $startDate, array $increments, float $startClose, float $baselineStep): array
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
     * 91 velas alcistas: arranque suave (100,00 -> 104,00 en el indice 80) y
     * despues dos tramos de 5 velas que llevan el cierre a 109,20 y a
     * 112,476. Con horizonte 5 y paso 5 se muestrean los indices 80 y 85,
     * cuyos retornos forward son exactamente +5,00% y +3,00%.
     *
     * @return list<HistoricalQuote>
     */
    private function winnerHistory(string $startDate = '2024-01-01'): array
    {
        return $this->history(
            $startDate,
            array_merge(array_fill(0, 5, 1.04), array_fill(0, 5, 0.6552)),
            100.0,
            0.05
        );
    }

    /**
     * Espejo exacto de `winnerHistory()`: 91 velas bajistas (200,00 ->
     * 196,00 en el indice 80, y despues a 186,20 y 180,614), con retornos
     * forward de exactamente -5,00% y -3,00% en los indices 80 y 85.
     *
     * @return list<HistoricalQuote>
     */
    private function loserHistory(string $startDate = '2024-01-01'): array
    {
        return $this->history(
            $startDate,
            array_merge(array_fill(0, 5, -1.96), array_fill(0, 5, -1.1172)),
            200.0,
            -0.05
        );
    }

    /**
     * Universo de cuatro tickers alineados en el tiempo: dos claramente
     * alcistas (los que el ranking debe elegir) y dos claramente bajistas.
     *
     * @return array<string,list<HistoricalQuote>>
     */
    private function universeWhereTopIsBest(): array
    {
        return [
            'AAA' => $this->winnerHistory(),
            'BBB' => $this->winnerHistory(),
            'CCC' => $this->loserHistory(),
            'DDD' => $this->loserHistory(),
        ];
    }

    /**
     * Caso principal: el top-N es de verdad el mejor.
     *
     * Universo de 4 tickers y top-2, con dos fechas de señal (2024-03-21 e
     * indice 85 -> 2024-03-26, separadas justo por el horizonte).
     *
     * - 2024-03-21: top = (+5,00 +5,00)/2 = +5,00; universo =
     *   (+5,00 +5,00 -5,00 -5,00)/4 = 0,00; alpha = +5,00.
     * - 2024-03-26: top = +3,00; universo = 0,00; alpha = +3,00.
     *
     * De ahi, con una fecha = un voto:
     *
     * - alpha media = (5,00 + 3,00)/2 = +4,00
     * - desviacion tipica muestral (n-1 = 1) = sqrt(2) = 1,4142 -> 1,41
     * - error estandar = 1,4142/sqrt(2) = 1,00
     * - t pareado = 4,00/1,00 = 4,00; IC95 = 4,00 ± 1,96 = [2,04 ; 5,96]
     * - 2 de 2 fechas con alpha positiva = 100%
     *
     * El contraste `pooled_*` (Welch sobre las nubes de retornos sin
     * emparejar por fecha) da sobre los mismos datos:
     * sd([5,5,3,3]) = 1,1547 y sd([5,5,-5,-5,3,3,-3,-3]) = 4,4078, de donde
     * el error estandar es sqrt(1,3333/4 + 19,4286/8) = 1,6619 -> 1,66 y
     * t = 4,00/1,6619 = 2,41: mucho menos significativo que el pareado,
     * porque se traga como ruido la dispersion del propio mercado que el
     * diseño transversal ya habia cancelado.
     */
    public function testTopNMejorQueElUniversoDaAlphaPositivaYTStatPareado(): void
    {
        $result = $this->service($this->universeWhereTopIsBest())
            ->runCrossSectional(['AAA', 'BBB', 'CCC', 'DDD'], self::HORIZON_DAYS, self::STEP, self::TOP_N);

        self::assertSame([], $result['errors']);
        self::assertSame(2, $result['dates_evaluated']);
        self::assertSame(0, $result['dates_dropped_low_breadth']);
        self::assertSame(0, $result['dates_dropped_overlapping']);

        self::assertSame(4.0, $result['avg_top_n_forward_return']);
        self::assertSame(0.0, $result['avg_universe_forward_return']);
        self::assertSame(4.0, $result['avg_alpha']);
        self::assertSame(1.41, $result['alpha_stddev']);
        self::assertSame(1.0, $result['alpha_stderr']);
        self::assertSame(2.04, $result['alpha_ci95_low']);
        self::assertSame(5.96, $result['alpha_ci95_high']);
        self::assertSame(4.0, $result['alpha_t_stat']);
        self::assertSame(2, $result['dates_with_positive_alpha']);
        self::assertSame(100.0, $result['pct_dates_positive_alpha']);
        self::assertSame(100.0, $result['win_rate_top_n']);
        self::assertSame(50.0, $result['win_rate_universe']);
        self::assertSame(1.66, $result['pooled_alpha_stderr']);
        self::assertSame(2.41, $result['pooled_alpha_t_stat']);

        self::assertSame([
            [
                'date' => '2024-03-21',
                'universe_size' => 4,
                'top_tickers' => ['AAA', 'BBB'],
                'top_avg_forward_return' => 5.0,
                'universe_avg_forward_return' => 0.0,
                'alpha' => 5.0,
            ],
            [
                'date' => '2024-03-26',
                'universe_size' => 4,
                'top_tickers' => ['AAA', 'BBB'],
                'top_avg_forward_return' => 3.0,
                'universe_avg_forward_return' => 0.0,
                'alpha' => 3.0,
            ],
        ], $result['dates']);
    }

    /**
     * Caso contrario: el ranking elige justo lo peor.
     *
     * Mismo universo de 4 tickers, pero con historicos de 86 velas (solo se
     * muestrea el indice 80, 2024-03-21) en los que las dos series alcistas
     * -las que el score elige- caen un 5,00% en el horizonte y las dos
     * bajistas suben un 5,00%. La alpha de la unica fecha es
     * -5,00 - 0,00 = -5,00.
     *
     * Con una sola fecha no hay dispersion calculable, asi que la
     * desviacion tipica, el error estandar, el IC y el t pareado son null
     * (mismo criterio de resiliencia que el resto del servicio) sin que eso
     * impida reportar la alpha ni el porcentaje de fechas con alpha
     * positiva, que aqui es 0%. El contraste sin emparejar si es calculable:
     * sd([-5,-5]) = 0 y sd([-5,-5,5,5]) = 5,7735, de donde el error estandar
     * es sqrt(0/2 + 33,3333/4) = 2,8868 -> 2,89 y t = -5,00/2,8868 = -1,73.
     *
     * Este caso es el que da sentido a la metrica: si el ranking no ordena,
     * la cifra sale negativa, no "poco significativa".
     */
    public function testTopNPeorQueElUniversoDaAlphaNegativa(): void
    {
        $crashingWinner = $this->history('2024-01-01', array_fill(0, 5, -1.04), 100.0, 0.05);
        $ralliedLoser = $this->history('2024-01-01', array_fill(0, 5, 1.96), 200.0, -0.05);

        $result = $this->service([
            'AAA' => $crashingWinner,
            'BBB' => $crashingWinner,
            'CCC' => $ralliedLoser,
            'DDD' => $ralliedLoser,
        ])->runCrossSectional(['AAA', 'BBB', 'CCC', 'DDD'], self::HORIZON_DAYS, self::STEP, self::TOP_N);

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(['AAA', 'BBB'], $result['dates'][0]['top_tickers']);
        self::assertSame(-5.0, $result['dates'][0]['alpha']);

        self::assertSame(-5.0, $result['avg_top_n_forward_return']);
        self::assertSame(0.0, $result['avg_universe_forward_return']);
        self::assertSame(-5.0, $result['avg_alpha']);
        self::assertNull($result['alpha_stddev']);
        self::assertNull($result['alpha_stderr']);
        self::assertNull($result['alpha_ci95_low']);
        self::assertNull($result['alpha_ci95_high']);
        self::assertNull($result['alpha_t_stat']);
        self::assertSame(0, $result['dates_with_positive_alpha']);
        self::assertSame(0.0, $result['pct_dates_positive_alpha']);
        self::assertSame(0.0, $result['win_rate_top_n']);
        self::assertSame(50.0, $result['win_rate_universe']);
        self::assertSame(2.89, $result['pooled_alpha_stderr']);
        self::assertSame(-1.73, $result['pooled_alpha_t_stat']);
    }

    /**
     * Un ticker con historico desplazado en el tiempo (empieza un mes mas
     * tarde) genera fechas de señal propias en las que es el unico presente:
     * ahi no hay universo con el que comparar y el "top-2" seria el propio
     * universo, asi que esas fechas se descartan y no cambian ni una cifra
     * del resultado respecto al universo alineado.
     */
    public function testFechasSinSuficienteAmplitudNoSeEvaluan(): void
    {
        $result = $this->service($this->universeWhereTopIsBest() + [
            'EEE' => $this->winnerHistory('2024-02-01'),
        ])->runCrossSectional(['AAA', 'BBB', 'CCC', 'DDD', 'EEE'], self::HORIZON_DAYS, self::STEP, self::TOP_N);

        self::assertSame([], $result['errors']);
        self::assertSame(2, $result['dates_evaluated']);
        self::assertSame(2, $result['dates_dropped_low_breadth']);
        self::assertSame(0, $result['dates_dropped_overlapping']);
        self::assertSame(['2024-03-21', '2024-03-26'], array_column($result['dates'], 'date'));
        self::assertSame(4.0, $result['avg_alpha']);
        self::assertSame(4.0, $result['alpha_t_stat']);
    }

    /**
     * Tres tickers desplazados un solo dia generan fechas con amplitud
     * suficiente (3 > top-2) pero solapadas con las ya evaluadas: su ventana
     * de retorno futuro comparte 4 de sus 5 dias con la anterior, asi que
     * contarlas como muestras independientes inflaria el t-stat sin aportar
     * informacion nueva. Deben descartarse por solape, dejando el resultado
     * identico al del universo alineado.
     */
    public function testFechasSolapadasSeDescartanParaQueElTStatSignifiqueAlgo(): void
    {
        $result = $this->service($this->universeWhereTopIsBest() + [
            'XXX' => $this->winnerHistory('2024-01-02'),
            'YYY' => $this->winnerHistory('2024-01-02'),
            'ZZZ' => $this->loserHistory('2024-01-02'),
        ])->runCrossSectional(
            ['AAA', 'BBB', 'CCC', 'DDD', 'XXX', 'YYY', 'ZZZ'],
            self::HORIZON_DAYS,
            self::STEP,
            self::TOP_N
        );

        self::assertSame([], $result['errors']);
        self::assertSame(2, $result['dates_evaluated']);
        self::assertSame(2, $result['dates_dropped_overlapping']);
        self::assertSame(0, $result['dates_dropped_low_breadth']);
        self::assertSame(['2024-03-21', '2024-03-26'], array_column($result['dates'], 'date'));
        self::assertSame(4.0, $result['avg_alpha']);
        self::assertSame(4.0, $result['alpha_t_stat']);
    }

    /**
     * Un paso menor que el horizonte produce muestras que comparten dias de
     * retorno futuro: el t-stat resultante seria mas alto de lo que
     * corresponde a la evidencia disponible. Como toda la razon de ser de
     * este metodo es dar una cifra en la que se pueda confiar, es un error
     * de programacion, no un aviso.
     */
    public function testPasoMenorQueElHorizonteEsUnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service($this->universeWhereTopIsBest())
            ->runCrossSectional(['AAA', 'BBB', 'CCC', 'DDD'], self::HORIZON_DAYS, self::HORIZON_DAYS - 1, self::TOP_N);
    }

    public function testTopNMenorQueUnoEsUnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service($this->universeWhereTopIsBest())
            ->runCrossSectional(['AAA', 'BBB', 'CCC', 'DDD'], self::HORIZON_DAYS, self::STEP, 0);
    }

    /**
     * Un ticker que falla (aqui, sin historico configurado en el proveedor)
     * no tumba el recorrido: se registra en `errors` y el resto del universo
     * se evalua igual, mismo criterio que `run()`.
     */
    public function testUnTickerQueFallaNoTumbaElRecorrido(): void
    {
        $result = $this->service($this->universeWhereTopIsBest())
            ->runCrossSectional(['AAA', 'BBB', 'CCC', 'DDD', 'SINDATOS'], self::HORIZON_DAYS, self::STEP, self::TOP_N);

        self::assertSame(['SINDATOS'], array_keys($result['errors']));
        self::assertSame(2, $result['dates_evaluated']);
        self::assertSame(4.0, $result['avg_alpha']);
    }
}
