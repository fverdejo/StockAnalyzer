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
            macdHistogramPrevious: $this->valueBeforeLast($macd['histogram'], 1),
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
            historyCount: count($quotes),
            momentum12m1: $this->momentumSkippingRecent($closes, 250, 21)
        );
    }

    /**
     * @param list<HistoricalQuote> $quotes
     */
    public function buildChartSeries(array $quotes): PriceChartSeries
    {
        $closes = $this->column($quotes, static fn (HistoricalQuote $q): float => $q->getClose());
        $opens = $this->column($quotes, static fn (HistoricalQuote $q): float => $q->getOpen());
        $highs = $this->column($quotes, static fn (HistoricalQuote $q): float => $q->getHigh());
        $lows = $this->column($quotes, static fn (HistoricalQuote $q): float => $q->getLow());
        $labels = $this->column($quotes, static fn (HistoricalQuote $q): string => $q->getDate()->format('Y-m-d'));
        $volumes = $this->column($quotes, static fn (HistoricalQuote $q): int => $q->getVolume());
        $bollinger = $this->bollingerSeries($closes, 20);
        $macd = $this->macdFromEma($this->emaSeries($closes, 12), $this->emaSeries($closes, 26));
        $rsi14 = $this->rsiSeries($closes, 14);

        return new PriceChartSeries(
            labels: $labels,
            closes: $closes,
            highs: $highs,
            lows: $lows,
            sma20: $this->smaSeries($closes, 20),
            sma50: $this->smaSeries($closes, 50),
            bollingerUpper: $bollinger['upper'],
            bollingerLower: $bollinger['lower'],
            volumes: $volumes,
            macd: $macd['macd'],
            macdSignal: $macd['signal'],
            macdHistogram: $macd['histogram'],
            rsi14: $rsi14,
            opens: $opens
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
     * Average True Range con suavizado de Wilder (equivalente a una EMA de
     * alpha=1/periodo), no media simple. Cambio deliberado (ver
     * versions.md): es el estandar de facto cuando el ATR se usa como
     * nivel de precio accionable (stop-loss/objetivo, ver RiskLevels),
     * porque evita el salto discontinuo que sufre una SMA cuando un dia
     * con gap grande sale de la ventana de N sesiones. Afecta al valor
     * numerico de ATR14 para todos los consumidores existentes
     * (TechnicalScoreAnalyzer, StockDetailPage, DashboardPage), no solo al
     * nuevo calculo de niveles de riesgo.
     *
     * La serie se "siembra" con la media simple de los primeros $period
     * true ranges (la formula clasica de Wilder) y a partir de ahi cada
     * valor se suaviza con el anterior: atr[i] = (atr[i-1] * (period-1) +
     * trueRange[i]) / period.
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

        $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;

        for ($index = $period; $index < count($trueRanges); $index++) {
            $atr = (($atr * ($period - 1)) + $trueRanges[$index]) / $period;
        }

        return $atr;
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
     * Momentum 12-1: retorno del periodo largo EXCLUYENDO las ultimas
     * $skip sesiones. Con 250/21 es el "12 menos 1" clasico — un año de
     * revalorizacion sin contar el ultimo mes.
     *
     * El salto no es un detalle: medido sobre 10 años (ver versions.md
     * v2.76) el mismo periodo de 250 sesiones SIN excluir el ultimo mes
     * sigue ordenando al reves el retorno futuro, y excluyendolo endereza
     * el signo. Lo que contamina es el ultimo mes, donde domina la
     * reversion a corto plazo.
     *
     * @param list<float> $closes
     */
    private function momentumSkippingRecent(array $closes, int $period, int $skip): ?float
    {
        $count = count($closes);

        if ($count <= $period) {
            return null;
        }

        $end = $closes[$count - 1 - $skip];
        $start = $closes[$count - 1 - $period];

        return $start > 0 ? (($end - $start) / $start) * 100 : null;
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
     * Serie completa de RSI: aplica en cada indice la misma formula que
     * rsi() sobre la ventana de $period cambios terminada en ese indice, de
     * forma que el ultimo valor de esta serie coincide con rsi($closes,
     * $period) para el array completo.
     *
     * @param list<float> $closes
     * @return list<float|null>
     */
    private function rsiSeries(array $closes, int $period): array
    {
        $count = count($closes);
        $result = array_fill(0, $count, null);

        for ($index = $period; $index < $count; $index++) {
            $slice = array_slice($closes, $index - $period, $period + 1);
            $gains = 0.0;
            $losses = 0.0;

            for ($i = 1; $i < count($slice); $i++) {
                $change = $slice[$i] - $slice[$i - 1];

                if ($change >= 0) {
                    $gains += $change;
                } else {
                    $losses += abs($change);
                }
            }

            if ($losses === 0.0) {
                $result[$index] = 100.0;

                continue;
            }

            $relativeStrength = ($gains / $period) / ($losses / $period);
            $result[$index] = 100 - (100 / (1 + $relativeStrength));
        }

        return $result;
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

    /**
     * Valor de la serie $offset posiciones antes de la ultima definida:
     * localiza el mismo indice que lastDefined() y retrocede $offset pasos
     * desde ahi, en vez de devolver ese ultimo valor. Se usa para comparar
     * el valor actual de un indicador con el que tenia unas sesiones atras
     * (p.ej. detectar un cruce reciente del histograma MACD).
     *
     * @param list<float|null> $series
     */
    private function valueBeforeLast(array $series, int $offset): ?float
    {
        for ($index = count($series) - 1; $index >= 0; $index--) {
            if ($series[$index] !== null) {
                $targetIndex = $index - $offset;

                return $targetIndex >= 0 ? $series[$targetIndex] : null;
            }
        }

        return null;
    }
}
