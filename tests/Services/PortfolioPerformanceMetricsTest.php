<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\PortfolioPerformanceMetrics;

final class PortfolioPerformanceMetricsTest extends TestCase
{
    public function testResumeRendimientoRiesgoExposicionYComparacionRelativa(): void
    {
        $metrics = (new PortfolioPerformanceMetrics())->summarize([
            [
                'date' => '2025-01-01',
                'equity' => 1000.0,
                'daily_return_pct' => 0.0,
                'drawdown_pct' => 0.0,
                'gross_exposure_pct' => 50.0,
                'benchmark_equity' => 1000.0,
                'benchmark_daily_return_pct' => 0.0,
                'benchmark_drawdown_pct' => 0.0,
            ],
            [
                'date' => '2025-07-02',
                'equity' => 1100.0,
                'daily_return_pct' => 10.0,
                'drawdown_pct' => 0.0,
                'gross_exposure_pct' => 100.0,
                'benchmark_equity' => 1050.0,
                'benchmark_daily_return_pct' => 5.0,
                'benchmark_drawdown_pct' => 0.0,
            ],
            [
                'date' => '2026-01-01',
                'equity' => 990.0,
                'daily_return_pct' => -10.0,
                'drawdown_pct' => -10.0,
                'gross_exposure_pct' => 75.0,
                'benchmark_equity' => 1039.5,
                'benchmark_daily_return_pct' => -1.0,
                'benchmark_drawdown_pct' => -1.0,
            ],
        ], 1000.0);

        self::assertSame(3, $metrics['portfolio']['return_observations']);
        self::assertEqualsWithDelta(-1.0, $metrics['portfolio']['total_return_pct'], 0.000001);
        self::assertEqualsWithDelta(158.745079, $metrics['portfolio']['annualized_volatility_pct'], 0.000001);
        self::assertEqualsWithDelta(0.0, $metrics['portfolio']['sharpe_ratio_zero_rf'], 0.000001);
        self::assertEqualsWithDelta(-10.0, $metrics['portfolio']['max_drawdown_pct'], 0.000001);
        self::assertEqualsWithDelta(3.95, $metrics['benchmark']['total_return_pct'], 0.000001);
        self::assertSame(3, $metrics['relative']['paired_return_observations']);
        self::assertNotNull($metrics['relative']['annualized_tracking_error_pct']);
        self::assertNotNull($metrics['relative']['information_ratio']);
        self::assertEqualsWithDelta(75.0, $metrics['average_gross_exposure_pct'], 0.000001);
    }

    public function testNoFabricaRatioCuandoNoHayMuestraSuficiente(): void
    {
        $metrics = (new PortfolioPerformanceMetrics())->summarize([
            [
                'date' => '2026-01-02',
                'equity' => 1000.0,
                'daily_return_pct' => 0.0,
                'drawdown_pct' => 0.0,
                'gross_exposure_pct' => 0.0,
                'benchmark_equity' => null,
                'benchmark_daily_return_pct' => null,
                'benchmark_drawdown_pct' => null,
            ],
        ], 1000.0);

        self::assertNull($metrics['portfolio']['annualized_return_pct']);
        self::assertNull($metrics['portfolio']['annualized_volatility_pct']);
        self::assertNull($metrics['portfolio']['sharpe_ratio_zero_rf']);
        self::assertNull($metrics['benchmark']);
        self::assertNull($metrics['relative']);
    }
}
