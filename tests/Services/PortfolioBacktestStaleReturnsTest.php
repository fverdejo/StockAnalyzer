<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Services\PortfolioBacktestSimulator;

final class PortfolioBacktestStaleReturnsTest extends TestCase
{
    public function testHuecosYRecuperacionNoEntranEnRatiosDiarios(): void
    {
        $result = $this->simulator()->simulate([
            $this->day('2026-01-02', 100.0, 'BUY'),
            $this->day('2026-01-05', null),
            $this->day('2026-01-06', null),
            $this->day('2026-01-07', 110.0),
            $this->day('2026-01-08', 121.0),
        ]);

        self::assertSame([0.0, null, null, null, 10.0], array_column($result['equity_curve'], 'daily_return_pct'));
        self::assertSame([false, true, true, false, false], array_column($result['equity_curve'], 'portfolio_is_stale'));
        self::assertSame(2, $result['stale_portfolio_days']);
        self::assertSame(3, $result['portfolio_returns_excluded_by_stale_marks']);
        self::assertSame(5, $result['performance']['portfolio']['observations']);
        self::assertSame(2, $result['performance']['portfolio']['return_observations']);
        self::assertSame(5, $result['performance']['benchmark']['return_observations']);
        self::assertSame(2, $result['performance']['relative']['paired_return_observations']);
        self::assertSame(21.0, $result['total_return_pct']);
        self::assertNotNull($result['valuation_warning']);
    }

    public function testVentaEnRecuperacionNoTransformaSaltoAcumuladoEnRetornoDiario(): void
    {
        $result = $this->simulator()->simulate([
            $this->day('2026-01-02', 100.0, 'BUY'),
            $this->day('2026-01-05', null, 'SELL'),
            $this->day('2026-01-06', 110.0),
            $this->day('2026-01-07', null),
        ]);

        self::assertSame([0.0, null, null, 0.0], array_column($result['equity_curve'], 'daily_return_pct'));
        self::assertSame([false, true, false, false], array_column($result['equity_curve'], 'portfolio_is_stale'));
        self::assertSame([], $result['open_positions']);
        self::assertSame('signal_pending', $result['ledger'][1]['reason']);
        self::assertSame(1100.0, $result['final_equity']);
        self::assertSame(2, $result['performance']['portfolio']['return_observations']);
        self::assertSame(2, $result['performance']['relative']['paired_return_observations']);
    }

    public function testUnSoloActivoSinPrecioInvalidaElRetornoCompletoDeLaCartera(): void
    {
        $result = $this->simulator(0.5)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => [
                    'AAA' => ['open' => 100.0, 'close' => 100.0, 'volume' => 1000.0],
                    'BBB' => ['open' => 100.0, 'close' => 100.0, 'volume' => 1000.0],
                ],
                'orders' => [
                    ['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0],
                    ['ticker' => 'BBB', 'action' => 'BUY', 'priority' => 1.0],
                ],
            ],
            $this->day('2026-01-05', 120.0),
        ]);

        self::assertSame(1100.0, $result['final_equity']);
        self::assertTrue($result['equity_curve'][1]['portfolio_is_stale']);
        self::assertNull($result['equity_curve'][1]['daily_return_pct']);
        self::assertSame(1, $result['performance']['portfolio']['return_observations']);
    }

    public function testVolumenCeroNoInvalidaUnPrecioObservado(): void
    {
        $second = $this->day('2026-01-05', 110.0, 'SELL');
        $second['quotes']['AAA']['volume'] = 0.0;
        $result = $this->simulator()->simulate([
            $this->day('2026-01-02', 100.0, 'BUY'),
            $second,
        ]);

        self::assertSame(1, $result['liquidity_blocked_sells']);
        self::assertSame(10.0, $result['equity_curve'][1]['daily_return_pct']);
        self::assertFalse($result['equity_curve'][1]['portfolio_is_stale']);
        self::assertSame(0, $result['stale_portfolio_days']);
        self::assertNull($result['valuation_warning']);
    }

    public function testCarteraEnEfectivoNoExigePreciosDeActivosNoPoseidos(): void
    {
        $result = $this->simulator()->simulate([
            $this->day('2026-01-02', null),
            $this->day('2026-01-05', null),
        ]);

        self::assertSame([0.0, 0.0], array_column($result['equity_curve'], 'daily_return_pct'));
        self::assertSame([false, false], array_column($result['equity_curve'], 'portfolio_is_stale'));
        self::assertSame(0, $result['portfolio_returns_excluded_by_stale_marks']);
        self::assertSame(2, $result['performance']['portfolio']['return_observations']);
        self::assertNull($result['valuation_warning']);
    }

    private function simulator(float $maxWeight = 1.0): PortfolioBacktestSimulator
    {
        return new PortfolioBacktestSimulator(new BacktestingConfig(0.0, 1000.0, 2, $maxWeight, 1.0));
    }

    /** @return array<string,mixed> */
    private function day(string $date, ?float $price, ?string $action = null): array
    {
        return [
            'date' => $date,
            'quotes' => $price === null ? [] : [
                'AAA' => ['open' => $price, 'close' => $price, 'volume' => 1000.0],
            ],
            'orders' => $action === null ? [] : [
                ['ticker' => 'AAA', 'action' => $action, 'priority' => 1.0],
            ],
            'benchmark' => ['open' => 100.0, 'close' => 100.0],
        ];
    }
}
