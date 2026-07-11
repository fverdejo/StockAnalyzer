<?php

declare(strict_types=1);

namespace StockAnalyzer\Analyzer;

use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;

class ScoreCalculator
{
    public function calculate(Stock $stock, TechnicalSnapshot $technical): Score
    {
        $price = $stock->getQuote()->getPrice();

        $score = new Score();
        $score
            ->add(ScoreCategory::TECHNICAL, $this->technicalScore($price, $technical))
            ->add(ScoreCategory::MOMENTUM, $this->momentumScore($technical))
            ->add(ScoreCategory::RISK, $this->riskScore($technical))
            ->add(ScoreCategory::VALUATION, $this->valuationScore($price, $technical))
            ->add(ScoreCategory::FUNDAMENTAL, 15)
            ->add(ScoreCategory::QUALITY, 7)
            ->add(ScoreCategory::NEWS, 5)
            ->add(ScoreCategory::DIVIDEND, 2.5);

        return $score;
    }

    private function technicalScore(float $price, TechnicalSnapshot $technical): float
    {
        $score = 10.0;

        if ($technical->getSma20() !== null && $price > $technical->getSma20()) {
            $score += 8;
        }

        if ($technical->getSma50() !== null && $price > $technical->getSma50()) {
            $score += 8;
        }

        if ($technical->getSma20() !== null && $technical->getSma50() !== null && $technical->getSma20() > $technical->getSma50()) {
            $score += 4;
        }

        return $score;
    }

    private function momentumScore(TechnicalSnapshot $technical): float
    {
        $momentum = $technical->getMomentum30();

        if ($momentum === null) {
            return 5;
        }

        return 5 + ($momentum * 0.35);
    }

    private function riskScore(TechnicalSnapshot $technical): float
    {
        $volatility = $technical->getVolatility20();

        if ($volatility === null) {
            return 5;
        }

        return 10 - ($volatility * 2);
    }

    private function valuationScore(float $price, TechnicalSnapshot $technical): float
    {
        $sma50 = $technical->getSma50();

        if ($sma50 === null || $sma50 <= 0) {
            return 10;
        }

        $distance = (($price - $sma50) / $sma50) * 100;

        return match (true) {
            $distance < -8 => 18,
            $distance < 0 => 15,
            $distance < 8 => 12,
            $distance < 18 => 8,
            default => 5,
        };
    }
}
