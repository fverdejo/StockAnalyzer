<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

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

class BacktestingService
{
    public function __construct(
        private readonly MarketDataProviderInterface $marketDataProvider,
        private readonly TechnicalAnalyzer $technicalAnalyzer,
        private readonly ScoreCalculator $scoreCalculator,
        private readonly RiskLevelsCalculator $riskLevelsCalculator
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
     * Agrega el historial de señal de compra (mismo criterio que
     * backtestTicker()/runForTicker()) de un grupo de tickers, ponderando
     * por el numero de muestras gestionadas de cada uno. Pensado para dar
     * una cifra con mas soporte estadistico cuando el historico de un
     * ticker individual es corto (ver v2.34): un grupo de tickers del mismo
     * sector, NUNCA una mezcla arbitraria (la homogeneidad la decide el
     * llamador via UniverseConfig::narrowestSectorFor()).
     *
     * @param list<string> $tickers
     * @return array{buy_managed_samples: int, avg_buy_managed_return: ?float}|null
     */
    public function runForPeerGroup(array $tickers, int $horizonDays = 20, int $step = 5): ?array
    {
        $run = $this->run($tickers, $horizonDays, $step);
        $totalSamples = 0;
        $weightedReturnSum = 0.0;

        foreach ($run['results'] as $result) {
            $samples = (int) $result['buy_managed_samples'];

            if ($samples > 0 && $result['avg_buy_managed_return'] !== null) {
                $totalSamples += $samples;
                $weightedReturnSum += $result['avg_buy_managed_return'] * $samples;
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

        $stock = $this->marketDataProvider->getStock($ticker);
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
            'benchmark_return' => round($benchmark, 2),
            'recent_samples' => array_slice($samples, -10),
            'buy_managed_samples' => count($managedSamples),
            'avg_buy_managed_return' => $this->average(array_map(
                static fn (array $sample): float => (float) $sample['managed_return'],
                $managedSamples
            )),
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
