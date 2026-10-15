<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use InvalidArgumentException;
use StockAnalyzer\Models\HistoricalQuote;

/**
 * Convierte recomendaciones point-in-time y velas reales en el calendario
 * de ejecucion que consume PortfolioBacktestSimulator.
 *
 * Una decision calculada al cierre del indice N se ejecuta unicamente en la
 * apertura local N+1 del mismo ticker. Nunca reconstruye precios desde
 * retornos agregados.
 */
final class RecommendationPortfolioDatasetBuilder
{
    public function __construct(
        private readonly bool $requirePointInTimeRecommendations = true,
        private readonly int $liquidityLookbackSessions = 20
    ) {
        if ($this->liquidityLookbackSessions < 1) {
            throw new InvalidArgumentException('La ventana de liquidez debe tener al menos una sesion.');
        }
    }

    /**
     * @param array<string,list<HistoricalQuote>> $historiesByTicker
     * @param array<string,list<array{date: string, index: int, recommendation: string, score_percentage: float, fundamentals_is_point_in_time: bool, stop_loss: ?float, candidate_stop: ?float, fundamental_change: mixed, entry_price: ?float, eligible: bool}>> $timelinesByTicker
     * @param list<HistoricalQuote> $benchmarkHistory
     * @return array{
     *   days: list<array{date: string, quotes: array<string,array{open: float, low: float, close: float, volume: float}>, orders: list<array{ticker: string, action: string, priority: float, stop_loss: ?float}>, benchmark: array{open: float, close: float}|null}>,
     *   recommendations_total: int,
     *   orders_by_action: array{BUY: int, SELL: int, HOLD: int},
     *   buy_orders_excluded_by_membership: int,
     *   recommendations_excluded_by_missing_pit: int,
     *   buy_orders_excluded_by_missing_pit: int,
     *   buy_orders_point_in_time_count: int,
     *   buy_orders_non_point_in_time_count: int,
     *   buy_orders_point_in_time_pct: ?float,
     *   strict_point_in_time_recommendations: bool,
     *   liquidity_volume_method: string,
     *   liquidity_lookback_sessions: int,
     *   liquidity_quotes_total: int,
     *   liquidity_quotes_with_estimate: int,
     *   liquidity_quotes_missing_estimate: int,
     *   buy_orders_without_liquidity_estimate: int,
     *   recommendations_without_next_session: int,
     *   fundamentals_point_in_time_count: int,
     *   fundamentals_point_in_time_pct: ?float,
     *   benchmark_quotes_available: int,
     *   calendar_source: string,
     *   calendar_days_without_asset_quotes: int,
     *   calendar_warning: ?string
     * }
     */
    public function build(array $historiesByTicker, array $timelinesByTicker, array $benchmarkHistory = []): array
    {
        $ordersByDate = [];
        $recommendationsTotal = 0;
        $ordersByAction = ['BUY' => 0, 'SELL' => 0, 'HOLD' => 0];
        $excludedByMembership = 0;
        $excludedByMissingPointInTime = 0;
        $buyExcludedByMissingPointInTime = 0;
        $pointInTimeBuyCount = 0;
        $nonPointInTimeBuyCount = 0;
        $withoutNextSession = 0;
        $pointInTimeCount = 0;
        $firstExecutionDate = null;
        $liquidityQuotesTotal = 0;
        $liquidityQuotesWithEstimate = 0;

        foreach ($timelinesByTicker as $ticker => $timeline) {
            $ticker = strtoupper($ticker);
            $history = $historiesByTicker[$ticker] ?? $historiesByTicker[strtolower($ticker)] ?? [];

            foreach ($timeline as $point) {
                $recommendationsTotal++;
                if ($point['fundamentals_is_point_in_time']) {
                    $pointInTimeCount++;
                }
                $executionIndex = $point['index'] + 1;

                if (!isset($history[$executionIndex])) {
                    $withoutNextSession++;
                    continue;
                }

                $action = $this->actionFor($point['recommendation']);

                if ($action === 'BUY' && !$point['eligible']) {
                    $excludedByMembership++;
                    continue;
                }

                if ($action === 'BUY') {
                    if ($point['fundamentals_is_point_in_time']) {
                        $pointInTimeBuyCount++;
                    } else {
                        $nonPointInTimeBuyCount++;
                    }
                }

                if ($this->requirePointInTimeRecommendations && !$point['fundamentals_is_point_in_time']) {
                    $excludedByMissingPointInTime++;
                    if ($action === 'BUY') {
                        $buyExcludedByMissingPointInTime++;
                    }
                    continue;
                }

                $executionDate = $history[$executionIndex]->getDate()->format('Y-m-d');
                $firstExecutionDate = $firstExecutionDate === null || $executionDate < $firstExecutionDate
                    ? $executionDate
                    : $firstExecutionDate;
                $ordersByAction[$action]++;
                $ordersByDate[$executionDate][] = [
                    'ticker' => $ticker,
                    'action' => $action,
                    'priority' => $point['score_percentage'],
                    'stop_loss' => $action === 'BUY' ? $point['stop_loss'] : null,
                ];
            }
        }

        if ($firstExecutionDate === null) {
            return [
                'days' => [],
                'recommendations_total' => $recommendationsTotal,
                'orders_by_action' => $ordersByAction,
                'buy_orders_excluded_by_membership' => $excludedByMembership,
                'recommendations_excluded_by_missing_pit' => $excludedByMissingPointInTime,
                'buy_orders_excluded_by_missing_pit' => $buyExcludedByMissingPointInTime,
                'buy_orders_point_in_time_count' => $pointInTimeBuyCount,
                'buy_orders_non_point_in_time_count' => $nonPointInTimeBuyCount,
                'buy_orders_point_in_time_pct' => $this->buyPointInTimePct($pointInTimeBuyCount, $nonPointInTimeBuyCount),
                'strict_point_in_time_recommendations' => $this->requirePointInTimeRecommendations,
                'liquidity_volume_method' => 'trailing_average_volume_before_open',
                'liquidity_lookback_sessions' => $this->liquidityLookbackSessions,
                'liquidity_quotes_total' => 0,
                'liquidity_quotes_with_estimate' => 0,
                'liquidity_quotes_missing_estimate' => 0,
                'buy_orders_without_liquidity_estimate' => 0,
                'recommendations_without_next_session' => $withoutNextSession,
                'fundamentals_point_in_time_count' => $pointInTimeCount,
                'fundamentals_point_in_time_pct' => $recommendationsTotal > 0
                    ? round(($pointInTimeCount / $recommendationsTotal) * 100.0, 2)
                    : null,
                'benchmark_quotes_available' => 0,
                'calendar_source' => 'no_execution_calendar',
                'calendar_days_without_asset_quotes' => 0,
                'calendar_warning' => null,
            ];
        }

        $quotesByDate = [];
        foreach ($historiesByTicker as $ticker => $history) {
            $ticker = strtoupper($ticker);

            foreach ($history as $historyIndex => $quote) {
                $date = $quote->getDate()->format('Y-m-d');

                if ($date < $firstExecutionDate) {
                    continue;
                }

                $liquidityVolume = $this->trailingAverageVolume($history, $historyIndex);
                $liquidityQuotesTotal++;
                if ($liquidityVolume !== null) {
                    $liquidityQuotesWithEstimate++;
                }

                $quotesByDate[$date][$ticker] = [
                    'open' => $quote->getOpen(),
                    'low' => $quote->getLow(),
                    'close' => $quote->getClose(),
                    // Capacidad conocida al cierre anterior. El volumen de
                    // la propia sesion no se conoce cuando se compra al open.
                    'volume' => $liquidityVolume ?? 0.0,
                ];
            }
        }

        ksort($quotesByDate);
        $benchmarkByDate = [];
        foreach ($benchmarkHistory as $quote) {
            $date = $quote->getDate()->format('Y-m-d');

            if ($date >= $firstExecutionDate) {
                $benchmarkByDate[$date] = [
                    'open' => $quote->getOpen(),
                    'close' => $quote->getClose(),
                ];
            }
        }

        // Conservar las sesiones que solo aparecen en el benchmark: si
        // faltan precios de TODOS los valores, el simulador debe ver el hueco.
        // Las historias recibidas ya comparten el corte asOf del servicio.
        // No recortar al ultimo precio del activo: ocultaria huecos finales.
        $calendarDates = array_keys($quotesByDate + $benchmarkByDate);
        sort($calendarDates, SORT_STRING);
        $days = [];
        $calendarDaysWithoutAssetQuotes = 0;
        $buyOrdersWithoutLiquidityEstimate = 0;
        foreach ($calendarDates as $date) {
            $quotes = $quotesByDate[$date] ?? [];
            if ($quotes === []) {
                $calendarDaysWithoutAssetQuotes++;
            }
            $orders = $ordersByDate[$date] ?? [];
            foreach ($orders as $order) {
                if ($order['action'] === 'BUY' && (($quotes[$order['ticker']]['volume'] ?? 0.0) <= 0.0)) {
                    $buyOrdersWithoutLiquidityEstimate++;
                }
            }

            $days[] = [
                'date' => $date,
                'quotes' => $quotes,
                'orders' => $orders,
                'benchmark' => $benchmarkByDate[$date] ?? null,
            ];
        }

        return [
            'days' => $days,
            'recommendations_total' => $recommendationsTotal,
            'orders_by_action' => $ordersByAction,
            'buy_orders_excluded_by_membership' => $excludedByMembership,
            'recommendations_excluded_by_missing_pit' => $excludedByMissingPointInTime,
            'buy_orders_excluded_by_missing_pit' => $buyExcludedByMissingPointInTime,
            'buy_orders_point_in_time_count' => $pointInTimeBuyCount,
            'buy_orders_non_point_in_time_count' => $nonPointInTimeBuyCount,
            'buy_orders_point_in_time_pct' => $this->buyPointInTimePct($pointInTimeBuyCount, $nonPointInTimeBuyCount),
            'strict_point_in_time_recommendations' => $this->requirePointInTimeRecommendations,
            'liquidity_volume_method' => 'trailing_average_volume_before_open',
            'liquidity_lookback_sessions' => $this->liquidityLookbackSessions,
            'liquidity_quotes_total' => $liquidityQuotesTotal,
            'liquidity_quotes_with_estimate' => $liquidityQuotesWithEstimate,
            'liquidity_quotes_missing_estimate' => $liquidityQuotesTotal - $liquidityQuotesWithEstimate,
            'buy_orders_without_liquidity_estimate' => $buyOrdersWithoutLiquidityEstimate,
            'recommendations_without_next_session' => $withoutNextSession,
            'fundamentals_point_in_time_count' => $pointInTimeCount,
            'fundamentals_point_in_time_pct' => $recommendationsTotal > 0
                ? round(($pointInTimeCount / $recommendationsTotal) * 100.0, 2)
                : null,
            'benchmark_quotes_available' => count(array_filter(
                $days,
                static fn (array $day): bool => $day['benchmark'] !== null
            )),
            'calendar_source' => $benchmarkByDate === []
                ? 'asset_quotes_only'
                : 'asset_and_benchmark_quotes_union',
            'calendar_days_without_asset_quotes' => $calendarDaysWithoutAssetQuotes,
            'calendar_warning' => $benchmarkByDate === []
                ? 'Sin calendario de referencia: los huecos comunes a todos los valores pueden quedar sin detectar.'
                : 'Calendario unido de valores y benchmark; no distingue festivos locales, suspensiones ni huecos comunes a ambas fuentes.',
        ];
    }

    private function actionFor(string $recommendation): string
    {
        return match (strtoupper(trim($recommendation))) {
            'BUY' => 'BUY',
            'SELL', 'STRONG SELL' => 'SELL',
            default => 'HOLD',
        };
    }

    private function buyPointInTimePct(int $pointInTime, int $nonPointInTime): ?float
    {
        $total = $pointInTime + $nonPointInTime;

        return $total === 0 ? null : round(($pointInTime / $total) * 100.0, 2);
    }

    /**
     * @param list<HistoricalQuote> $history
     */
    private function trailingAverageVolume(array $history, int $executionIndex): ?float
    {
        $start = $executionIndex - $this->liquidityLookbackSessions;
        if ($start < 0) {
            return null;
        }

        $sum = 0.0;
        for ($index = $start; $index < $executionIndex; $index++) {
            $volume = (float) $history[$index]->getVolume();
            if (!is_finite($volume) || $volume <= 0.0) {
                return null;
            }
            $sum += $volume;
        }

        return $sum / $this->liquidityLookbackSessions;
    }
}
