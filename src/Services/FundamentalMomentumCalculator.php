<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

/**
 * Cambios interanuales de fundamentales. Todos los factores quedan
 * orientados igual: un numero mayor representa una mejora.
 */
final class FundamentalMomentumCalculator
{
    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $previousYear
     * @return array{
     *   revenue_growth_acceleration:?float,
     *   operating_margin_change:?float,
     *   roic_change:?float,
     *   debt_to_equity_improvement:?float,
     *   fcf_delta_yield:?float
     * }
     */
    public function calculate(array $current, array $previousYear): array
    {
        $currentRevenueGrowth = $this->number($current['revenueGrowth'] ?? null);
        $previousRevenueGrowth = $this->number($previousYear['revenueGrowth'] ?? null);
        $currentOperatingMargin = $this->number($current['operatingMargin'] ?? null);
        $previousOperatingMargin = $this->number($previousYear['operatingMargin'] ?? null);
        $currentRoic = $this->number($current['roic'] ?? null);
        $previousRoic = $this->number($previousYear['roic'] ?? null);
        $currentDebtToEquity = $this->number($current['debtToEquity'] ?? null);
        $previousDebtToEquity = $this->number($previousYear['debtToEquity'] ?? null);
        $currentFcf = $this->number($current['freeCashFlow'] ?? null);
        $previousFcf = $this->number($previousYear['freeCashFlow'] ?? null);
        $currentMarketCap = $this->number($current['marketCap'] ?? null);

        return [
            'revenue_growth_acceleration' => $this->difference($currentRevenueGrowth, $previousRevenueGrowth),
            'operating_margin_change' => $this->difference($currentOperatingMargin, $previousOperatingMargin),
            'roic_change' => $this->difference($currentRoic, $previousRoic),
            // Deuda menor es mejor, por eso se invierte la resta.
            'debt_to_equity_improvement' => $this->difference($previousDebtToEquity, $currentDebtToEquity),
            // Cambio de caja en puntos de rentabilidad sobre la
            // capitalizacion disponible en la fecha de senal.
            'fcf_delta_yield' => $currentFcf !== null
                && $previousFcf !== null
                && $currentMarketCap !== null
                && $currentMarketCap > 0.0
                    ? (($currentFcf - $previousFcf) / $currentMarketCap) * 100.0
                    : null,
        ];
    }

    private function difference(?float $current, ?float $previous): ?float
    {
        return $current !== null && $previous !== null ? $current - $previous : null;
    }

    private function number(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
