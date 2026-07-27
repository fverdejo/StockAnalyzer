<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\DTO\StockAnalysis;

class AnalysisJsonPresenter
{
    /**
     * @param list<StockAnalysis> $analyses
     * @param array<string,string> $errors
     * @return array<string,mixed>
     */
    public function ranking(array $analyses, array $errors, string $universe, string $rawTickers): array
    {
        return [
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'universe' => $universe,
            'tickers' => $rawTickers,
            'count' => count($analyses),
            'errors' => $errors,
            'results' => array_map(fn (StockAnalysis $analysis): array => $this->analysis($analysis), $analyses),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function analysis(StockAnalysis $analysis): array
    {
        $stock = $analysis->getStock();
        $company = $stock->getCompany();
        $quote = $stock->getQuote();
        $score = $analysis->getScore();

        return [
            'ticker' => $company->getTicker(),
            'name' => $company->getName(),
            'market' => $company->getMarket(),
            'currency' => $company->getCurrency(),
            'price' => $quote->getPrice(),
            'volume' => $quote->getVolume(),
            'score' => $score->getTotal(),
            'percentage' => $score->getPercentage(),
            'recommendation' => $score->getRecommendation(),
            'categories' => $score->toArray()['categories'],
            'technical' => [
                'sma20' => $analysis->getTechnicalSnapshot()->getSma20(),
                'sma50' => $analysis->getTechnicalSnapshot()->getSma50(),
                'rsi14' => $analysis->getTechnicalSnapshot()->getRsi14(),
                'macd' => $analysis->getTechnicalSnapshot()->getMacd(),
                'momentum30' => $analysis->getTechnicalSnapshot()->getMomentum30(),
                'volatility20' => $analysis->getTechnicalSnapshot()->getVolatility20(),
            ],
        ];
    }
}
