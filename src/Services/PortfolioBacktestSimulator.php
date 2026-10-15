<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use StockAnalyzer\Config\BacktestingConfig;

/**
 * C11: libro diario de una cartera con capital compartido.
 *
 * El llamador entrega ordenes ya conocidas antes de la apertura de cada
 * fecha. Esta clase no desplaza senales ni decide cuando estuvieron
 * disponibles: separar generacion y ejecucion evita esconder look-ahead.
 *
 * Las ventas se ejecutan primero. Las compras compiten por huecos y capital,
 * ordenadas por prioridad descendente y ticker ascendente como desempate
 * estable. Se permiten acciones fraccionarias, igual que la cartera personal
 * de la aplicacion.
 */
final class PortfolioBacktestSimulator
{
    private const EPSILON = 1.0E-9;

    public function __construct(
        private readonly BacktestingConfig $config = new BacktestingConfig(),
        private readonly PortfolioPerformanceMetrics $performanceMetrics = new PortfolioPerformanceMetrics()
    ) {
    }

    /**
     * @param list<array{
     *   date: string,
     *   quotes: array<string,array{open: float, close: float, volume: float, low?: float}>,
     *   orders: list<array{ticker: string, action: string, priority: float, stop_loss?: float}>,
     *   benchmark?: array{open: float, close: float}|null
     * }> $days
     * @return array<string,mixed>
     */
    public function simulate(array $days): array
    {
        $cash = $this->config->getInitialCapital();
        $positions = [];
        $pendingExits = [];
        $ledger = [];
        $equityCurve = [];
        $lastDate = null;
        $previousEquity = $cash;
        $peakEquity = $cash;
        $maxDrawdownPct = 0.0;
        $totalCosts = 0.0;
        $totalTurnover = 0.0;
        $staleMarks = 0;
        $stalePortfolioDays = 0;
        $portfolioReturnsExcludedByStaleMarks = 0;
        $previousPortfolioIsStale = false;
        $stopsTriggered = 0;
        $entryDayStopsTriggered = 0;
        $liquidityLimitedBuys = 0;
        $liquidityLimitedSells = 0;
        $liquidityBlockedSells = 0;
        $partialSellFills = 0;
        $stopsNotEvaluable = 0;
        $benchmarkUnits = null;
        $benchmarkLastClose = null;
        $benchmarkEntryDate = null;
        $benchmarkEntryCost = null;
        $benchmarkPeak = $this->config->getInitialCapital();
        $benchmarkMaxDrawdownPct = null;
        $benchmarkStaleMarks = 0;
        $benchmarkPreviousEquity = $this->config->getInitialCapital();
        $benchmarkGapSinceLastQuote = false;
        $rejections = [
            'missing_quote' => 0,
            'max_positions' => 0,
            'already_held' => 0,
            'insufficient_cash' => 0,
            'liquidity' => 0,
        ];

        foreach ($days as $day) {
            $date = $this->validDate($day['date']);

            if ($lastDate !== null && $date <= $lastDate) {
                throw new InvalidArgumentException('Los dias deben ser unicos y estar ordenados de forma ascendente.');
            }

            $lastDate = $date;
            $quotes = $this->quotes($day['quotes']);
            $orders = $this->orders($day['orders']);
            $benchmark = $this->benchmarkQuote($day['benchmark'] ?? null);

            if ($benchmark !== null) {
                if ($benchmarkUnits === null) {
                    $benchmarkGross = $this->config->getInitialCapital() / (1.0 + $this->config->getCostRate());
                    $benchmarkEntryCost = $benchmarkGross * $this->config->getCostRate();
                    $benchmarkUnits = $benchmarkGross / $benchmark['open'];
                    $benchmarkEntryDate = $date;
                }

                $benchmarkLastClose = $benchmark['close'];
            } elseif ($benchmarkUnits !== null) {
                $benchmarkStaleMarks++;
                $benchmarkGapSinceLastQuote = true;
            }
            $equityAtOpen = $cash + $this->positionsValue($positions, $quotes, 'open', $staleMarks);
            $tradedGross = 0.0;
            $sellTickers = [];

            // Presupuesto en acciones, compartido por todas las ejecuciones
            // del ticker en la sesion. volume es la estimacion PREVIA al open.
            $remainingCapacity = [];
            foreach ($quotes as $ticker => $quote) {
                $remainingCapacity[$ticker] = $quote['volume'] * $this->config->getMaxVolumeParticipation();
            }
            $exitAttempted = [];

            // Una intencion de salida persiste hasta cerrar. HOLD/BUY no
            // cancelan una liquidacion ya iniciada; SELL repetidas se agrupan.
            foreach ($orders as $order) {
                if ($order['action'] === 'SELL') {
                    $ticker = $order['ticker'];
                    $sellTickers[$ticker] = true;
                    if (isset($positions[$ticker]) && !isset($pendingExits[$ticker])) {
                        $pendingExits[$ticker] = ['reason' => 'signal', 'trigger_date' => $date];
                    }
                }
            }

            // Contabilidad comun de cada fill. Solo la cantidad ejecutada
            // genera efectivo/costes y consume capacidad; el resto sigue vivo.
            $executeExit = function (string $ticker, float $price, string $reason) use (
                $date, &$positions, &$pendingExits, &$remainingCapacity, &$cash,
                &$tradedGross, &$totalCosts, &$ledger, &$liquidityLimitedSells,
                &$liquidityBlockedSells, &$partialSellFills
            ): void {
                $requested = $positions[$ticker]['quantity'];
                $quantity = min($requested, $remainingCapacity[$ticker]);

                if ($quantity + self::EPSILON < $requested) {
                    $liquidityLimitedSells++;
                }
                if ($quantity <= self::EPSILON) {
                    $liquidityBlockedSells++;
                    return;
                }

                $remaining = max(0.0, $requested - $quantity);
                $gross = $quantity * $price;
                $cost = $gross * $this->config->getCostRate();
                $cash += $gross - $cost;
                $tradedGross += $gross;
                $totalCosts += $cost;
                $remainingCapacity[$ticker] = max(0.0, $remainingCapacity[$ticker] - $quantity);
                $ledger[] = array_merge(
                    $this->ledgerRow($date, $ticker, 'SELL', $reason, $quantity, $price, $gross, $cost, $cash),
                    ['requested_quantity' => round($requested, 8), 'remaining_quantity' => round($remaining, 8)]
                );

                if ($remaining <= self::EPSILON) {
                    unset($positions[$ticker], $pendingExits[$ticker]);
                } else {
                    $positions[$ticker]['quantity'] = $remaining;
                    $partialSellFills++;
                }
            };

            // Fases 1 y 2: disparar gaps heredados, ejecutar primero stops
            // pendientes/gaps y despues ventas por senal, siempre al open.
            foreach ($positions as $ticker => $position) {
                if ($position['stop_loss'] !== null
                    && isset($quotes[$ticker])
                    && $quotes[$ticker]['open'] <= $position['stop_loss']
                    && (!isset($pendingExits[$ticker]) || $pendingExits[$ticker]['reason'] === 'signal')) {
                    $pendingExits[$ticker] = ['reason' => 'stop_loss_gap', 'trigger_date' => $date];
                    $stopsTriggered++;
                }
            }

            foreach ([true, false] as $stopPhase) {
                foreach ($pendingExits as $ticker => $pending) {
                    if (($pending['reason'] !== 'signal') !== $stopPhase) {
                        continue;
                    }
                    $sellTickers[$ticker] = true;
                    $exitAttempted[$ticker] = true;

                    if (!isset($quotes[$ticker])) {
                        $rejections['missing_quote']++;
                        continue;
                    }

                    $reason = $pending['trigger_date'] === $date
                        ? $pending['reason']
                        : ($stopPhase ? 'stop_loss_pending' : 'signal_pending');
                    $executeExit($ticker, $quotes[$ticker]['open'], $reason);
                }
            }

            // Fase 3: todas las compras al open. Ningun stop intradia puede
            // aportar efectivo retroactivamente a estas compras.
            $buys = array_values(array_filter(
                $orders,
                static fn (array $order): bool => $order['action'] === 'BUY'
            ));
            usort(
                $buys,
                static fn (array $left, array $right): int
                    => [$right['priority'], $left['ticker']] <=> [$left['priority'], $right['ticker']]
            );

            foreach ($buys as $order) {
                $ticker = $order['ticker'];

                if (isset($sellTickers[$ticker])) {
                    continue;
                }

                if (isset($positions[$ticker])) {
                    $rejections['already_held']++;
                    continue;
                }

                if (count($positions) >= $this->config->getMaxPositions()) {
                    $rejections['max_positions']++;
                    continue;
                }

                if (!isset($quotes[$ticker])) {
                    $rejections['missing_quote']++;
                    continue;
                }

                $quote = $quotes[$ticker];
                $targetGross = min(
                    $equityAtOpen * $this->config->getMaxPositionWeight(),
                    $cash / (1.0 + $this->config->getCostRate())
                );
                $liquidityGross = $remainingCapacity[$ticker] * $quote['open'];
                $gross = min($targetGross, $liquidityGross);

                if ($liquidityGross <= self::EPSILON) {
                    $rejections['liquidity']++;
                    continue;
                }

                if ($gross <= self::EPSILON) {
                    $rejections['insufficient_cash']++;
                    continue;
                }

                if ($gross + self::EPSILON < $targetGross) {
                    $liquidityLimitedBuys++;
                }

                $quantity = $gross / $quote['open'];
                $remainingCapacity[$ticker] = max(0.0, $remainingCapacity[$ticker] - $quantity);
                $cost = $gross * $this->config->getCostRate();
                $cash -= $gross + $cost;
                $tradedGross += $gross;
                $totalCosts += $cost;
                $positions[$ticker] = [
                    'quantity' => $quantity,
                    'entry_date' => $date,
                    'entry_price' => $quote['open'],
                    'last_close' => $quote['close'],
                    'stop_loss' => $order['stop_loss'],
                ];
                $ledger[] = $this->ledgerRow($date, $ticker, 'BUY', 'signal', $quantity, $quote['open'], $gross, $cost, $cash);
            }

            // Fase 4: rango intradia, incluidas entradas de hoy. Un stop
            // disparado queda pendiente aunque la compra haya agotado la
            // capacidad. Su efectivo nunca financia compras retrospectivas.
            foreach ($positions as $ticker => $position) {
                $stopLoss = $position['stop_loss'];

                if ($stopLoss === null || !isset($quotes[$ticker])
                    || (isset($pendingExits[$ticker]) && $pendingExits[$ticker]['reason'] !== 'signal')) {
                    continue;
                }

                $quote = $quotes[$ticker];
                $stopPrice = null;

                if ($quote['open'] <= $stopLoss) {
                    $stopPrice = $quote['open'];
                } elseif ($quote['low'] === null) {
                    $stopsNotEvaluable++;
                } elseif ($quote['low'] <= $stopLoss) {
                    $stopPrice = $stopLoss;
                }

                if ($stopPrice === null) {
                    continue;
                }

                $stopsTriggered++;
                $isEntryDay = $position['entry_date'] === $date;
                if ($isEntryDay) {
                    $entryDayStopsTriggered++;
                }
                $reason = $isEntryDay ? 'entry_day_stop_loss' : 'stop_loss';
                $pendingExits[$ticker] = ['reason' => $reason, 'trigger_date' => $date];

                // Si una venta al open ya agoto la capacidad, el disparo
                // persiste sin registrar un segundo intento para ese ticker.
                if (!isset($exitAttempted[$ticker])) {
                    $executeExit($ticker, $stopPrice, $reason);
                }
            }

            $positionsValue = $this->positionsValue($positions, $quotes, 'close', $staleMarks);
            $portfolioIsStale = array_diff_key($positions, $quotes) !== [];
            if ($portfolioIsStale) {
                $stalePortfolioDays++;
            }
            $excludePortfolioReturn = $portfolioIsStale || $previousPortfolioIsStale;
            if ($excludePortfolioReturn) {
                $portfolioReturnsExcludedByStaleMarks++;
            }
            foreach ($positions as $ticker => &$position) {
                if (isset($quotes[$ticker])) {
                    $position['last_close'] = $quotes[$ticker]['close'];
                }
            }
            unset($position);

            $equity = $cash + $positionsValue;
            $peakEquity = max($peakEquity, $equity);
            $drawdownPct = $peakEquity > 0.0 ? (($equity / $peakEquity) - 1.0) * 100.0 : 0.0;
            $maxDrawdownPct = min($maxDrawdownPct, $drawdownPct);
            // Excluir tanto el cierre arrastrado como el primer precio tras
            // el hueco: el salto acumulado no es un retorno de una sesion.
            $dailyReturnPct = !$excludePortfolioReturn && $previousEquity > 0.0
                ? (($equity / $previousEquity) - 1.0) * 100.0
                : null;
            $turnoverPct = $equityAtOpen > 0.0 ? ($tradedGross / $equityAtOpen) * 100.0 : 0.0;
            $totalTurnover += $turnoverPct;
            $benchmarkEquity = $benchmarkUnits !== null && $benchmarkLastClose !== null
                ? $benchmarkUnits * $benchmarkLastClose
                : null;
            $benchmarkReturnPct = $benchmarkEquity !== null
                ? (($benchmarkEquity / $this->config->getInitialCapital()) - 1.0) * 100.0
                : null;
            $benchmarkDailyReturnPct = null;
            $benchmarkIsStale = $benchmarkEquity === null ? null : $benchmark === null;

            if ($benchmarkEquity !== null && $benchmark !== null) {
                if (!$benchmarkGapSinceLastQuote && $benchmarkPreviousEquity > 0.0) {
                    $benchmarkDailyReturnPct = (($benchmarkEquity / $benchmarkPreviousEquity) - 1.0) * 100.0;
                }

                $benchmarkPreviousEquity = $benchmarkEquity;
                $benchmarkGapSinceLastQuote = false;
            }
            $portfolioReturnPct = (($equity / $this->config->getInitialCapital()) - 1.0) * 100.0;
            $benchmarkDrawdownPct = null;

            if ($benchmarkEquity !== null) {
                $benchmarkPeak = max($benchmarkPeak, $benchmarkEquity);
                $benchmarkDrawdownPct = $benchmarkPeak > 0.0
                    ? (($benchmarkEquity / $benchmarkPeak) - 1.0) * 100.0
                    : 0.0;
                $benchmarkMaxDrawdownPct = $benchmarkMaxDrawdownPct === null
                    ? $benchmarkDrawdownPct
                    : min($benchmarkMaxDrawdownPct, $benchmarkDrawdownPct);
            }

            $equityCurve[] = [
                'date' => $date,
                'cash' => round($cash, 6),
                'positions_value' => round($positionsValue, 6),
                'equity' => round($equity, 6),
                'daily_return_pct' => $dailyReturnPct === null ? null : round($dailyReturnPct, 6),
                'portfolio_is_stale' => $portfolioIsStale,
                'drawdown_pct' => round($drawdownPct, 6),
                'gross_exposure_pct' => $equity > 0.0 ? round(($positionsValue / $equity) * 100.0, 6) : 0.0,
                'turnover_pct' => round($turnoverPct, 6),
                'positions' => count($positions),
                'pending_exit_positions' => count($pendingExits),
                'benchmark_equity' => $benchmarkEquity === null ? null : round($benchmarkEquity, 6),
                'benchmark_daily_return_pct' => $benchmarkDailyReturnPct === null ? null : round($benchmarkDailyReturnPct, 6),
                'benchmark_return_pct' => $benchmarkReturnPct === null ? null : round($benchmarkReturnPct, 6),
                'benchmark_drawdown_pct' => $benchmarkDrawdownPct === null ? null : round($benchmarkDrawdownPct, 6),
                'benchmark_is_stale' => $benchmarkIsStale,
                'excess_return_pct' => $benchmarkReturnPct === null ? null : round($portfolioReturnPct - $benchmarkReturnPct, 6),
            ];
            $previousEquity = $equity;
            $previousPortfolioIsStale = $portfolioIsStale;
        }

        ksort($positions);
        ksort($pendingExits);
        foreach ($pendingExits as $ticker => &$pending) {
            $pending['quantity'] = $positions[$ticker]['quantity'];
        }
        unset($pending);
        $finalEquity = $equityCurve === [] ? $cash : (float) $equityCurve[array_key_last($equityCurve)]['equity'];
        $lastCurvePoint = $equityCurve === [] ? null : $equityCurve[array_key_last($equityCurve)];
        $benchmarkFinalEquity = $lastCurvePoint['benchmark_equity'] ?? null;
        $benchmarkTotalReturnPct = $lastCurvePoint['benchmark_return_pct'] ?? null;
        $portfolioTotalReturnPct = (($finalEquity / $this->config->getInitialCapital()) - 1.0) * 100.0;

        return [
            'config' => [
                'initial_capital' => $this->config->getInitialCapital(),
                'cost_bps_per_side' => $this->config->getCostBps(),
                'max_positions' => $this->config->getMaxPositions(),
                'max_position_weight' => $this->config->getMaxPositionWeight(),
                'max_volume_participation' => $this->config->getMaxVolumeParticipation(),
                'liquidity_budget' => 'shared_gross_quantity_per_ticker_session',
                'exit_order_policy' => 'persist_until_filled',
            ],
            'initial_capital' => $this->config->getInitialCapital(),
            'final_equity' => round($finalEquity, 6),
            'total_return_pct' => round($portfolioTotalReturnPct, 6),
            'max_drawdown_pct' => round($maxDrawdownPct, 6),
            'total_costs' => round($totalCosts, 6),
            'cumulative_turnover_pct' => round($totalTurnover, 6),
            'stale_position_marks' => $staleMarks,
            'stale_portfolio_days' => $stalePortfolioDays,
            'portfolio_returns_excluded_by_stale_marks' => $portfolioReturnsExcludedByStaleMarks,
            'valuation_warning' => $stalePortfolioDays > 0
                ? 'Hay cierres arrastrados: patrimonio, retorno acumulado y drawdown son aproximados. Los ratios diarios excluyen huecos y su primera recuperacion.'
                : null,
            'stops_triggered' => $stopsTriggered,
            'entry_day_stops_triggered' => $entryDayStopsTriggered,
            'liquidity_limited_buys' => $liquidityLimitedBuys,
            'liquidity_limited_sells' => $liquidityLimitedSells,
            'liquidity_blocked_sells' => $liquidityBlockedSells,
            'partial_sell_fills' => $partialSellFills,
            'pending_exit_positions' => count($pendingExits),
            'pending_exits' => $pendingExits,
            'stops_not_evaluable' => $stopsNotEvaluable,
            'benchmark_entry_date' => $benchmarkEntryDate,
            'benchmark_entry_cost' => $benchmarkEntryCost === null ? null : round($benchmarkEntryCost, 6),
            'benchmark_final_equity' => $benchmarkFinalEquity,
            'benchmark_total_return_pct' => $benchmarkTotalReturnPct,
            'benchmark_max_drawdown_pct' => $benchmarkMaxDrawdownPct === null ? null : round($benchmarkMaxDrawdownPct, 6),
            'benchmark_stale_marks' => $benchmarkStaleMarks,
            'excess_return_pct' => $benchmarkTotalReturnPct === null
                ? null
                : round($portfolioTotalReturnPct - (float) $benchmarkTotalReturnPct, 6),
            'rejections' => $rejections,
            'equity_curve' => $equityCurve,
            'performance' => $this->performanceMetrics->summarize(
                $equityCurve,
                $this->config->getInitialCapital()
            ),
            'ledger' => $ledger,
            'open_positions' => $positions,
        ];
    }

    private function validDate(mixed $date): string
    {
        if (!is_string($date)) {
            throw new InvalidArgumentException('Cada dia necesita date en formato YYYY-MM-DD.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Cada dia necesita date en formato YYYY-MM-DD.');
        }

        return $date;
    }

    /**
     * @param mixed $raw
     * @return array<string,array{open: float, close: float, volume: float, low: ?float}>
     */
    private function quotes(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('quotes debe ser un mapa por ticker.');
        }

        $quotes = [];
        foreach ($raw as $ticker => $quote) {
            if (!is_string($ticker) || !is_array($quote)) {
                throw new InvalidArgumentException('Cotizacion de cartera invalida.');
            }

            $ticker = strtoupper(trim($ticker));
            $open = (float) ($quote['open'] ?? 0.0);
            $close = (float) ($quote['close'] ?? 0.0);
            $volume = (float) ($quote['volume'] ?? -1.0);
            $low = isset($quote['low']) ? (float) $quote['low'] : null;

            if ($ticker === '' || !is_finite($open) || !is_finite($close) || !is_finite($volume) || $open <= 0.0 || $close <= 0.0 || $volume < 0.0
                || ($low !== null && (!is_finite($low) || $low <= 0.0 || $low > min($open, $close)))) {
                throw new InvalidArgumentException('Cotizacion de cartera invalida.');
            }

            $quotes[$ticker] = ['open' => $open, 'close' => $close, 'volume' => $volume, 'low' => $low];
        }

        return $quotes;
    }

    /**
     * @param mixed $raw
     * @return list<array{ticker: string, action: string, priority: float, stop_loss: ?float}>
     */
    private function orders(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('orders debe ser una lista.');
        }

        $orders = [];
        foreach ($raw as $order) {
            if (!is_array($order)) {
                throw new InvalidArgumentException('Orden de cartera invalida.');
            }

            $ticker = strtoupper(trim((string) ($order['ticker'] ?? '')));
            $action = strtoupper(trim((string) ($order['action'] ?? '')));
            $priority = (float) ($order['priority'] ?? 0.0);
            $stopLoss = isset($order['stop_loss']) ? (float) $order['stop_loss'] : null;

            if ($ticker === '' || !in_array($action, ['BUY', 'SELL', 'HOLD'], true) || !is_finite($priority)
                || ($stopLoss !== null && (!is_finite($stopLoss) || $stopLoss <= 0.0))) {
                throw new InvalidArgumentException('Orden de cartera invalida.');
            }

            $orders[] = ['ticker' => $ticker, 'action' => $action, 'priority' => $priority, 'stop_loss' => $stopLoss];
        }

        return $orders;
    }

    /**
     * @return array{open: float, close: float}|null
     */
    private function benchmarkQuote(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (!is_array($raw)) {
            throw new InvalidArgumentException('Benchmark diario invalido.');
        }

        $open = (float) ($raw['open'] ?? 0.0);
        $close = (float) ($raw['close'] ?? 0.0);

        if (!is_finite($open) || !is_finite($close) || $open <= 0.0 || $close <= 0.0) {
            throw new InvalidArgumentException('Benchmark diario invalido.');
        }

        return ['open' => $open, 'close' => $close];
    }

    /**
     * @param array<string,array{quantity: float, entry_date: string, entry_price: float, last_close: float, stop_loss: ?float}> $positions
     * @param array<string,array{open: float, close: float, volume: float, low: ?float}> $quotes
     */
    private function positionsValue(array $positions, array $quotes, string $field, int &$staleMarks): float
    {
        $value = 0.0;
        foreach ($positions as $ticker => $position) {
            if (isset($quotes[$ticker])) {
                $value += $position['quantity'] * $quotes[$ticker][$field];
                continue;
            }

            $value += $position['quantity'] * $position['last_close'];
            $staleMarks++;
        }

        return $value;
    }

    /**
     * @return array{date: string, ticker: string, action: string, reason: string, quantity: float, price: float, gross: float, cost: float, cash_after: float}
     */
    private function ledgerRow(string $date, string $ticker, string $action, string $reason, float $quantity, float $price, float $gross, float $cost, float $cash): array
    {
        return [
            'date' => $date,
            'ticker' => $ticker,
            'action' => $action,
            'reason' => $reason,
            'quantity' => round($quantity, 8),
            'price' => round($price, 6),
            'gross' => round($gross, 6),
            'cost' => round($cost, 6),
            'cash_after' => round($cash, 6),
        ];
    }
}
