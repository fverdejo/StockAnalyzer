<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;

class BacktestingService
{
    public function __construct(
        private readonly MarketDataProviderInterface $marketDataProvider,
        private readonly TechnicalAnalyzer $technicalAnalyzer,
        private readonly ScoreCalculator $scoreCalculator
    ) {
    }

    /**
     * @param list<string> $tickers
     * @return array<string,mixed>
     */
    public function run(array $tickers, int $horizonDays = 20): array
    {
        $results = [];
        $errors = [];

        foreach ($tickers as $ticker) {
            try {
                $results[] = $this->backtestTicker($ticker, $horizonDays);
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
     * @return array<string,mixed>
     */
    private function backtestTicker(string $ticker, int $horizonDays): array
    {
        $stock = $this->marketDataProvider->getStock($ticker);
        $history = $this->marketDataProvider->getHistoricalQuotes($ticker);
        $samples = [];
        $step = 5;
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

            $samples[] = [
                'date' => $current->getDate()->format('Y-m-d'),
                'recommendation' => $score->getRecommendation(),
                'percentage' => $score->getPercentage(),
                'forward_return' => round($forwardReturn, 2),
            ];
        }

        $buyReturns = $this->returnsFor($samples, ['STRONG BUY', 'BUY']);
        $sellReturns = $this->returnsFor($samples, ['SELL', 'STRONG SELL']);
        $benchmark = $count > $horizonDays
            ? (($history[$count - 1]->getClose() / $history[0]->getClose()) - 1) * 100
            : 0.0;

        return [
            'ticker' => strtoupper($ticker),
            'samples' => count($samples),
            'buy_signals' => count($buyReturns),
            'sell_signals' => count($sellReturns),
            'avg_buy_forward_return' => $this->average($buyReturns),
            'avg_sell_forward_return' => $this->average($sellReturns),
            'benchmark_return' => round($benchmark, 2),
            'recent_samples' => array_slice($samples, -10),
        ];
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
}
