<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\PortfolioBacktestSimulator;
use StockAnalyzer\Services\RecommendationPortfolioBacktestService;
use StockAnalyzer\Services\RecommendationPortfolioDatasetBuilder;

final class RecommendationPortfolioBacktestServiceTest extends TestCase
{
    public function testUnaRecomendacionPointInTimeLlegaAlLedgerEnLaAperturaSiguiente(): void
    {
        $history = [
            new HistoricalQuote(new DateTimeImmutable('2026-01-02'), 100.0, 101.0, 99.0, 100.0, 1000),
            new HistoricalQuote(new DateTimeImmutable('2026-01-05'), 102.0, 105.0, 101.0, 104.0, 1000),
            new HistoricalQuote(new DateTimeImmutable('2026-01-06'), 104.0, 106.0, 103.0, 105.0, 1000),
        ];
        $timeline = [[
            'date' => '2026-01-02',
            'index' => 0,
            'recommendation' => 'BUY',
            'score_percentage' => 80.0,
            'fundamentals_is_point_in_time' => true,
            'stop_loss' => 90.0,
            'candidate_stop' => 90.0,
            'fundamental_change' => null,
            'entry_price' => 102.0,
            'eligible' => true,
        ]];

        $backtesting = new class ($history, $timeline) extends BacktestingService {
            public function __construct(
                private readonly array $fixtureHistory,
                private readonly array $fixtureTimeline
            ) {
            }

            public function historyFor(string $ticker, ?DateTimeImmutable $asOf = null): array
            {
                return $this->fixtureHistory;
            }

            public function replayTimeline(string $ticker, int $step = 5, ?DateTimeImmutable $asOf = null, ?string $indexCode = null): array
            {
                return $this->fixtureTimeline;
            }
        };
        $service = new RecommendationPortfolioBacktestService(
            $backtesting,
            new RecommendationPortfolioDatasetBuilder(true, 1),
            new PortfolioBacktestSimulator(new BacktestingConfig(0.0, 1000.0, 1, 1.0, 1.0))
        );

        $result = $service->run(['AAA'], step: 5, asOf: new DateTimeImmutable('2026-01-06'), indexCode: 'GSPC');

        self::assertSame(1, $result['tickers_simulated']);
        self::assertSame('2026-01-06', $result['as_of']);
        self::assertSame('2026-01-05', $result['ledger'][0]['date']);
        self::assertSame('AAA', $result['ledger'][0]['ticker']);
        self::assertSame('BUY', $result['ledger'][0]['action']);
        self::assertSame(102.0, $result['ledger'][0]['price']);
        self::assertSame(2, $result['calendar_days']);
        self::assertSame([], $result['errors']);
        self::assertSame(100.0, $result['fundamentals_point_in_time_pct']);
        self::assertTrue($result['strict_point_in_time_recommendations']);
        self::assertSame(100.0, $result['buy_orders_point_in_time_pct']);
        self::assertSame('GSPC', $result['index_code']);
        self::assertStringContainsString('Membresia point-in-time aplicada', $result['membership_warning']);
        self::assertStringContainsString('dividendGrowth5y', $result['point_in_time_warning']);
        self::assertSame('SPY', $result['benchmark_ticker']);
        self::assertNull($result['benchmark_error']);
        self::assertNotNull($result['benchmark_total_return_pct']);
    }
}
