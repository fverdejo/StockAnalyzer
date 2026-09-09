<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\FundamentalMomentumCalculator;

final class FundamentalMomentumCalculatorTest extends TestCase
{
    public function testOrientaTodosLosCambiosDeFormaQueMayorSeaMejor(): void
    {
        $factors = (new FundamentalMomentumCalculator())->calculate(
            [
                'revenueGrowth' => 12.0,
                'operatingMargin' => 18.0,
                'roic' => 15.0,
                'debtToEquity' => 0.8,
                'freeCashFlow' => 150.0,
                'marketCap' => 2_000.0,
            ],
            [
                'revenueGrowth' => 7.0,
                'operatingMargin' => 16.0,
                'roic' => 11.0,
                'debtToEquity' => 1.1,
                'freeCashFlow' => 100.0,
                'marketCap' => 1_500.0,
            ]
        );

        self::assertSame(5.0, $factors['revenue_growth_acceleration']);
        self::assertSame(2.0, $factors['operating_margin_change']);
        self::assertSame(4.0, $factors['roic_change']);
        self::assertEqualsWithDelta(0.3, $factors['debt_to_equity_improvement'], 0.000001);
        self::assertEqualsWithDelta(2.5, $factors['fcf_delta_yield'], 0.000001);
    }

    public function testNoFabricaCambiosCuandoFaltaUnoDeLosDosPeriodos(): void
    {
        $factors = (new FundamentalMomentumCalculator())->calculate(
            ['operatingMargin' => 10.0, 'freeCashFlow' => 100.0, 'marketCap' => 1_000.0],
            ['operatingMargin' => null, 'freeCashFlow' => null]
        );

        self::assertNull($factors['revenue_growth_acceleration']);
        self::assertNull($factors['operating_margin_change']);
        self::assertNull($factors['roic_change']);
        self::assertNull($factors['debt_to_equity_improvement']);
        self::assertNull($factors['fcf_delta_yield']);
    }

    public function testFcfDeltaYieldExigeCapitalizacionPositiva(): void
    {
        $calculator = new FundamentalMomentumCalculator();
        $current = ['freeCashFlow' => 150.0, 'marketCap' => 0.0];
        $previous = ['freeCashFlow' => 100.0];

        self::assertNull($calculator->calculate($current, $previous)['fcf_delta_yield']);

        $current['marketCap'] = -1_000.0;
        self::assertNull($calculator->calculate($current, $previous)['fcf_delta_yield']);
    }

    public function testAceptaNumerosSerializadosComoTextoPeroNoValoresNoFinitos(): void
    {
        $factors = (new FundamentalMomentumCalculator())->calculate(
            ['revenueGrowth' => '12.5', 'operatingMargin' => INF],
            ['revenueGrowth' => '10', 'operatingMargin' => 2.0]
        );

        self::assertSame(2.5, $factors['revenue_growth_acceleration']);
        self::assertNull($factors['operating_margin_change']);
    }
}
