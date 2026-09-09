<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use StockAnalyzer\Models\HistoricalQuote;

/** Estado de tendencia calculado al cierre de una fecha de senal. */
final class HistoricalTrendSnapshotCalculator
{
    private const SMA_WINDOW = 200;
    private const MOMENTUM_LONG_LOOKBACK = 252;
    private const MOMENTUM_SKIP_RECENT = 21;

    /**
     * @param list<HistoricalQuote> $history Orden ascendente.
     * @return array{as_of_date:string,close:float,sma200:float,above_sma200:bool,momentum_12_1_pct:float,momentum_12_1_positive:bool}|null
     */
    public function calculate(DateTimeImmutable $signalDate, array $history): ?array
    {
        $index = $this->lastIndexOnOrBefore($history, $signalDate->format('Y-m-d'));

        if ($index === null || $index < self::MOMENTUM_LONG_LOOKBACK) {
            return null;
        }

        $asOf = $history[$index];

        if ($asOf->getDate()->diff($signalDate)->days > 7) {
            return null;
        }

        $smaSum = 0.0;

        for ($cursor = $index - self::SMA_WINDOW + 1; $cursor <= $index; $cursor++) {
            $close = $history[$cursor]->getClose();

            if ($close <= 0.0) {
                return null;
            }

            $smaSum += $close;
        }

        $recentAnchor = $history[$index - self::MOMENTUM_SKIP_RECENT]->getClose();
        $oldAnchor = $history[$index - self::MOMENTUM_LONG_LOOKBACK]->getClose();
        $close = $asOf->getClose();

        if ($recentAnchor <= 0.0 || $oldAnchor <= 0.0 || $close <= 0.0) {
            return null;
        }

        $sma200 = $smaSum / self::SMA_WINDOW;
        $momentum = (($recentAnchor / $oldAnchor) - 1.0) * 100.0;

        return [
            'as_of_date' => $asOf->getDate()->format('Y-m-d'),
            'close' => $close,
            'sma200' => $sma200,
            'above_sma200' => $close > $sma200,
            'momentum_12_1_pct' => $momentum,
            'momentum_12_1_positive' => $momentum > 0.0,
        ];
    }

    /** @param list<HistoricalQuote> $history */
    private function lastIndexOnOrBefore(array $history, string $signalDate): ?int
    {
        $low = 0;
        $high = count($history);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($history[$middle]->getDate()->format('Y-m-d') <= $signalDate) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return isset($history[$low - 1]) ? $low - 1 : null;
    }
}
