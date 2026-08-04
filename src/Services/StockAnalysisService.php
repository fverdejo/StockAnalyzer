<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Stock;

class StockAnalysisService
{
    public function __construct(
        private readonly MarketDataProviderInterface $marketDataProvider,
        private readonly ScoreCalculator $scoreCalculator,
        private readonly TechnicalAnalyzer $technicalAnalyzer,
        private readonly RiskLevelsCalculator $riskLevelsCalculator,
        private readonly DividendGrowthCalculator $dividendGrowthCalculator = new DividendGrowthCalculator()
    ) {
    }

    public function analyze(string $ticker): StockAnalysis
    {
        $stock = $this->enrichWithDividendGrowth($this->marketDataProvider->getStock($ticker), $ticker);
        $history = $this->marketDataProvider->getHistoricalQuotes($ticker);
        $technicalSnapshot = $this->technicalAnalyzer->analyze($history);
        $chartSeries = $this->technicalAnalyzer->buildChartSeries($history);
        $scoreResult = $this->scoreCalculator->calculate($stock, $technicalSnapshot);
        $riskLevels = $this->riskLevelsCalculator->compute($technicalSnapshot, $stock->getQuote()->getPrice());

        return new StockAnalysis(
            $stock,
            $scoreResult->getScore(),
            $technicalSnapshot,
            $scoreResult->getCategoryResults(),
            $chartSeries,
            $riskLevels
        );
    }

    /**
     * Completa Fundamentals::dividendGrowth5y con el historial real de
     * dividendos (llamada aparte, cacheada con TTL largo via
     * CachedMarketDataProvider::getDividendHistory(), a diferencia del
     * resto de Fundamentals que viaja dentro de getStock()). Best effort:
     * un historial vacio (sin dividendo, o fallo del proveedor) deja
     * dividendGrowth5y en null, que FundamentalAnalyzer::dividend() ya
     * trata como "sin dato".
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
}
