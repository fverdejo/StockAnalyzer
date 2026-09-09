<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\EarningsEvent;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\EarningsEventReturnCalculator;

final class EarningsEventReturnCalculatorTest extends TestCase
{
    private function quote(string $date, float $open, float $close): HistoricalQuote
    {
        return new HistoricalQuote(
            new DateTimeImmutable($date),
            $open,
            max($open, $close),
            min($open, $close),
            $close,
            1_000_000
        );
    }

    private function event(string $reportDate): EarningsEvent
    {
        return new EarningsEvent(
            'TEST',
            new DateTimeImmutable('2023-12-31'),
            new DateTimeImmutable($reportDate),
            'BeforeMarket',
            1.10,
            1.00,
            0.10,
            10.0,
            'USD'
        );
    }

    public function testEntraSiempreEnLaSesionPosteriorYCalculaAlphaContraLasMismasFechas(): void
    {
        $stock = [
            $this->quote('2024-01-02', 1.0, 1.0),
            $this->quote('2024-01-03', 100.0, 100.0),
            $this->quote('2024-01-04', 105.0, 110.0),
        ];
        $benchmark = [
            '2024-01-03' => $this->quote('2024-01-03', 200.0, 200.0),
            '2024-01-04' => $this->quote('2024-01-04', 205.0, 210.0),
        ];

        $result = (new EarningsEventReturnCalculator())->calculate(
            $this->event('2024-01-02'),
            $stock,
            $benchmark,
            1
        );

        self::assertNotNull($result);
        self::assertSame('2024-01-03', $result['entry_date']);
        self::assertSame('2024-01-04', $result['exit_date']);
        self::assertEqualsWithDelta(10.0, $result['stock_return_pct'], 0.000001);
        self::assertEqualsWithDelta(5.0, $result['benchmark_return_pct'], 0.000001);
        self::assertEqualsWithDelta(5.0, $result['market_adjusted_return_pct'], 0.000001);
    }

    public function testFinDeSemanaUsaLaPrimeraSesionPosterior(): void
    {
        $stock = [
            $this->quote('2024-01-05', 100.0, 100.0),
            $this->quote('2024-01-08', 100.0, 101.0),
            $this->quote('2024-01-09', 101.0, 102.0),
        ];
        $benchmark = [
            '2024-01-08' => $this->quote('2024-01-08', 100.0, 100.0),
            '2024-01-09' => $this->quote('2024-01-09', 100.0, 100.0),
        ];

        $result = (new EarningsEventReturnCalculator())->calculate(
            $this->event('2024-01-06'),
            $stock,
            $benchmark,
            1
        );

        self::assertNotNull($result);
        self::assertSame('2024-01-08', $result['entry_date']);
    }

    public function testNoInventaRetornoSiFaltaHorizonteOBenchmarkExacto(): void
    {
        $stock = [
            $this->quote('2024-01-03', 100.0, 100.0),
            $this->quote('2024-01-04', 100.0, 101.0),
        ];
        $calculator = new EarningsEventReturnCalculator();

        self::assertNull($calculator->calculate($this->event('2024-01-02'), $stock, [], 1));
        self::assertNull($calculator->calculate(
            $this->event('2024-01-02'),
            $stock,
            ['2024-01-03' => $stock[0], '2024-01-04' => $stock[1]],
            2
        ));
    }

    public function testNoUsaLaPrimeraVelaSiElAnuncioEsAnteriorAlInicioDelHistorico(): void
    {
        $stock = [
            $this->quote('2024-01-03', 100.0, 100.0),
            $this->quote('2024-01-04', 100.0, 101.0),
        ];
        $benchmark = [
            '2024-01-03' => $stock[0],
            '2024-01-04' => $stock[1],
        ];

        $result = (new EarningsEventReturnCalculator())->calculate(
            $this->event('2020-01-02'),
            $stock,
            $benchmark,
            1
        );

        self::assertNull($result);
    }

    public function testRechazaHorizonteCero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EarningsEventReturnCalculator())->calculate($this->event('2024-01-02'), [], [], 0);
    }
}
