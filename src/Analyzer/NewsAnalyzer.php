<?php

declare(strict_types=1);

namespace StockAnalyzer\Analyzer;

use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\Signal;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Enums\SignalVerdict;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Repository\NewsRepository;

class NewsAnalyzer
{
    public function __construct(
        private readonly NewsRepository $news,
        private readonly ScoreWeights $weights = new ScoreWeights()
    ) {
    }

    public function analyze(Stock $stock): CategoryResult
    {
        $ticker = $stock->getCompany()->getTicker();
        $sentiment = $this->news->sentimentForTicker($ticker);
        $max = $this->weights->getMax(ScoreCategory::NEWS);

        if ($sentiment === null) {
            return new CategoryResult(
                ScoreCategory::NEWS,
                $max / 2,
                [new Signal(
                    'Noticias',
                    SignalVerdict::NEUTRAL,
                    'No hay noticias recientes importadas para este ticker; la categoria se mantiene neutra.',
                    ScoreCategory::NEWS
                )]
            );
        }

        $score = ($max / 2) + (($sentiment->getAverageScore() * $max) / 2);
        $verdict = match (true) {
            $sentiment->getAverageScore() > 0.2 => SignalVerdict::POSITIVE,
            $sentiment->getAverageScore() < -0.2 => SignalVerdict::NEGATIVE,
            default => SignalVerdict::NEUTRAL,
        };

        return new CategoryResult(
            ScoreCategory::NEWS,
            max(0.0, min($max, $score)),
            [new Signal(
                'Noticias',
                $verdict,
                sprintf(
                    'Hay %d noticias recientes importadas; sentimiento medio %s. Ultima: %s%s',
                    $sentiment->getCount(),
                    number_format($sentiment->getAverageScore(), 2, ',', '.'),
                    $sentiment->getHeadline() ?? 'sin titular',
                    $sentiment->getSource() ? ' (' . $sentiment->getSource() . ').' : '.'
                ),
                ScoreCategory::NEWS
            )]
        );
    }
}
