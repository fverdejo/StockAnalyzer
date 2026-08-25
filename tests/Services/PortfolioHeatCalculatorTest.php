<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Services\PortfolioHeatCalculator;

final class PortfolioHeatCalculatorTest extends TestCase
{
    private function calculator(): PortfolioHeatCalculator
    {
        return new PortfolioHeatCalculator();
    }

    public function testSumaElRiesgoDeCadaPosicionComoPorcentajeDelTotal(): void
    {
        // AAA: 10 acciones a 100€, stop en 95€ -> riesgo 5€/accion -> 50€.
        // BBB: 5 acciones a 200€, stop en 190€ -> riesgo 10€/accion -> 50€.
        // Total cartera: 1.000€ + 1.000€ = 2.000€. Calor total: 100€/2.000€ = 5%.
        $holdings = [
            new Holding('AAA', 10.0, 100.0, 100.0),
            new Holding('BBB', 5.0, 200.0, 200.0),
        ];
        $portfolio = new Portfolio(
            $holdings,
            [],
            0.0,
            ['AAA' => 100.0, 'BBB' => 200.0],
            ['AAA' => 'EUR', 'BBB' => 'EUR']
        );
        $riskLevels = [
            'AAA' => RiskLevels::compute(100.0, 2.0, 2.5, 2.0),
            'BBB' => RiskLevels::compute(200.0, 4.0, 2.5, 2.0),
        ];

        $heat = $this->calculator()->compute($portfolio, $riskLevels);

        self::assertNotNull($heat);
        self::assertEqualsWithDelta(2000.0, $heat->getTotalValueEur(), 0.0001);
        self::assertEqualsWithDelta(2.5, $heat->getRiskWeights()['AAA'], 0.0001);
        self::assertEqualsWithDelta(2.5, $heat->getRiskWeights()['BBB'], 0.0001);
        self::assertEqualsWithDelta(5.0, $heat->getTotalHeatPercent(), 0.0001);
        self::assertFalse($heat->isHot());
        self::assertSame([], $heat->getExcludedTickers());
    }

    public function testUnaPosicionSinRiskLevelsSeExcluyeSinInvalidarElCalculo(): void
    {
        $holdings = [
            new Holding('AAA', 10.0, 100.0, 100.0),
            new Holding('BBB', 5.0, 200.0, 200.0),
        ];
        $portfolio = new Portfolio(
            $holdings,
            [],
            0.0,
            ['AAA' => 100.0, 'BBB' => 200.0],
            ['AAA' => 'EUR', 'BBB' => 'EUR']
        );
        $riskLevels = [
            'AAA' => RiskLevels::compute(100.0, 2.0, 2.5, 2.0),
            // BBB sin RiskLevels (p.ej. ATR14 con historico insuficiente).
        ];

        $heat = $this->calculator()->compute($portfolio, $riskLevels);

        self::assertNotNull($heat);
        self::assertArrayHasKey('AAA', $heat->getRiskWeights());
        self::assertArrayNotHasKey('BBB', $heat->getRiskWeights());
        self::assertSame(['BBB'], $heat->getExcludedTickers());
    }

    public function testSuperarElUmbralDeAvisoMarcaLaCarteraComoCaliente(): void
    {
        // Riesgo del 20% de la cartera: muy por encima del 15% de referencia.
        $holdings = [new Holding('AAA', 10.0, 100.0, 100.0)];
        $portfolio = new Portfolio(
            $holdings,
            [],
            0.0,
            ['AAA' => 100.0],
            ['AAA' => 'EUR']
        );
        $riskLevels = ['AAA' => RiskLevels::compute(100.0, 8.0, 2.5, 2.0)];

        $heat = $this->calculator()->compute($portfolio, $riskLevels);

        self::assertNotNull($heat);
        self::assertTrue($heat->isHot());
    }

    public function testSinPosicionesDevuelveNull(): void
    {
        $portfolio = new Portfolio([], [], 0.0);

        self::assertNull($this->calculator()->compute($portfolio, []));
    }

    public function testConTodasLasPosicionesExcluidasDevuelveNull(): void
    {
        $holdings = [new Holding('AAA', 10.0, 100.0, 100.0)];
        $portfolio = new Portfolio(
            $holdings,
            [],
            0.0,
            ['AAA' => 100.0],
            ['AAA' => 'EUR']
        );

        $heat = $this->calculator()->compute($portfolio, []);

        self::assertNull($heat);
    }
}
