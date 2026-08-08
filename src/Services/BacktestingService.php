<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateInterval;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Repository\TickerBacktestCacheRepository;

class BacktestingService
{
    public function __construct(
        private readonly MarketDataProviderInterface $marketDataProvider,
        private readonly TechnicalAnalyzer $technicalAnalyzer,
        private readonly ScoreCalculator $scoreCalculator,
        private readonly RiskLevelsCalculator $riskLevelsCalculator,
        private readonly DividendGrowthCalculator $dividendGrowthCalculator = new DividendGrowthCalculator()
    ) {
    }

    /**
     * @param list<string> $tickers
     * @param int $step Tamaño del paso entre muestras consecutivas. Con el
     *        $step por defecto (5) y un horizonte tipico de 20 dias, cada
     *        muestra comparte hasta 15 de sus 20 dias de retorno futuro con
     *        la siguiente (autocorrelacion). Para obtener muestras
     *        estadisticamente independientes hay que ejecutar con
     *        $step = $horizonDays (ver tambien 'effective_independent_samples'
     *        en el resultado de cada ticker).
     * @param string $mode 'full' (score completo, el que ve el usuario real)
     *        o 'technical' (solo TECHNICAL+MOMENTUM+RISK, herramienta de
     *        investigacion via CLI que no afecta al pipeline real).
     * @return array<string,mixed>
     */
    public function run(array $tickers, int $horizonDays = 20, int $step = 5, string $mode = 'full'): array
    {
        $results = [];
        $errors = [];

        foreach ($tickers as $ticker) {
            try {
                $results[] = $this->backtestTicker($ticker, $horizonDays, $step, $mode);
            } catch (\Throwable $exception) {
                $errors[$ticker] = $exception->getMessage();
            }
        }

        return [
            'horizon_days' => $horizonDays,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'aggregate' => $this->aggregateUniverse($results),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Agrega en una sola cifra por universo lo que hasta ahora habia que
     * promediar a ojo entre decenas de filas de `results` (ver versions.md
     * v2.59), y añade la lectura por EPISODIOS de mercado.
     *
     * La vista por episodios existe porque las muestras BUY de tickers
     * distintos en la misma fecha NO son independientes: comparten el
     * movimiento del mercado de ese dia. `effective_independent_samples`
     * (v2.31) solo corrige el solape temporal DENTRO de un ticker, no este
     * agrupamiento entre tickers. Por eso `avg_of_monthly_avgs` (un mes = un
     * voto) acompaña siempre a `avg_buy_forward_return` (una muestra = un
     * voto): si ambas se separan mucho, la media global esta dominada por
     * unos pocos episodios de mercado y no por la calidad de la señal.
     *
     * @param list<array<string,mixed>> $results
     * @return array<string,mixed>
     */
    private function aggregateUniverse(array $results): array
    {
        $totalSamples = 0;
        $allDaysWeightedSum = 0.0;
        $allDaysSamples = 0;
        $buyReturns = [];
        $distinctBuyTickers = 0;
        $returnsByMonth = [];

        foreach ($results as $result) {
            $tickerSamples = (int) ($result['samples'] ?? 0);
            $totalSamples += $tickerSamples;

            if (($result['avg_all_days_forward_return'] ?? null) !== null) {
                $allDaysWeightedSum += (float) $result['avg_all_days_forward_return'] * $tickerSamples;
                $allDaysSamples += $tickerSamples;
            }

            /** @var list<array{date: string, forward_return: float}> $buySamples */
            $buySamples = $result['buy_samples'] ?? [];

            if ($buySamples !== []) {
                $distinctBuyTickers++;
            }

            foreach ($buySamples as $buySample) {
                $return = (float) $buySample['forward_return'];
                $buyReturns[] = $return;
                $returnsByMonth[substr((string) $buySample['date'], 0, 7)][] = $return;
            }
        }

        ksort($returnsByMonth);
        $monthlyAverages = [];

        foreach ($returnsByMonth as $month => $monthReturns) {
            $monthlyAverages[$month] = (float) $this->average($monthReturns);
        }

        $avgBuy = $this->average($buyReturns);
        $avgAll = $allDaysSamples > 0 ? round($allDaysWeightedSum / $allDaysSamples, 2) : null;

        return [
            'samples' => $totalSamples,
            'buy_signals' => count($buyReturns),
            'avg_buy_forward_return' => $avgBuy,
            'avg_all_days_forward_return' => $avgAll,
            'buy_alpha_vs_all_days' => ($avgBuy !== null && $avgAll !== null) ? round($avgBuy - $avgAll, 2) : null,
            'win_rate_buy' => $this->winRate($buyReturns),
            'distinct_buy_tickers' => $distinctBuyTickers,
            'distinct_buy_months' => count($monthlyAverages),
            'avg_of_monthly_avgs' => $this->average(array_values($monthlyAverages)),
            'worst_month' => $this->worstMonth($monthlyAverages),
        ];
    }

    /**
     * Mes con la peor media de retornos BUY del universo, el episodio de
     * mercado que mas pesa en contra de la señal. Mismo criterio de
     * resiliencia que el resto del agregado: sin ningun mes con muestras,
     * null. En caso de empate gana el mes mas antiguo (el array llega
     * ordenado por fecha desde `aggregateUniverse()`), para que el resultado
     * sea determinista.
     *
     * @param array<string,float> $monthlyAverages
     * @return array{month: string, avg_forward_return: float}|null
     */
    private function worstMonth(array $monthlyAverages): ?array
    {
        $worstMonth = null;
        $worstAverage = null;

        foreach ($monthlyAverages as $month => $average) {
            if ($worstAverage === null || $average < $worstAverage) {
                $worstMonth = (string) $month;
                $worstAverage = $average;
            }
        }

        if ($worstMonth === null || $worstAverage === null) {
            return null;
        }

        return ['month' => $worstMonth, 'avg_forward_return' => $worstAverage];
    }

    /**
     * Version de un solo ticker de `backtestTicker()`, pensada para uso
     * interactivo (ver versions.md v2.23: historial de la señal de compra
     * en la ficha de detalle). A diferencia de `run()`, nunca lanza: si el
     * calculo falla (proveedor, historico insuficiente...) devuelve null
     * para que la pagina que la invoque siga funcionando sin el panel.
     *
     * @return array<string,mixed>|null
     */
    public function runForTicker(string $ticker, int $horizonDays = 20, int $step = 5): ?array
    {
        try {
            return $this->backtestTicker($ticker, $horizonDays, $step);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Version cacheada de `runForTicker()` (ver versions.md v2.34,
     * `TickerBacktestCacheRepository`): solo cachea resultados en modo
     * 'full' (el que ve el usuario real), nunca los de `--mode=technical`
     * de `bin/backtest.php`, que es una herramienta de investigacion y no
     * debe contaminar la cache de produccion.
     *
     * @return array<string,mixed>|null
     */
    public function runForTickerCached(
        string $ticker,
        TickerBacktestCacheRepository $cache,
        int $horizonDays = 20,
        int $step = 5,
        ?DateInterval $ttl = null
    ): ?array {
        $ttl ??= new DateInterval('P1D');
        $cached = $cache->find($ticker, $horizonDays, $step, $ttl);

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->runForTicker($ticker, $horizonDays, $step);

        if ($result !== null) {
            $cache->save($ticker, $horizonDays, $step, $result);
        }

        return $result;
    }

    /**
     * Agrega el historial de señal de compra (mismo criterio que
     * backtestTicker()/runForTicker()) de un grupo de tickers, ponderando
     * por el numero de muestras gestionadas de cada uno. Pensado para dar
     * una cifra con mas soporte estadistico cuando el historico de un
     * ticker individual es corto (ver v2.34): un grupo de tickers del mismo
     * sector, NUNCA una mezcla arbitraria (la homogeneidad la decide el
     * llamador via UniverseConfig::narrowestSectorFor()).
     *
     * Recorre los tickers uno a uno via `runForTickerCached()` en vez de
     * `run()` (que recalcula TODOS los tickers de golpe sin cache): la
     * mayoria vendran de cache tras un primer "calentamiento" (ver
     * `bin/backtest.php --persist`). Como mucho `$maxLiveComputations`
     * tickers sin cachear se calculan de verdad en una misma llamada; el
     * resto de tickers sin cache se excluyen del agregado de esta respuesta
     * concreta para no bloquear la peticion esperando calcular un grupo
     * entero (hasta ~50 tickers).
     *
     * @param list<string> $tickers
     * @return array{buy_managed_samples: int, avg_buy_managed_return: ?float}|null
     */
    public function runForPeerGroup(
        array $tickers,
        TickerBacktestCacheRepository $cache,
        int $horizonDays = 20,
        int $step = 5,
        int $maxLiveComputations = 5
    ): ?array {
        $totalSamples = 0;
        $weightedReturnSum = 0.0;
        $liveComputations = 0;
        $ttl = new DateInterval('P1D');

        foreach ($tickers as $ticker) {
            $cached = $cache->find($ticker, $horizonDays, $step, $ttl);

            if ($cached === null) {
                if ($liveComputations >= $maxLiveComputations) {
                    continue;
                }

                $liveComputations++;
                $cached = $this->runForTickerCached($ticker, $cache, $horizonDays, $step, $ttl);
            }

            if ($cached === null) {
                continue;
            }

            $samples = (int) $cached['buy_managed_samples'];

            if ($samples > 0 && $cached['avg_buy_managed_return'] !== null) {
                $totalSamples += $samples;
                $weightedReturnSum += $cached['avg_buy_managed_return'] * $samples;
            }
        }

        if ($totalSamples === 0) {
            return null;
        }

        return [
            'buy_managed_samples' => $totalSamples,
            'avg_buy_managed_return' => round($weightedReturnSum / $totalSamples, 2),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function backtestTicker(string $ticker, int $horizonDays, int $step, string $mode = 'full'): array
    {
        if (!in_array($mode, ['full', 'technical'], true)) {
            throw new \InvalidArgumentException("Modo de backtest desconocido: '$mode'. Valores validos: 'full', 'technical'.");
        }

        $stock = $this->enrichWithDividendGrowth($this->marketDataProvider->getStock($ticker), $ticker);
        $history = $this->marketDataProvider->getHistoricalQuotes($ticker);
        $samples = [];
        $minimumLookback = 80;
        $count = count($history);

        for ($index = $minimumLookback; $index < $count - $horizonDays; $index += $step) {
            $past = array_slice($history, 0, $index + 1);
            $current = $history[$index];
            $future = $history[$index + $horizonDays];
            $synthetic = $this->stockAt($stock, $current);
            $technical = $this->technicalAnalyzer->analyze($past);
            $score = $this->scoreCalculator->calculate($synthetic, $technical)->getScore();
            $forwardReturn = (($future->getClose() / $current->getClose()) - 1) * 100;

            if ($mode === 'technical') {
                $weights = $this->scoreCalculator->getWeights();
                $scores = $score->getScores();
                $technicalMax = $weights->getMax(ScoreCategory::TECHNICAL)
                    + $weights->getMax(ScoreCategory::MOMENTUM)
                    + $weights->getMax(ScoreCategory::RISK);
                $technicalTotal = ($scores[ScoreCategory::TECHNICAL->value] ?? 0)
                    + ($scores[ScoreCategory::MOMENTUM->value] ?? 0)
                    + ($scores[ScoreCategory::RISK->value] ?? 0);
                $percentage = $technicalMax > 0 ? round(($technicalTotal / $technicalMax) * 100, 2) : 0.0;
                $recommendation = Score::recommendationFor($percentage);
            } else {
                $percentage = $score->getPercentage();
                $recommendation = $score->getRecommendation();
            }

            $managedReturn = null;
            $exitReason = null;
            $exitDay = null;

            if (in_array($recommendation, ['STRONG BUY', 'BUY'], true)) {
                $riskLevels = $this->riskLevelsCalculator->compute($technical, $current->getClose());

                if ($riskLevels !== null) {
                    [$exitReason, $exitPrice, $exitDay] = $this->simulateManagedExit(
                        $history,
                        $index,
                        $horizonDays,
                        $riskLevels,
                        $future
                    );
                    $managedReturn = round((($exitPrice / $current->getClose()) - 1) * 100, 2);
                }
            }

            $samples[] = [
                'date' => $current->getDate()->format('Y-m-d'),
                'recommendation' => $recommendation,
                'percentage' => $percentage,
                'forward_return' => round($forwardReturn, 2),
                'managed_return' => $managedReturn,
                'exit_reason' => $exitReason,
                'exit_day' => $exitDay,
            ];
        }

        $buyReturns = $this->returnsFor($samples, ['STRONG BUY', 'BUY']);
        $sellReturns = $this->returnsFor($samples, ['SELL', 'STRONG SELL']);
        $managedSamples = $this->managedSamplesFor($samples, ['STRONG BUY', 'BUY']);
        $benchmark = $count > $horizonDays
            ? (($history[$count - 1]->getClose() / $history[0]->getClose()) - 1) * 100
            : 0.0;
        $allReturns = array_column($samples, 'forward_return');
        $avgAll = $this->average($allReturns);
        $avgBuy = $this->average($buyReturns);
        $alpha = ($avgBuy !== null && $avgAll !== null) ? round($avgBuy - $avgAll, 2) : null;
        $buyStdDev = $this->stdDev($buyReturns);
        $buyStdErr = $buyStdDev !== null ? $buyStdDev / sqrt(count($buyReturns)) : null;
        $alphaStdErr = $this->welchStdErr($buyReturns, $allReturns);

        return [
            'ticker' => strtoupper($ticker),
            'samples' => count($samples),
            'effective_independent_samples' => (int) floor(
                count($samples) / max(1, (int) ceil($horizonDays / $step))
            ),
            'buy_signals' => count($buyReturns),
            'sell_signals' => count($sellReturns),
            'avg_buy_forward_return' => $this->average($buyReturns),
            'avg_sell_forward_return' => $this->average($sellReturns),
            'win_rate_buy' => $this->winRate($buyReturns),
            'win_rate_sell' => $this->winRate($sellReturns),
            'avg_all_days_forward_return' => $avgAll,
            'win_rate_all_days' => $this->winRate($allReturns),
            'buy_alpha_vs_all_days' => $alpha,
            'buy_return_stddev' => $buyStdDev !== null ? round($buyStdDev, 2) : null,
            'buy_return_stderr' => $buyStdErr !== null ? round($buyStdErr, 2) : null,
            'buy_return_ci95_low' => ($avgBuy !== null && $buyStdErr !== null)
                ? round($avgBuy - (1.96 * $buyStdErr), 2)
                : null,
            'buy_return_ci95_high' => ($avgBuy !== null && $buyStdErr !== null)
                ? round($avgBuy + (1.96 * $buyStdErr), 2)
                : null,
            'buy_alpha_stderr' => $alphaStdErr !== null ? round($alphaStdErr, 2) : null,
            'buy_alpha_t_stat' => ($alpha !== null && $alphaStdErr !== null && $alphaStdErr > 0.0)
                ? round($alpha / $alphaStdErr, 2)
                : null,
            'benchmark_return' => round($benchmark, 2),
            'recent_samples' => array_slice($samples, -10),
            'buy_samples' => $this->datedReturnsFor($samples, ['STRONG BUY', 'BUY']),
            'buy_managed_samples' => count($managedSamples),
            'avg_buy_managed_return' => $this->average(array_map(
                static fn (array $sample): float => (float) $sample['managed_return'],
                $managedSamples
            )),
            'max_drawdown_managed' => $this->worstManagedReturn($managedSamples),
            'stop_loss_rate' => $this->rateOf($managedSamples, 'stop_loss'),
            'target_rate' => $this->rateOf($managedSamples, 'target'),
            'horizon_rate' => $this->rateOf($managedSamples, 'horizon'),
        ];
    }

    /**
     * Recorre el historico dia a dia desde la señal hasta el horizonte para
     * saber si el stop-loss/objetivo basado en ATR14 (RiskLevelsCalculator)
     * se dispara antes que el horizonte fijo. Criterio conservador: si un
     * mismo dia cruza stop y objetivo a la vez, se asume que el stop-loss
     * se ejecuta primero, porque no hay datos intradia para saber cual de
     * los dos sucedio antes.
     *
     * @param list<HistoricalQuote> $history
     * @return array{0: string, 1: float, 2: int}
     */
    private function simulateManagedExit(
        array $history,
        int $index,
        int $horizonDays,
        RiskLevels $riskLevels,
        HistoricalQuote $future
    ): array {
        for ($offset = 1; $offset <= $horizonDays; $offset++) {
            $day = $history[$index + $offset];
            $hitStop = $day->getLow() <= $riskLevels->getStopLoss();
            $hitTarget = $day->getHigh() >= $riskLevels->getTarget();

            if ($hitStop) {
                return ['stop_loss', $riskLevels->getStopLoss(), $offset];
            }

            if ($hitTarget) {
                return ['target', $riskLevels->getTarget(), $offset];
            }
        }

        return ['horizon', $future->getClose(), $horizonDays];
    }

    /**
     * Igual que el resto de Fundamentals (PER, ROE...), dividendGrowth5y se
     * calcula una unica vez con el historial de dividendos MAS RECIENTE
     * disponible y se trata como constante durante todo el recorrido
     * historico de backtestTicker()/stockAt(): no hay forma de reconstruir
     * el historial de dividendos "tal y como se veia" en cada fecha pasada
     * sin guardar snapshots historicos que hoy no existen. Misma
     * simplificacion conocida que ya asume el resto del backtest para
     * cualquier campo fundamental.
     */
    private function enrichWithDividendGrowth(Stock $stock, string $ticker): Stock
    {
        $dividendHistory = $this->marketDataProvider->getDividendHistory($ticker);
        $dividendGrowth5y = $this->dividendGrowthCalculator->calculate($dividendHistory);

        return new Stock(
            $stock->getCompany(),
            $stock->getQuote(),
            $stock->getFundamentals()->withDividendGrowth5y($dividendGrowth5y)
        );
    }

    private function stockAt(Stock $stock, HistoricalQuote $historical): Stock
    {
        $company = $stock->getCompany();

        return new Stock(
            new Company(
                $company->getTicker(),
                $company->getName(),
                $company->getSector(),
                $company->getIndustry(),
                $company->getMarket(),
                $company->getCurrency()
            ),
            new Quote(
                $historical->getClose(),
                $historical->getOpen(),
                $historical->getHigh(),
                $historical->getLow(),
                $historical->getClose(),
                $historical->getVolume(),
                $historical->getDate()
            ),
            $stock->getFundamentals()
        );
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param list<string> $recommendations
     * @return list<float>
     */
    private function returnsFor(array $samples, array $recommendations): array
    {
        $returns = [];

        foreach ($samples as $sample) {
            if (in_array((string) $sample['recommendation'], $recommendations, true)) {
                $returns[] = (float) $sample['forward_return'];
            }
        }

        return $returns;
    }

    /**
     * Muestras BUY con su fecha, no solo su retorno: base del agregado por
     * universo/episodios de mercado de `run()` (ver versions.md v2.59), que
     * necesita saber en que mes cayo cada muestra y de que ticker viene, no
     * solo su valor. Version "con fecha" de `returnsFor()`, que se mantiene
     * para todo lo que solo necesita la lista de retornos.
     *
     * @param list<array<string,mixed>> $samples
     * @param list<string> $recommendations
     * @return list<array{date: string, forward_return: float}>
     */
    private function datedReturnsFor(array $samples, array $recommendations): array
    {
        $dated = [];

        foreach ($samples as $sample) {
            if (in_array((string) $sample['recommendation'], $recommendations, true)) {
                $dated[] = [
                    'date' => (string) $sample['date'],
                    'forward_return' => (float) $sample['forward_return'],
                ];
            }
        }

        return $dated;
    }

    /**
     * @param list<float> $values
     */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * Desviacion tipica MUESTRAL (denominador n-1, no n): las muestras de un
     * backtest son una muestra del comportamiento de la señal, no la
     * poblacion completa de todos los dias posibles. Devuelve el valor sin
     * redondear (quien lo publica en el resultado lo redondea, para que los
     * estadisticos derivados no acumulen error de redondeo). Mismo criterio
     * de resiliencia que `average()`/`winRate()`: sin dispersion calculable
     * (menos de 2 valores), null en vez de dividir por cero.
     *
     * @param list<float> $values
     */
    private function stdDev(array $values): ?float
    {
        $count = count($values);

        if ($count < 2) {
            return null;
        }

        $mean = array_sum($values) / $count;
        $sumOfSquares = 0.0;

        foreach ($values as $value) {
            $sumOfSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumOfSquares / ($count - 1));
    }

    /**
     * Error estandar de la diferencia entre dos medias por la formula de
     * Welch (`sqrt(sB²/nB + sA²/nA)`), sin asumir que ambos grupos tengan la
     * misma varianza: el grupo BUY es un subconjunto pequeño y mas selectivo
     * que "todos los dias", asi que la version de varianza combinada seria
     * una hipotesis que estos datos no respaldan. Sin redondear, igual que
     * `stdDev()`. null si alguno de los dos grupos no tiene dispersion
     * calculable (menos de 2 muestras).
     *
     * @param list<float> $groupB
     * @param list<float> $groupA
     */
    private function welchStdErr(array $groupB, array $groupA): ?float
    {
        $stdDevB = $this->stdDev($groupB);
        $stdDevA = $this->stdDev($groupA);

        if ($stdDevB === null || $stdDevA === null) {
            return null;
        }

        return sqrt((($stdDevB ** 2) / count($groupB)) + (($stdDevA ** 2) / count($groupA)));
    }

    /**
     * Muestras con recomendacion de compra que ademas tuvieron niveles de
     * riesgo calculables (managed_return no nulo): base tanto para
     * avg_buy_managed_return como para las tasas de salida.
     *
     * @param list<array<string,mixed>> $samples
     * @param list<string> $recommendations
     * @return list<array<string,mixed>>
     */
    private function managedSamplesFor(array $samples, array $recommendations): array
    {
        $managed = [];

        foreach ($samples as $sample) {
            if (
                in_array((string) $sample['recommendation'], $recommendations, true)
                && $sample['managed_return'] !== null
            ) {
                $managed[] = $sample;
            }
        }

        return $managed;
    }

    /**
     * Porcentaje de muestras con forward_return positivo (0% exacto no
     * cuenta como acierto: sin movimiento no hay ganancia que respalde la
     * señal). Mismo criterio de resiliencia que average(): sin muestras,
     * null en vez de dividir por cero.
     *
     * @param list<float> $returns
     */
    private function winRate(array $returns): ?float
    {
        if ($returns === []) {
            return null;
        }

        $wins = 0;

        foreach ($returns as $return) {
            if ($return > 0) {
                $wins++;
            }
        }

        return round(($wins / count($returns)) * 100, 2);
    }

    /**
     * Peor managed_return individual entre las muestras BUY gestionadas: el
     * drawdown mas severo que habria sufrido la estrategia gestionada, no
     * la media (avg_buy_managed_return ya la reporta). Mismo criterio de
     * resiliencia que el resto del agregado: sin muestras, null.
     *
     * @param list<array<string,mixed>> $managedSamples
     */
    private function worstManagedReturn(array $managedSamples): ?float
    {
        if ($managedSamples === []) {
            return null;
        }

        $returns = array_map(
            static fn (array $sample): float => (float) $sample['managed_return'],
            $managedSamples
        );

        return min($returns);
    }

    /**
     * @param list<array<string,mixed>> $managedSamples
     */
    private function rateOf(array $managedSamples, string $reason): ?float
    {
        if ($managedSamples === []) {
            return null;
        }

        $matching = array_filter(
            $managedSamples,
            static fn (array $sample): bool => $sample['exit_reason'] === $reason
        );

        return round((count($matching) / count($managedSamples)) * 100, 2);
    }
}
