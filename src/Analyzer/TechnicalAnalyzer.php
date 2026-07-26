<?php

declare(strict_types=1);

namespace StockAnalyzer\Analyzer;

use StockAnalyzer\DTO\PriceChartSeries;
use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Models\HistoricalQuote;

/**
 * Calcula indicadores tecnicos a partir del historico de cotizaciones.
 * Expone dos vistas sobre los mismos datos:
 *
 * - analyze(): el ultimo valor de cada indicador, para puntuar y listar.
 * - buildChartSeries(): la serie completa alineada por fecha, para dibujar
 *   un grafico.
 *
 * Los metodos privados *Series() son la fuente unica de cada calculo; los
 * metodos de "ultimo valor" simplemente toman el ultimo dato definido de
 * la serie correspondiente para no duplicar formulas.
 */
class TechnicalAnalyzer
{
    /**
     * @param list<HistoricalQuote> $quotes
     */
    public function analyze(array $quotes): TechnicalSnapshot
    {
        $closes = $this->column($quotes, static fn (HistoricalQuote $q): float => $q->getClose());
        $highs = $this->column($quotes, static fn (HistoricalQuote $q): float => $q->getHigh());
        $lows = $this->column($quotes, static fn (HistoricalQuote $q): float => $q->getLow());
        $volumes = $this->column($quotes, static fn (HistoricalQuote $q): int => $q->getVolume());

        $ema12Series = $this->emaSeries($closes, 12);
        $ema26Series = $this->emaSeries($closes, 26);
        $macd = $this->macdFromEma($ema12Series, $ema26Series);
        $bollinger = $this->bollingerSeries($closes, 20);

        return new TechnicalSnapshot(
            sma20: $this->lastDefined($this->smaSeries($closes, 20)),
            sma50: $this->lastDefined($this->smaSeries($closes, 50)),
            ema12: $this->lastDefined($ema12Series),
            ema26: $this->lastDefined($ema26Series),
            rsi14: $this->rsi($closes, 14),
            macd: $this->lastDefined($macd['macd']),
            macdSignal: $this->lastDefined($macd['signal']),
            macdHistogram: $this->lastDefined($macd['histogram']),
            bollingerUpper: $this->lastDefined($bollinger['upper']),
            bollingerMiddle: $this->lastDefined($this->smaSeries($closes, 20)),
            bollingerLower: $this->lastDefined($bollinger['lower']),
            atr14: $this->atr($highs, $lows, $closes, 14),
            momentum30: $this->momentum($closes, 30),
            volatility20: $this->volatility($closes, 20),
            avgVolume20: $this->averageVolume($volumes, 20),
            lastVolume: $volumes === [] ? null : $volumes[array_key_last($volumes)],
            high52w: $highs === [] ? null : max($highs),
            low52w: $lows === [] ? null : min($lows),
            historyCount: count($quotes)
        );
    }

    /**
     * @param list<HistoricalQuote> $quotes
     */
    public function buildChartSeries(array $quotes): PriceChartSeries
    {
        $closes = $this->column($quotes, static fn (HistoricalQuote $q): float => $q->getClose());
        $labels = $this->column($quotes, static fn (HistoricalQuote $q): string => $q->getDate()->format('Y-m-d'));
        $volumes = $this->column($quotes, static fn (HistoricalQuote $q): int => $q->getVolume());
        $bollinger = $this->bollingerSeries($closes, 20);

        return new PriceChartSeries(
            labels: $labels,
            closes: $closes,
            sma20: $this->smaSeries($closes, 20),
            sma50: $this->smaSeries($closes, 50),
            bollingerUpper: $bollinger['upper'],
            bollingerLower: $bollinger['lower'],
            volumes: $volumes
        );
    }

    /**
     * @template T
     * @param list<HistoricalQuote> $quotes
     * @param callable(HistoricalQuote): T $extractor
     * @return list<T>
     */
    private function column(array $quotes, callable $extractor): array
    {
        return array_map($extractor, $quotes);
    }

    /**
     * @param list<float> $values
     * @return list<float|null>
     */
    private function smaSeries(array $values, int $period): array
    {
        $count = count($values);
        $result = array_fill(0, $count, null);

        for ($index = $period - 1; $index < $count; $index++) {
            $result[$index] = array_sum(array_slice($values, $index - $period + 1, $period)) / $period;
        }

        return $result;
    }

    /**
     * @param list<float> $values
     * @return list<float|null>
     */
    private function emaSeries(array $values, int $period): array
    {
        $count = count($values);
        $result = array_fill(0, $count, null);

        if ($count < $period) {
            return $result;
        }

        $multiplier = 2 / ($period + 1);
        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $result[$period - 1] = $seed;
        $previous = $seed;

        for ($index = $period; $index < $count; $index++) {
            $ema = (($values[$index] - $previous) * $multiplier) + $previous;
            $result[$index] = $ema;
            $previous = $ema;
        }

        return $result;
    }

    /**
     * @param list<float|null> $ema12Series
     * @param list<float|null> $ema26Series
     * @return array{macd: list<float|null>, signal: list<float|null>, histogram: list<float|null>}
     */
    private function macdFromEma(array $ema12Series, array $ema26Series): array
    {
        $count = count($ema12Series);
        $macdLine = array_fill(0, $count, null);

        for ($index = 0; $index < $count; $index++) {
            if ($ema12Series[$index] !== null && $ema26Series[$index] !== null) {
                $macdLine[$index] = $ema12Series[$index] - $ema26Series[$index];
            }
        }

        // La linea de señal es una EMA9 del MACD. Se calcula solo sobre el
        // tramo ya definido del MACD y despues se realinea con el array
        // original (que arrastra nulls mientras EMA26 aun no existe).
        $definedMacd = array_values(array_filter($macdLine, static fn (?float $value): bool => $value !== null));
        $signalOnDefined = $this->emaSeries($definedMacd, 9);

        $signalLine = array_fill(0, $count, null);
        $offset = $count - count($definedMacd);

        foreach ($signalOnDefined as $index => $value) {
            $signalLine[$offset + $index] = $value;
        }

        $histogram = array_fill(0, $count, null);

        for ($index = 0; $index < $count; $index++) {
            if ($macdLine[$index] !== null && $signalLine[$index] !== null) {
                $histogram[$index] = $macdLine[$index] - $signalLine[$index];
            }
        }

        return ['macd' => $macdLine, 'signal' => $signalLine, 'histogram' => $histogram];
    }

    /**
     * @param list<float> $values
     * @return array{upper: list<float|null>, lower: list<float|null>}
     */
    private function bollingerSeries(array $values, int $period, float $stdDevMultiplier = 2.0): array
    {
        $count = count($values);
        $upper = array_fill(0, $count, null);
        $lower = array_fill(0, $count, null);

        for ($index = $period - 1; $index < $count; $index++) {
            $slice = array_slice($values, $index - $period + 1, $period);
            $middle = array_sum($slice) / $period;
            $variance = array_sum(array_map(
                static fn (float $value): float => ($value - $middle) ** 2,
                $slice
            )) / $period;
            $stdDev = sqrt($variance);

            $upper[$index] = $middle + ($stdDev * $stdDevMultiplier);
            $lower[$index] = $middle - ($stdDev * $stdDevMultiplier);
        }

        return ['upper' => $upper, 'lower' => $lower];
    }

    /**
     * Average True Range con media simple (no suavizado de Wilder). Es una
     * simplificacion deliberada: mas facil de razonar y suficientemente
     * precisa para puntuar riesgo relativo entre acciones.
     *
     * @param list<float> $highs
     * @param list<float> $lows
     * @param list<float> $closes
     */
    private function atr(array $highs, array $lows, array $closes, int $period): ?float
    {
        $count = count($closes);

        if ($count <= $period) {
            return null;
        }

        $trueRanges = [];

        for ($index = 1; $index < $count; $index++) {
            $trueRanges[] = max(
                $highs[$index] - $lows[$index],
                abs($highs[$index] - $closes[$index - 1]),
                abs($lows[$index] - $closes[$index - 1])
            );
        }

        $recent = array_slice($trueRanges, -$period);

        return array_sum($recent) / count($recent);
    }

    /**
     * @param list<int> $volumes
     */
    private function averageVolume(array $volumes, int $period): ?float
    {
        if (count($volumes) < $period) {
            return null;
        }

        return array_sum(array_slice($volumes, -$period)) / $period;
    }

    /**
     * @param list<float> $closes
     */
    private function momentum(array $closes, int $period): ?float
    {
        if (count($closes) <= $period) {
            return null;
        }

        $current = $closes[array_key_last($closes)];
        $past = $closes[count($closes) - 1 - $period];

        return $past > 0 ? (($current - $past) / $past) * 100 : null;
    }

    /**
     * @param list<float> $closes
     */
    private function volatility(array $closes, int $period): ?float
    {
        if (count($closes) <= $period) {
            return null;
        }

        $returns = [];
        $slice = array_slice($closes, -($period + 1));

        for ($index = 1; $index < count($slice); $index++) {
            if ($slice[$index - 1] > 0) {
                $returns[] = (($slice[$index] - $slice[$index - 1]) / $slice[$index - 1]) * 100;
            }
        }

        if ($returns === []) {
            return null;
        }

        $average = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(
            static fn (float $value): float => ($value - $average) ** 2,
            $returns
        )) / count($returns);

        return sqrt($variance);
    }

    /**
     * @param list<float> $closes
     */
    private function rsi(array $closes, int $period): ?float
    {
        if (count($closes) <= $period) {
            return null;
        }

        $slice = array_slice($closes, -($period + 1));
        $gains = 0.0;
        $losses = 0.0;

        for ($index = 1; $index < count($slice); $index++) {
            $change = $slice[$index] - $slice[$index - 1];

            if ($change >= 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }

        if ($losses === 0.0) {
            return 100.0;
        }

        $relativeStrength = ($gains / $period) / ($losses / $period);

        return 100 - (100 / (1 + $relativeStrength));
    }

    /**
     * @param list<float|null> $series
     */
    private function lastDefined(array $series): ?float
    {
        for ($index = count($series) - 1; $index >= 0; $index--) {
            if ($series[$index] !== null) {
                return $series[$index];
            }
        }

        return null;
    }
}
