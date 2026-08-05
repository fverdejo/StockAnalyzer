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
            'results' => $results,
            'errors' => $errors,
        ];
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
            'buy_alpha_vs_all_days' => ($avgBuy !== null && $avgAll !== null) ? round($avgBuy - $avgAll, 2) : null,
            'benchmark_return' => round($benchmark, 2),
            'recent_samples' => array_slice($samples, -10),
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
