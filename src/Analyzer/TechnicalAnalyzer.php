<?php

declare(strict_types=1);

namespace StockAnalyzer\Analyzer;

use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Models\HistoricalQuote;

class TechnicalAnalyzer
{
    /**
     * @param list<HistoricalQuote> $quotes
     */
    public function analyze(array $quotes): TechnicalSnapshot
    {
        $closes = array_map(
            static fn (HistoricalQuote $quote): float => $quote->getClose(),
            $quotes
        );

        return new TechnicalSnapshot(
            $this->sma($closes, 20),
            $this->sma($closes, 50),
            $this->rsi($closes, 14),
            $this->momentum($closes, 30),
            $this->volatility($closes, 20),
            count($quotes)
        );
    }

    /**
     * @param list<float> $values
     */
    private function sma(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }

        return array_sum(array_slice($values, -$period)) / $period;
    }

    /**
     * @param list<float> $closes
     */
    private function momentum(array $closes, int $period): ?float
    {
        if (count($closes) <= $period) {
            return null;
        }

        $current = $closes[array_key_last($closes)];
        $past = $closes[count($closes) - 1 - $period];

        return $past > 0 ? (($current - $past) / $past) * 100 : null;
    }

    /**
     * @param list<float> $closes
     */
    private function volatility(array $closes, int $period): ?float
    {
        if (count($closes) <= $period) {
            return null;
        }

        $returns = [];
        $slice = array_slice($closes, -($period + 1));

        for ($index = 1; $index < count($slice); $index++) {
            if ($slice[$index - 1] > 0) {
                $returns[] = (($slice[$index] - $slice[$index - 1]) / $slice[$index - 1]) * 100;
            }
        }

        if ($returns === []) {
            return null;
        }

        $average = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(
            static fn (float $value): float => ($value - $average) ** 2,
            $returns
        )) / count($returns);

        return sqrt($variance);
    }

    /**
     * @param list<float> $closes
     */
    private function rsi(array $closes, int $period): ?float
    {
        if (count($closes) <= $period) {
            return null;
        }

        $slice = array_slice($closes, -($period + 1));
        $gains = 0.0;
        $losses = 0.0;

        for ($index = 1; $index < count($slice); $index++) {
            $change = $slice[$index] - $slice[$index - 1];

            if ($change >= 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }

        if ($losses === 0.0) {
            return 100.0;
        }

        $relativeStrength = ($gains / $period) / ($losses / $period);

        return 100 - (100 / (1 + $relativeStrength));
    }
}
