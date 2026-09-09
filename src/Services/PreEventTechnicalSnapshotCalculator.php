<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use InvalidArgumentException;
use StockAnalyzer\DTO\EarningsEvent;
use StockAnalyzer\Models\HistoricalQuote;

/**
 * Estado tecnico conocido antes de un anuncio de resultados.
 *
 * Usa siempre la ultima sesion estrictamente anterior a `reportDate`. Asi
 * un anuncio BeforeMarket tampoco puede contaminar el indicador con el
 * cierre posterior a que el mercado conociese el resultado.
 */
final class PreEventTechnicalSnapshotCalculator
{
    /**
     * @param list<HistoricalQuote> $history Orden ascendente por fecha.
     * @return array{
     *   as_of_date:string,
     *   close:float,
     *   sma50:float,
     *   sma200:float,
     *   distance_to_sma200_pct:float,
     *   below_sma200:bool
     * }|null
     */
    public function calculate(EarningsEvent $event, array $history, int $longWindow = 200): ?array
    {
        if ($longWindow < 50) {
            throw new InvalidArgumentException('La ventana larga debe ser de al menos 50 sesiones.');
        }

        $lastKnownIndex = $this->lastIndexBefore($history, $event->reportDate->format('Y-m-d'));

        if ($lastKnownIndex === null || $lastKnownIndex + 1 < $longWindow) {
            return null;
        }

        $longStart = $lastKnownIndex - $longWindow + 1;
        $shortStart = $lastKnownIndex - 50 + 1;
        $longSum = 0.0;
        $shortSum = 0.0;

        for ($index = $longStart; $index <= $lastKnownIndex; $index++) {
            $close = $history[$index]->getClose();

            if ($close <= 0.0) {
                return null;
            }

            $longSum += $close;

            if ($index >= $shortStart) {
                $shortSum += $close;
            }
        }

        $lastQuote = $history[$lastKnownIndex];
        $lastClose = $lastQuote->getClose();
        $sma200 = $longSum / $longWindow;
        $sma50 = $shortSum / 50;

        return [
            'as_of_date' => $lastQuote->getDate()->format('Y-m-d'),
            'close' => $lastClose,
            'sma50' => $sma50,
            'sma200' => $sma200,
            'distance_to_sma200_pct' => (($lastClose / $sma200) - 1.0) * 100.0,
            'below_sma200' => $lastClose < $sma200,
        ];
    }

    /** @param list<HistoricalQuote> $history */
    private function lastIndexBefore(array $history, string $reportDate): ?int
    {
        $low = 0;
        $high = count($history);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($history[$middle]->getDate()->format('Y-m-d') < $reportDate) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $candidate = $low - 1;

        return isset($history[$candidate]) ? $candidate : null;
    }
}
