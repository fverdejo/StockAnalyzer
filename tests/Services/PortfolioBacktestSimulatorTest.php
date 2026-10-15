<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Services\PortfolioBacktestSimulator;

final class PortfolioBacktestSimulatorTest extends TestCase
{
    private function simulator(
        float $costBps = 0.0,
        float $capital = 1000.0,
        int $maxPositions = 2,
        float $maxWeight = 0.5,
        float $maxParticipation = 1.0
    ): PortfolioBacktestSimulator {
        return new PortfolioBacktestSimulator(new BacktestingConfig(
            $costBps,
            $capital,
            $maxPositions,
            $maxWeight,
            $maxParticipation
        ));
    }

    public function testLasComprasCompitenPorUnCapitalComunYPrioridadDeterminista(): void
    {
        $result = $this->simulator(maxPositions: 2)->simulate([[
            'date' => '2026-01-02',
            'quotes' => [
                'AAA' => ['open' => 10.0, 'close' => 11.0, 'volume' => 1000.0],
                'BBB' => ['open' => 20.0, 'close' => 20.0, 'volume' => 1000.0],
                'CCC' => ['open' => 5.0, 'close' => 5.0, 'volume' => 1000.0],
            ],
            'orders' => [
                ['ticker' => 'CCC', 'action' => 'BUY', 'priority' => 80.0],
                ['ticker' => 'BBB', 'action' => 'BUY', 'priority' => 90.0],
                ['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 90.0],
            ],
        ]]);

        self::assertSame(['AAA', 'BBB'], array_column($result['ledger'], 'ticker'));
        self::assertSame(1, $result['rejections']['max_positions']);
        self::assertEqualsWithDelta(1050.0, $result['final_equity'], 0.000001);
        self::assertSame(2, $result['equity_curve'][0]['positions']);
    }

    public function testVendeAntesDeComprarYLiberarCapitalPermiteLaSustitucion(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 10.0, 'close' => 10.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 10.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => [
                    'AAA' => ['open' => 12.0, 'close' => 12.0, 'volume' => 1000.0],
                    'BBB' => ['open' => 20.0, 'close' => 22.0, 'volume' => 1000.0],
                ],
                'orders' => [
                    ['ticker' => 'AAA', 'action' => 'SELL', 'priority' => 0.0],
                    ['ticker' => 'BBB', 'action' => 'BUY', 'priority' => 5.0],
                ],
            ],
        ]);

        self::assertSame(['BUY', 'SELL', 'BUY'], array_column($result['ledger'], 'action'));
        self::assertArrayHasKey('BBB', $result['open_positions']);
        self::assertEqualsWithDelta(1320.0, $result['final_equity'], 0.000001);
    }

    public function testCostesPorLadoReducenEfectivoYRentabilidad(): void
    {
        $result = $this->simulator(costBps: 10.0, maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 10.0, 'close' => 10.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => ['AAA' => ['open' => 10.0, 'close' => 10.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'SELL', 'priority' => 1.0]],
            ],
        ]);

        self::assertLessThan(1000.0, $result['final_equity']);
        self::assertEqualsWithDelta(1.998002, $result['total_costs'], 0.00001);
        self::assertCount(2, $result['ledger']);
    }

    public function testLiquidezLimitaElTamanoDeLaCompra(): void
    {
        $result = $this->simulator(maxWeight: 1.0, maxParticipation: 0.10)->simulate([[
            'date' => '2026-01-02',
            'quotes' => ['AAA' => ['open' => 10.0, 'close' => 10.0, 'volume' => 20.0]],
            'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
        ]]);

        self::assertEqualsWithDelta(2.0, $result['ledger'][0]['quantity'], 0.000001);
        self::assertEqualsWithDelta(20.0, $result['ledger'][0]['gross'], 0.000001);
        self::assertEqualsWithDelta(980.0, $result['equity_curve'][0]['cash'], 0.000001);
        self::assertSame(1, $result['liquidity_limited_buys']);
    }

    public function testUnaCotizacionAusenteSeHaceVisibleComoMarcaObsoleta(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 10.0, 'close' => 11.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
            ['date' => '2026-01-05', 'quotes' => [], 'orders' => []],
        ]);

        self::assertSame(2, $result['stale_position_marks']);
        self::assertEqualsWithDelta(1100.0, $result['final_equity'], 0.000001);
    }

    public function testRechazaDiasDesordenadosParaNoCambiarLaSimulacionEnSilencio(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->simulator()->simulate([
            ['date' => '2026-01-05', 'quotes' => [], 'orders' => []],
            ['date' => '2026-01-02', 'quotes' => [], 'orders' => []],
        ]);
    }

    public function testStopIntradiaSeEjecutaAlNivelDelStop(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'low' => 99.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => ['AAA' => ['open' => 95.0, 'low' => 85.0, 'close' => 92.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'HOLD', 'priority' => 1.0]],
            ],
        ]);

        self::assertSame(1, $result['stops_triggered']);
        self::assertSame('stop_loss', $result['ledger'][1]['reason']);
        self::assertEqualsWithDelta(90.0, $result['ledger'][1]['price'], 0.000001);
        self::assertSame([], $result['open_positions']);
    }

    public function testHuecoBajistaEjecutaStopALaAperturaReal(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'low' => 99.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => ['AAA' => ['open' => 80.0, 'low' => 75.0, 'close' => 78.0, 'volume' => 1000.0]],
                'orders' => [],
            ],
        ]);

        self::assertEqualsWithDelta(80.0, $result['ledger'][1]['price'], 0.000001);
        self::assertEqualsWithDelta(800.0, $result['final_equity'], 0.000001);
        self::assertSame('stop_loss_gap', $result['ledger'][1]['reason']);
    }

    public function testStopSeEvaluaTambienDuranteLaSesionDeEntrada(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([[
            'date' => '2026-01-02',
            'quotes' => ['AAA' => ['open' => 100.0, 'low' => 85.0, 'close' => 95.0, 'volume' => 1000.0]],
            'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
        ]]);

        self::assertSame(['BUY', 'SELL'], array_column($result['ledger'], 'action'));
        self::assertSame('entry_day_stop_loss', $result['ledger'][1]['reason']);
        self::assertEqualsWithDelta(90.0, $result['ledger'][1]['price'], 0.000001);
        self::assertEqualsWithDelta(900.0, $result['final_equity'], 0.000001);
        self::assertEqualsWithDelta(190.0, $result['cumulative_turnover_pct'], 0.000001);
        self::assertSame(1, $result['entry_day_stops_triggered']);
        self::assertSame([], $result['open_positions']);
    }

    public function testStopYaVulneradoAlEntrarHaceIdaYVueltaAlOpenConDosCostes(): void
    {
        $result = $this->simulator(costBps: 10.0, maxPositions: 1, maxWeight: 1.0)->simulate([[
            'date' => '2026-01-02',
            'quotes' => ['AAA' => ['open' => 80.0, 'low' => 75.0, 'close' => 78.0, 'volume' => 1000.0]],
            'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
        ]]);

        self::assertSame(['BUY', 'SELL'], array_column($result['ledger'], 'action'));
        self::assertEqualsWithDelta(80.0, $result['ledger'][1]['price'], 0.000001);
        self::assertEqualsWithDelta(998.001998, $result['final_equity'], 0.000001);
        self::assertEqualsWithDelta(1.998002, $result['total_costs'], 0.00001);
    }

    public function testVentaPorSenalEnOpenPrecedeAlStopIntradia(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'low' => 95.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => ['AAA' => ['open' => 95.0, 'low' => 85.0, 'close' => 92.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'SELL', 'priority' => 1.0]],
            ],
        ]);

        self::assertSame('signal', $result['ledger'][1]['reason']);
        self::assertEqualsWithDelta(95.0, $result['ledger'][1]['price'], 0.000001);
        self::assertSame(0, $result['stops_triggered']);
    }

    public function testStopIntradiaNoFinanciaUnaCompraRetrospectivaEnLaApertura(): void
    {
        $result = $this->simulator(maxPositions: 2, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'low' => 95.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => [
                    'AAA' => ['open' => 100.0, 'low' => 80.0, 'close' => 85.0, 'volume' => 1000.0],
                    'BBB' => ['open' => 10.0, 'low' => 10.0, 'close' => 10.0, 'volume' => 1000.0],
                ],
                'orders' => [['ticker' => 'BBB', 'action' => 'BUY', 'priority' => 1.0]],
            ],
        ]);

        self::assertSame(1, $result['rejections']['insufficient_cash']);
        self::assertSame(['AAA', 'AAA'], array_column($result['ledger'], 'ticker'));
        self::assertEqualsWithDelta(900.0, $result['final_equity'], 0.000001);
    }

    public function testLiquidezSalidaParcialConservaContabilidadCostesYTurnover(): void
    {
        $result = $this->simulator(costBps: 100.0, capital: 1010.0, maxPositions: 1, maxWeight: 1.0, maxParticipation: 0.10)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => ['AAA' => ['open' => 110.0, 'close' => 115.0, 'volume' => 30.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'SELL', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-06',
                'quotes' => ['AAA' => ['open' => 120.0, 'close' => 118.0, 'volume' => 20.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'HOLD', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-07',
                'quotes' => ['AAA' => ['open' => 90.0, 'close' => 90.0, 'volume' => 50.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
        ]);

        self::assertSame([10.0, 3.0, 2.0, 5.0], array_column($result['ledger'], 'quantity'));
        self::assertSame(['signal', 'signal_pending', 'signal_pending'], array_column(array_slice($result['ledger'], 1), 'reason'));
        self::assertSame([10.0, 7.0, 5.0], array_column(array_slice($result['ledger'], 1), 'requested_quantity'));
        self::assertSame([7.0, 5.0, 0.0], array_column(array_slice($result['ledger'], 1), 'remaining_quantity'));
        self::assertSame([10.0, 3.3, 2.4, 4.5], array_column($result['ledger'], 'cost'));
        self::assertSame([1000.0, 805.0, 590.0, 0.0], array_column($result['equity_curve'], 'positions_value'));
        self::assertSame([0.0, 326.7, 564.3, 1009.8], array_column($result['equity_curve'], 'cash'));
        self::assertEqualsWithDelta(1009.8, $result['final_equity'], 0.000001);
        self::assertEqualsWithDelta(20.2, $result['total_costs'], 0.000001);
        $expectedTurnover = 100.0 * (1000.0 / 1010.0 + 330.0 / 1100.0 + 240.0 / 1166.7 + 450.0 / 1014.3);
        self::assertEqualsWithDelta($expectedTurnover, $result['cumulative_turnover_pct'], 0.000001);
        self::assertSame(2, $result['partial_sell_fills']);
        self::assertSame(2, $result['liquidity_limited_sells']);
        self::assertSame(0, $result['pending_exit_positions']);
        self::assertSame([], $result['pending_exits']);
        self::assertSame([], $result['open_positions']);
    }

    public function testLiquidezSalidaResidualPermaneceValoradoYOcupaPlaza(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0, maxParticipation: 0.10)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => [
                    'AAA' => ['open' => 100.0, 'close' => 90.0, 'volume' => 30.0],
                    'BBB' => ['open' => 10.0, 'close' => 10.0, 'volume' => 1000.0],
                ],
                'orders' => [
                    ['ticker' => 'AAA', 'action' => 'SELL', 'priority' => 1.0],
                    ['ticker' => 'BBB', 'action' => 'BUY', 'priority' => 1.0],
                ],
            ],
        ]);

        self::assertSame(1, $result['rejections']['max_positions']);
        self::assertSame(['AAA'], array_keys($result['open_positions']));
        self::assertEqualsWithDelta(7.0, $result['open_positions']['AAA']['quantity'], 0.000001);
        self::assertEqualsWithDelta(300.0, $result['equity_curve'][1]['cash'], 0.000001);
        self::assertEqualsWithDelta(630.0, $result['equity_curve'][1]['positions_value'], 0.000001);
        self::assertEqualsWithDelta(930.0, $result['final_equity'], 0.000001);
        self::assertSame(1, $result['pending_exit_positions']);
        self::assertSame('signal', $result['pending_exits']['AAA']['reason']);
        self::assertSame('2026-01-05', $result['pending_exits']['AAA']['trigger_date']);
        self::assertEqualsWithDelta(7.0, $result['pending_exits']['AAA']['quantity'], 0.000001);
    }

    public function testLiquidezSalidaSenalPersisteSinQuoteSinCapacidadYConHoldOBuy(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => [],
                'orders' => [['ticker' => 'AAA', 'action' => 'SELL', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-06',
                'quotes' => ['AAA' => ['open' => 110.0, 'close' => 110.0, 'volume' => 0.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'HOLD', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-07',
                'quotes' => ['AAA' => ['open' => 120.0, 'close' => 120.0, 'volume' => 5.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-08',
                'quotes' => ['AAA' => ['open' => 130.0, 'close' => 130.0, 'volume' => 5.0]],
                'orders' => [],
            ],
        ]);

        self::assertSame(['2026-01-02', '2026-01-07', '2026-01-08'], array_column($result['ledger'], 'date'));
        self::assertSame(['BUY', 'SELL', 'SELL'], array_column($result['ledger'], 'action'));
        self::assertSame([10.0, 5.0, 5.0], array_column($result['ledger'], 'quantity'));
        self::assertSame(['signal_pending', 'signal_pending'], array_column(array_slice($result['ledger'], 1), 'reason'));
        self::assertSame(1, $result['liquidity_blocked_sells']);
        self::assertEqualsWithDelta(1250.0, $result['final_equity'], 0.000001);
        self::assertSame([], $result['pending_exits']);
        self::assertSame([], $result['open_positions']);
    }

    public function testLiquidezSalidaStopPersisteAunqueRecuperePrecioYSeCuentaUnaVez(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'low' => 95.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => ['AAA' => ['open' => 95.0, 'low' => 85.0, 'close' => 92.0, 'volume' => 0.0]],
                'orders' => [],
            ],
            ['date' => '2026-01-06', 'quotes' => [], 'orders' => []],
            [
                'date' => '2026-01-07',
                'quotes' => ['AAA' => ['open' => 105.0, 'low' => 100.0, 'close' => 106.0, 'volume' => 4.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'HOLD', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-08',
                'quotes' => ['AAA' => ['open' => 110.0, 'low' => 100.0, 'close' => 112.0, 'volume' => 6.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
        ]);

        self::assertSame([10.0, 4.0, 6.0], array_column($result['ledger'], 'quantity'));
        self::assertSame([100.0, 105.0, 110.0], array_column($result['ledger'], 'price'));
        self::assertSame(['stop_loss_pending', 'stop_loss_pending'], array_column(array_slice($result['ledger'], 1), 'reason'));
        self::assertSame(1, $result['stops_triggered']);
        self::assertSame(0, $result['entry_day_stops_triggered']);
        self::assertSame(1, $result['liquidity_blocked_sells']);
        self::assertSame(1, $result['partial_sell_fills']);
        self::assertEqualsWithDelta(1080.0, $result['final_equity'], 0.000001);
        self::assertSame([], $result['pending_exits']);
        self::assertSame([], $result['open_positions']);
    }

    public function testLiquidezSalidaCompraAgotaCapacidadYStopQuedaPendiente(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0, maxParticipation: 0.10)->simulate([[
            'date' => '2026-01-02',
            'quotes' => ['AAA' => ['open' => 100.0, 'low' => 85.0, 'close' => 95.0, 'volume' => 100.0]],
            'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
        ]]);

        self::assertSame(['BUY'], array_column($result['ledger'], 'action'));
        self::assertSame(1, $result['stops_triggered']);
        self::assertSame(1, $result['entry_day_stops_triggered']);
        self::assertSame(1, $result['liquidity_blocked_sells']);
        self::assertSame(0, $result['partial_sell_fills']);
        self::assertSame(1, $result['pending_exit_positions']);
        self::assertSame('entry_day_stop_loss', $result['pending_exits']['AAA']['reason']);
        self::assertSame('2026-01-02', $result['pending_exits']['AAA']['trigger_date']);
        self::assertEqualsWithDelta(10.0, $result['pending_exits']['AAA']['quantity'], 0.000001);
        self::assertEqualsWithDelta(10.0, $result['open_positions']['AAA']['quantity'], 0.000001);
        self::assertEqualsWithDelta(950.0, $result['final_equity'], 0.000001);
        self::assertEqualsWithDelta(100.0, $result['cumulative_turnover_pct'], 0.000001);
    }

    public function testLiquidezSalidaStopDeEntradaUsaSoloCapacidadRestanteTrasCompra(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0, maxParticipation: 0.10)->simulate([[
            'date' => '2026-01-02',
            'quotes' => ['AAA' => ['open' => 100.0, 'low' => 85.0, 'close' => 95.0, 'volume' => 150.0]],
            'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
        ]]);

        self::assertSame([10.0, 5.0], array_column($result['ledger'], 'quantity'));
        self::assertSame('entry_day_stop_loss', $result['ledger'][1]['reason']);
        self::assertEqualsWithDelta(10.0, $result['ledger'][1]['requested_quantity'], 0.000001);
        self::assertEqualsWithDelta(5.0, $result['ledger'][1]['remaining_quantity'], 0.000001);
        self::assertEqualsWithDelta(5.0, $result['pending_exits']['AAA']['quantity'], 0.000001);
        self::assertEqualsWithDelta(450.0, $result['equity_curve'][0]['cash'], 0.000001);
        self::assertEqualsWithDelta(475.0, $result['equity_curve'][0]['positions_value'], 0.000001);
        self::assertEqualsWithDelta(925.0, $result['final_equity'], 0.000001);
        self::assertEqualsWithDelta(145.0, $result['cumulative_turnover_pct'], 0.000001);
        self::assertSame(1, $result['partial_sell_fills']);
        self::assertSame(1, $result['liquidity_limited_sells']);
        self::assertSame(1, $result['stops_triggered']);
    }

    public function testLiquidezSalidaStopPorGapImpideRecomprarEnLaMismaApertura(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'low' => 95.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => ['AAA' => ['open' => 80.0, 'low' => 75.0, 'close' => 78.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
        ]);

        self::assertSame(['BUY', 'SELL'], array_column($result['ledger'], 'action'));
        self::assertSame('stop_loss_gap', $result['ledger'][1]['reason']);
        self::assertSame(1, $result['stops_triggered']);
        self::assertEqualsWithDelta(800.0, $result['final_equity'], 0.000001);
        self::assertSame([], $result['open_positions']);
    }

    public function testLiquidezSalidaSenalesDuplicadasNoDuplicanCuotaNiContadores(): void
    {
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => ['AAA' => ['open' => 100.0, 'close' => 100.0, 'volume' => 3.0]],
                'orders' => [
                    ['ticker' => 'AAA', 'action' => 'SELL', 'priority' => 1.0],
                    ['ticker' => 'AAA', 'action' => 'SELL', 'priority' => 2.0],
                    ['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 3.0],
                ],
            ],
        ]);

        self::assertSame([10.0, 3.0], array_column($result['ledger'], 'quantity'));
        self::assertSame(1, $result['partial_sell_fills']);
        self::assertSame(1, $result['liquidity_limited_sells']);
        self::assertSame(0, $result['liquidity_blocked_sells']);
        self::assertEqualsWithDelta(7.0, $result['pending_exits']['AAA']['quantity'], 0.000001);
        self::assertEqualsWithDelta(1000.0, $result['final_equity'], 0.000001);
    }

    public function testLiquidezSalidaResidualDeSenalActivaStopSinDuplicarIntento(): void
    {
        $days = [
            [
                'date' => '2026-01-02',
                'quotes' => ['AAA' => ['open' => 100.0, 'low' => 95.0, 'close' => 100.0, 'volume' => 1000.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'BUY', 'priority' => 1.0, 'stop_loss' => 90.0]],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => ['AAA' => ['open' => 100.0, 'low' => 80.0, 'close' => 85.0, 'volume' => 3.0]],
                'orders' => [['ticker' => 'AAA', 'action' => 'SELL', 'priority' => 1.0]],
            ],
        ];
        $intermediate = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate($days);

        self::assertSame([10.0, 3.0], array_column($intermediate['ledger'], 'quantity'));
        self::assertSame('signal', $intermediate['ledger'][1]['reason']);
        self::assertSame('stop_loss', $intermediate['pending_exits']['AAA']['reason']);
        self::assertSame('2026-01-05', $intermediate['pending_exits']['AAA']['trigger_date']);
        self::assertEqualsWithDelta(7.0, $intermediate['pending_exits']['AAA']['quantity'], 0.000001);
        self::assertSame(1, $intermediate['partial_sell_fills']);
        self::assertSame(1, $intermediate['liquidity_limited_sells']);
        self::assertSame(0, $intermediate['liquidity_blocked_sells']);
        self::assertSame(1, $intermediate['stops_triggered']);
        self::assertEqualsWithDelta(895.0, $intermediate['final_equity'], 0.000001);

        $days[] = [
            'date' => '2026-01-06',
            'quotes' => ['AAA' => ['open' => 120.0, 'low' => 110.0, 'close' => 115.0, 'volume' => 7.0]],
            'orders' => [['ticker' => 'AAA', 'action' => 'HOLD', 'priority' => 1.0]],
        ];
        $result = $this->simulator(maxPositions: 1, maxWeight: 1.0)->simulate($days);

        self::assertSame([10.0, 3.0, 7.0], array_column($result['ledger'], 'quantity'));
        self::assertSame('stop_loss_pending', $result['ledger'][2]['reason']);
        self::assertEqualsWithDelta(120.0, $result['ledger'][2]['price'], 0.000001);
        self::assertSame(1, $result['stops_triggered']);
        self::assertSame(1, $result['partial_sell_fills']);
        self::assertSame(1, $result['liquidity_limited_sells']);
        self::assertSame(0, $result['liquidity_blocked_sells']);
        self::assertEqualsWithDelta(1140.0, $result['final_equity'], 0.000001);
        self::assertSame([], $result['pending_exits']);
        self::assertSame([], $result['open_positions']);
    }

    public function testBenchmarkUsaMismoCapitalInicioYCosteYPublicaRetornoExcedente(): void
    {
        $result = $this->simulator(costBps: 0.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => [],
                'orders' => [],
                'benchmark' => ['open' => 100.0, 'close' => 90.0],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => [],
                'orders' => [],
                'benchmark' => ['open' => 100.0, 'close' => 110.0],
            ],
        ]);

        self::assertSame('2026-01-02', $result['benchmark_entry_date']);
        self::assertEqualsWithDelta(-10.0, $result['equity_curve'][0]['benchmark_daily_return_pct'], 0.000001);
        self::assertEqualsWithDelta(22.222222, $result['equity_curve'][1]['benchmark_daily_return_pct'], 0.000001);
        self::assertEqualsWithDelta(-10.0, $result['equity_curve'][0]['benchmark_drawdown_pct'], 0.000001);
        self::assertEqualsWithDelta(-10.0, $result['benchmark_max_drawdown_pct'], 0.000001);
        self::assertEqualsWithDelta(1100.0, $result['benchmark_final_equity'], 0.000001);
        self::assertEqualsWithDelta(10.0, $result['benchmark_total_return_pct'], 0.000001);
        self::assertEqualsWithDelta(-10.0, $result['excess_return_pct'], 0.000001);
        self::assertEqualsWithDelta(-10.0, $result['equity_curve'][1]['excess_return_pct'], 0.000001);
        self::assertSame(2, $result['performance']['relative']['paired_return_observations']);
    }

    public function testHuecoDelBenchmarkNoSeConvierteEnRetornoDiarioFicticio(): void
    {
        $result = $this->simulator(costBps: 0.0)->simulate([
            [
                'date' => '2026-01-02',
                'quotes' => [],
                'orders' => [],
                'benchmark' => ['open' => 100.0, 'close' => 100.0],
            ],
            [
                'date' => '2026-01-05',
                'quotes' => [],
                'orders' => [],
                'benchmark' => null,
            ],
            [
                'date' => '2026-01-06',
                'quotes' => [],
                'orders' => [],
                'benchmark' => ['open' => 105.0, 'close' => 110.0],
            ],
            [
                'date' => '2026-01-07',
                'quotes' => [],
                'orders' => [],
                'benchmark' => ['open' => 110.0, 'close' => 121.0],
            ],
        ]);

        self::assertSame(1, $result['benchmark_stale_marks']);
        self::assertTrue($result['equity_curve'][1]['benchmark_is_stale']);
        self::assertNull($result['equity_curve'][1]['benchmark_daily_return_pct']);
        self::assertNull($result['equity_curve'][2]['benchmark_daily_return_pct']);
        self::assertEqualsWithDelta(10.0, $result['equity_curve'][3]['benchmark_daily_return_pct'], 0.000001);
        self::assertSame(2, $result['performance']['benchmark']['return_observations']);
        self::assertSame(2, $result['performance']['relative']['paired_return_observations']);
    }
}
