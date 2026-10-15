<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\PortfolioBacktestSimulator;
use StockAnalyzer\Services\RecommendationPortfolioDatasetBuilder;

final class RecommendationPortfolioDatasetBuilderTest extends TestCase
{
    /**
     * @return list<HistoricalQuote>
     */
    private function history(string $ticker): array
    {
        $offset = $ticker === 'AAA' ? 0.0 : 10.0;

        return [
            new HistoricalQuote(new DateTimeImmutable('2026-01-02'), 100.0 + $offset, 102.0 + $offset, 99.0 + $offset, 101.0 + $offset, 1000),
            new HistoricalQuote(new DateTimeImmutable('2026-01-05'), 102.0 + $offset, 104.0 + $offset, 98.0 + $offset, 103.0 + $offset, 1100),
            new HistoricalQuote(new DateTimeImmutable('2026-01-06'), 104.0 + $offset, 105.0 + $offset, 100.0 + $offset, 102.0 + $offset, 1200),
        ];
    }

    /**
     * @return array{date: string, index: int, recommendation: string, score_percentage: float, fundamentals_is_point_in_time: bool, stop_loss: ?float, candidate_stop: ?float, fundamental_change: mixed, entry_price: ?float, eligible: bool}
     */
    private function point(int $index, string $recommendation, float $score, bool $eligible = true): array
    {
        return [
            'date' => $index === 0 ? '2026-01-02' : '2026-01-05',
            'index' => $index,
            'recommendation' => $recommendation,
            'score_percentage' => $score,
            'fundamentals_is_point_in_time' => $index === 0,
            'stop_loss' => $recommendation === 'BUY' ? 90.0 : null,
            'candidate_stop' => 90.0,
            'fundamental_change' => null,
            'entry_price' => 102.0,
            'eligible' => $eligible,
        ];
    }

    public function testEjecutaEnLaAperturaSiguienteYConservaPrioridadStopYOhlcv(): void
    {
        $builder = new RecommendationPortfolioDatasetBuilder(true, 1);
        $result = $builder->build(
            ['AAA' => $this->history('AAA'), 'BBB' => $this->history('BBB')],
            [
                'AAA' => [$this->point(0, 'BUY', 82.5)],
                'BBB' => [$this->point(0, 'BUY', 70.0, false)],
            ],
            $this->history('AAA')
        );

        self::assertSame('2026-01-05', $result['days'][0]['date']);
        self::assertSame(102.0, $result['days'][0]['quotes']['AAA']['open']);
        self::assertSame(98.0, $result['days'][0]['quotes']['AAA']['low']);
        self::assertSame(1000.0, $result['days'][0]['quotes']['AAA']['volume']);
        self::assertSame('BUY', $result['days'][0]['orders'][0]['action']);
        self::assertSame(82.5, $result['days'][0]['orders'][0]['priority']);
        self::assertSame(90.0, $result['days'][0]['orders'][0]['stop_loss']);
        self::assertSame(1, $result['buy_orders_excluded_by_membership']);
        self::assertSame(100.0, $result['fundamentals_point_in_time_pct']);
        self::assertSame(2, $result['benchmark_quotes_available']);
        self::assertSame(102.0, $result['days'][0]['benchmark']['open']);
        self::assertSame('trailing_average_volume_before_open', $result['liquidity_volume_method']);
        self::assertSame(1, $result['liquidity_lookback_sessions']);
    }

    public function testMapeaSellStrongSellYElRestoAHoldSinMirarRetornosFuturos(): void
    {
        $result = (new RecommendationPortfolioDatasetBuilder(false))->build(
            ['AAA' => $this->history('AAA')],
            ['AAA' => [
                $this->point(0, 'STRONG SELL', 10.0),
                $this->point(1, 'HOLD', 50.0),
            ]]
        );

        self::assertSame(['SELL', 'HOLD'], array_values(array_filter(
            array_map(
                static fn (array $day): ?string => $day['orders'][0]['action'] ?? null,
                $result['days']
            )
        )));
        self::assertSame(['BUY' => 0, 'SELL' => 1, 'HOLD' => 1], $result['orders_by_action']);
        self::assertSame(50.0, $result['fundamentals_point_in_time_pct']);
    }

    public function testModoEstrictoExcluyeRecomendacionSinSnapshotPointInTime(): void
    {
        $strict = (new RecommendationPortfolioDatasetBuilder())->build(
            ['AAA' => $this->history('AAA')],
            ['AAA' => [$this->point(1, 'BUY', 80.0)]]
        );
        $exploratory = (new RecommendationPortfolioDatasetBuilder(false))->build(
            ['AAA' => $this->history('AAA')],
            ['AAA' => [$this->point(1, 'BUY', 80.0)]]
        );

        self::assertTrue($strict['strict_point_in_time_recommendations']);
        self::assertSame(1, $strict['recommendations_excluded_by_missing_pit']);
        self::assertSame(1, $strict['buy_orders_excluded_by_missing_pit']);
        self::assertSame(0.0, $strict['buy_orders_point_in_time_pct']);
        self::assertSame([], $strict['days']);
        self::assertFalse($exploratory['strict_point_in_time_recommendations']);
        self::assertSame('BUY', $exploratory['days'][0]['orders'][0]['action']);
        self::assertSame(1, $exploratory['buy_orders_without_liquidity_estimate']);
    }

    public function testLiquidezUsaSoloElVolumenAnteriorALaApertura(): void
    {
        $result = (new RecommendationPortfolioDatasetBuilder(false, 2))->build(
            ['AAA' => $this->history('AAA')],
            ['AAA' => [$this->point(1, 'BUY', 80.0)]]
        );

        self::assertSame(1050.0, $result['days'][0]['quotes']['AAA']['volume']);
        self::assertSame(1, $result['liquidity_quotes_total']);
        self::assertSame(1, $result['liquidity_quotes_with_estimate']);
        self::assertSame(0, $result['liquidity_quotes_missing_estimate']);
        self::assertSame(0, $result['buy_orders_without_liquidity_estimate']);
    }

    public function testUltimaRecomendacionSinSesionPosteriorQuedaDiagnosticada(): void
    {
        $result = (new RecommendationPortfolioDatasetBuilder())->build(
            ['AAA' => $this->history('AAA')],
            ['AAA' => [$this->point(2, 'BUY', 80.0)]]
        );

        self::assertSame([], $result['days']);
        self::assertSame(1, $result['recommendations_without_next_session']);
    }

    public function testCalendarioConBenchmarkConservaHuecoYExcluyeSuRecuperacionDeRatios(): void
    {
        $result = (new RecommendationPortfolioDatasetBuilder(true, 1))->build(
            ['AAA' => [
                $this->flatQuote('2026-01-02', 100.0),
                $this->flatQuote('2026-01-05', 100.0),
                $this->flatQuote('2026-01-07', 110.0),
                $this->flatQuote('2026-01-08', 121.0),
            ]],
            ['AAA' => [$this->point(0, 'BUY', 80.0)]],
            [
                $this->flatQuote('2026-01-02', 100.0),
                $this->flatQuote('2026-01-05', 100.0),
                $this->flatQuote('2026-01-06', 100.0),
                $this->flatQuote('2026-01-07', 100.0),
                $this->flatQuote('2026-01-08', 100.0),
            ]
        );

        self::assertSame(
            ['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08'],
            array_column($result['days'], 'date')
        );
        self::assertSame([], $result['days'][1]['quotes']);
        self::assertSame([], $result['days'][1]['orders']);
        self::assertSame('asset_and_benchmark_quotes_union', $result['calendar_source']);
        self::assertSame(1, $result['calendar_days_without_asset_quotes']);
        self::assertIsString($result['calendar_warning']);
        self::assertNotSame('', $result['calendar_warning']);

        $simulation = (new PortfolioBacktestSimulator(
            new BacktestingConfig(0.0, 1000.0, 1, 1.0, 1.0)
        ))->simulate($result['days']);

        self::assertSame([0.0, null, null, 10.0], array_column($simulation['equity_curve'], 'daily_return_pct'));
        self::assertSame([false, true, false, false], array_column($simulation['equity_curve'], 'portfolio_is_stale'));
        self::assertSame(1, $simulation['stale_portfolio_days']);
        self::assertSame(2, $simulation['portfolio_returns_excluded_by_stale_marks']);
        self::assertSame(2, $simulation['performance']['portfolio']['return_observations']);
        self::assertSame(2, $simulation['performance']['relative']['paired_return_observations']);
        self::assertSame(4, $simulation['performance']['benchmark']['return_observations']);
        self::assertSame(1210.0, $simulation['final_equity']);
    }

    public function testCalendarioConservaBenchmarkPosteriorAlUltimoPrecioDelActivo(): void
    {
        $result = (new RecommendationPortfolioDatasetBuilder(true, 1))->build(
            ['AAA' => [
                $this->flatQuote('2026-01-02', 100.0),
                $this->flatQuote('2026-01-05', 100.0),
            ]],
            ['AAA' => [$this->point(0, 'BUY', 80.0)]],
            [
                $this->flatQuote('2026-01-02', 100.0),
                $this->flatQuote('2026-01-05', 100.0),
                $this->flatQuote('2026-01-06', 110.0),
            ]
        );

        self::assertSame(['2026-01-05', '2026-01-06'], array_column($result['days'], 'date'));
        self::assertSame([], $result['days'][1]['quotes']);
        self::assertSame([], $result['days'][1]['orders']);
        self::assertSame(1, $result['calendar_days_without_asset_quotes']);

        $simulation = (new PortfolioBacktestSimulator(
            new BacktestingConfig(0.0, 1000.0, 1, 1.0, 1.0)
        ))->simulate($result['days']);

        self::assertTrue($simulation['equity_curve'][1]['portfolio_is_stale']);
        self::assertNull($simulation['equity_curve'][1]['daily_return_pct']);
        self::assertSame(1, $simulation['stale_portfolio_days']);
        self::assertNotNull($simulation['valuation_warning']);
        self::assertSame(1, $simulation['performance']['portfolio']['return_observations']);
        self::assertSame(2, $simulation['performance']['benchmark']['return_observations']);
        self::assertSame(1000.0, $simulation['final_equity']);
        self::assertSame(1100.0, $simulation['benchmark_final_equity']);
    }

    public function testCalendarioUnionOrdenadoUnicoConservaDiasSoloDelActivoYExcluyeHistoriaPrevia(): void
    {
        $result = (new RecommendationPortfolioDatasetBuilder(true, 1))->build(
            ['AAA' => [
                $this->flatQuote('2026-01-02', 100.0),
                $this->flatQuote('2026-01-05', 100.0),
                $this->flatQuote('2026-01-07', 110.0),
                $this->flatQuote('2026-01-08', 121.0),
            ]],
            ['AAA' => [$this->point(0, 'BUY', 80.0)]],
            [
                $this->flatQuote('2026-01-06', 100.0),
                $this->flatQuote('2026-01-02', 100.0),
                $this->flatQuote('2026-01-05', 100.0),
                $this->flatQuote('2026-01-06', 100.0),
            ]
        );

        self::assertSame(
            ['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08'],
            array_column($result['days'], 'date')
        );
        self::assertSame(110.0, $result['days'][2]['quotes']['AAA']['close']);
        self::assertNull($result['days'][2]['benchmark']);
        self::assertSame(121.0, $result['days'][3]['quotes']['AAA']['close']);
        self::assertNull($result['days'][3]['benchmark']);
        self::assertSame(2, $result['benchmark_quotes_available']);
        self::assertSame(1, $result['calendar_days_without_asset_quotes']);
        self::assertSame('asset_and_benchmark_quotes_union', $result['calendar_source']);
    }

    public function testCalendarioSinBenchmarkNoInventaSesionesYDeclaraLimitacion(): void
    {
        foreach ([[], [$this->flatQuote('2026-01-02', 100.0)]] as $benchmark) {
            $result = (new RecommendationPortfolioDatasetBuilder(true, 1))->build(
                ['AAA' => [
                    $this->flatQuote('2026-01-02', 100.0),
                    $this->flatQuote('2026-01-05', 100.0),
                    $this->flatQuote('2026-01-07', 110.0),
                ]],
                ['AAA' => [$this->point(0, 'BUY', 80.0)]],
                $benchmark
            );

            self::assertSame(['2026-01-05', '2026-01-07'], array_column($result['days'], 'date'));
            self::assertSame('asset_quotes_only', $result['calendar_source']);
            self::assertSame(0, $result['calendar_days_without_asset_quotes']);
            self::assertSame(0, $result['benchmark_quotes_available']);
            self::assertIsString($result['calendar_warning']);
            self::assertNotSame('', $result['calendar_warning']);
        }
    }

    public function testCalendarioSinOrdenesEjecutablesNoSeCreaSoloPorBenchmark(): void
    {
        $result = (new RecommendationPortfolioDatasetBuilder())->build(
            ['AAA' => $this->history('AAA')],
            ['AAA' => []],
            $this->history('AAA')
        );

        self::assertSame([], $result['days']);
        self::assertSame('no_execution_calendar', $result['calendar_source']);
        self::assertSame(0, $result['calendar_days_without_asset_quotes']);
        self::assertNull($result['calendar_warning']);
    }

    private function flatQuote(string $date, float $price): HistoricalQuote
    {
        return new HistoricalQuote(new DateTimeImmutable($date), $price, $price, $price, $price, 1000);
    }
}
