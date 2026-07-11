<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;

class StockAnalysisService
{
    public function __construct(
        private readonly MarketDataProviderInterface $marketDataProvider,
        private readonly ScoreCalculator $scoreCalculator,
        private readonly TechnicalAnalyzer $technicalAnalyzer
    ) {
    }

    public function analyze(string $ticker): StockAnalysis
    {
        $stock = $this->marketDataProvider->getStock($ticker);
        $history = $this->marketDataProvider->getHistoricalQuotes($ticker);
        $technicalSnapshot = $this->technicalAnalyzer->analyze($history);
        $score = $this->scoreCalculator->calculate($stock, $technicalSnapshot);

        return new StockAnalysis($stock, $score, $technicalSnapshot);
    }
}
