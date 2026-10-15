<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;

/**
 * Resume una curva de cartera con metricas comparables y ajustadas por riesgo.
 *
 * Los ratios usan 252 sesiones y tipo libre de riesgo cero. La salida publica
 * los tamanos de muestra para que un ratio calculado con pocos retornos no se
 * interprete como evidencia suficiente.
 */
final class PortfolioPerformanceMetrics
{
    private const TRADING_DAYS_PER_YEAR = 252.0;
    private const CALENDAR_DAYS_PER_YEAR = 365.2425;
    private const EPSILON = 1.0E-12;

    /**
     * @param list<array<string,mixed>> $equityCurve
     * @return array{
     *   portfolio: array<string,mixed>,
     *   benchmark: array<string,mixed>|null,
     *   relative: array<string,mixed>|null,
     *   average_gross_exposure_pct: ?float
     * }
     */
    public function summarize(array $equityCurve, float $initialCapital): array
    {
        if ($equityCurve === [] || $initialCapital <= 0.0) {
            return [
                'portfolio' => $this->emptyPerformance(),
                'benchmark' => null,
                'relative' => null,
                'average_gross_exposure_pct' => null,
            ];
        }

        $portfolioReturns = [];
        $benchmarkReturns = [];
        $activeReturns = [];
        $benchmarkPoints = [];
        $exposures = [];

        foreach ($equityCurve as $point) {
            $portfolioReturn = $this->percentageAsDecimal($point['daily_return_pct'] ?? null);
            $benchmarkReturn = $this->percentageAsDecimal($point['benchmark_daily_return_pct'] ?? null);

            if ($portfolioReturn !== null) {
                $portfolioReturns[] = $portfolioReturn;
            }
            if ($benchmarkReturn !== null) {
                $benchmarkReturns[] = $benchmarkReturn;
            }
            if ($portfolioReturn !== null && $benchmarkReturn !== null) {
                $activeReturns[] = $portfolioReturn - $benchmarkReturn;
            }
            if (is_numeric($point['benchmark_equity'] ?? null)) {
                $benchmarkPoints[] = $point;
            }
            if (is_numeric($point['gross_exposure_pct'] ?? null)) {
                $exposures[] = (float) $point['gross_exposure_pct'];
            }
        }

        $portfolio = $this->performance(
            $equityCurve,
            $portfolioReturns,
            $initialCapital,
            'equity',
            'drawdown_pct'
        );
        $benchmark = $benchmarkPoints === []
            ? null
            : $this->performance(
                $benchmarkPoints,
                $benchmarkReturns,
                $initialCapital,
                'benchmark_equity',
                'benchmark_drawdown_pct'
            );

        return [
            'portfolio' => $portfolio,
            'benchmark' => $benchmark,
            'relative' => $benchmark === null ? null : $this->relativePerformance($activeReturns),
            'average_gross_exposure_pct' => $exposures === []
                ? null
                : round($this->mean($exposures), 6),
        ];
    }

    /**
     * @param list<array<string,mixed>> $points
     * @param list<float> $returns
     * @return array<string,mixed>
     */
    private function performance(
        array $points,
        array $returns,
        float $initialCapital,
        string $equityField,
        string $drawdownField
    ): array {
        $first = $points[0];
        $last = $points[array_key_last($points)];
        $finalEquity = (float) $last[$equityField];
        $totalReturnPct = (($finalEquity / $initialCapital) - 1.0) * 100.0;
        $periodStart = (string) $first['date'];
        $periodEnd = (string) $last['date'];
        $annualizedReturnPct = $this->annualizedReturnPct(
            $initialCapital,
            $finalEquity,
            $periodStart,
            $periodEnd
        );
        $standardDeviation = $this->sampleStandardDeviation($returns);
        $meanReturn = $returns === [] ? null : $this->mean($returns);
        $annualizedVolatilityPct = $standardDeviation === null
            ? null
            : $standardDeviation * sqrt(self::TRADING_DAYS_PER_YEAR) * 100.0;
        $sharpe = $standardDeviation === null || $standardDeviation <= self::EPSILON || $meanReturn === null
            ? null
            : ($meanReturn / $standardDeviation) * sqrt(self::TRADING_DAYS_PER_YEAR);
        $downsideDeviation = $this->downsideDeviation($returns);
        $sortino = $downsideDeviation === null || $downsideDeviation <= self::EPSILON || $meanReturn === null
            ? null
            : ($meanReturn / $downsideDeviation) * sqrt(self::TRADING_DAYS_PER_YEAR);
        $maxDrawdownPct = $this->minimumField($points, $drawdownField);
        $calmar = $annualizedReturnPct === null
            || $maxDrawdownPct === null
            || abs($maxDrawdownPct) <= self::EPSILON
                ? null
                : $annualizedReturnPct / abs($maxDrawdownPct);
        $positiveReturns = count(array_filter(
            $returns,
            static fn (float $return): bool => $return > 0.0
        ));

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'observations' => count($points),
            'return_observations' => count($returns),
            'final_equity' => round($finalEquity, 6),
            'total_return_pct' => round($totalReturnPct, 6),
            'annualized_return_pct' => $annualizedReturnPct === null ? null : round($annualizedReturnPct, 6),
            'annualized_volatility_pct' => $annualizedVolatilityPct === null ? null : round($annualizedVolatilityPct, 6),
            'sharpe_ratio_zero_rf' => $sharpe === null ? null : round($sharpe, 6),
            'sortino_ratio_zero_target' => $sortino === null ? null : round($sortino, 6),
            'calmar_ratio' => $calmar === null ? null : round($calmar, 6),
            'max_drawdown_pct' => $maxDrawdownPct === null ? null : round($maxDrawdownPct, 6),
            'positive_return_days_pct' => $returns === []
                ? null
                : round(($positiveReturns / count($returns)) * 100.0, 6),
        ];
    }

    /**
     * @param list<float> $activeReturns
     * @return array<string,mixed>
     */
    private function relativePerformance(array $activeReturns): array
    {
        $trackingError = $this->sampleStandardDeviation($activeReturns);
        $meanActiveReturn = $activeReturns === [] ? null : $this->mean($activeReturns);
        $informationRatio = $trackingError === null
            || $trackingError <= self::EPSILON
            || $meanActiveReturn === null
                ? null
                : ($meanActiveReturn / $trackingError) * sqrt(self::TRADING_DAYS_PER_YEAR);

        return [
            'paired_return_observations' => count($activeReturns),
            'mean_daily_excess_return_pct' => $meanActiveReturn === null
                ? null
                : round($meanActiveReturn * 100.0, 6),
            'annualized_tracking_error_pct' => $trackingError === null
                ? null
                : round($trackingError * sqrt(self::TRADING_DAYS_PER_YEAR) * 100.0, 6),
            'information_ratio' => $informationRatio === null ? null : round($informationRatio, 6),
        ];
    }

    private function annualizedReturnPct(
        float $initialCapital,
        float $finalEquity,
        string $periodStart,
        string $periodEnd
    ): ?float {
        if ($initialCapital <= 0.0 || $finalEquity <= 0.0) {
            return null;
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);

        if (!$start || !$end) {
            return null;
        }

        $days = (int) $start->diff($end)->format('%r%a');
        if ($days <= 0) {
            return null;
        }

        $years = $days / self::CALENDAR_DAYS_PER_YEAR;

        return ((($finalEquity / $initialCapital) ** (1.0 / $years)) - 1.0) * 100.0;
    }

    /**
     * @param list<float> $values
     */
    private function sampleStandardDeviation(array $values): ?float
    {
        if (count($values) < 2) {
            return null;
        }

        $mean = $this->mean($values);
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / (count($values) - 1));
    }

    /**
     * @param list<float> $returns
     */
    private function downsideDeviation(array $returns): ?float
    {
        if ($returns === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($returns as $return) {
            $sum += min(0.0, $return) ** 2;
        }

        return sqrt($sum / count($returns));
    }

    /**
     * @param list<float> $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }

    private function percentageAsDecimal(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $percentage = (float) $value;

        return is_finite($percentage) ? $percentage / 100.0 : null;
    }

    /**
     * @param list<array<string,mixed>> $points
     */
    private function minimumField(array $points, string $field): ?float
    {
        $values = [];
        foreach ($points as $point) {
            if (is_numeric($point[$field] ?? null)) {
                $values[] = (float) $point[$field];
            }
        }

        return $values === [] ? null : min($values);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyPerformance(): array
    {
        return [
            'period_start' => null,
            'period_end' => null,
            'observations' => 0,
            'return_observations' => 0,
            'final_equity' => null,
            'total_return_pct' => null,
            'annualized_return_pct' => null,
            'annualized_volatility_pct' => null,
            'sharpe_ratio_zero_rf' => null,
            'sortino_ratio_zero_target' => null,
            'calmar_ratio' => null,
            'max_drawdown_pct' => null,
            'positive_return_days_pct' => null,
        ];
    }
}
