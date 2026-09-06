<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * Foto del ultimo valor de cada indicador tecnico. Para las series
 * historicas completas (necesarias en el grafico) usar PriceChartSeries.
 */
class TechnicalSnapshot
{
    public function __construct(
        private readonly ?float $sma20,
        private readonly ?float $sma50,
        private readonly ?float $ema12,
        private readonly ?float $ema26,
        private readonly ?float $rsi14,
        private readonly ?float $macd,
        private readonly ?float $macdSignal,
        private readonly ?float $macdHistogram,
        private readonly ?float $macdHistogramPrevious,
        private readonly ?float $bollingerUpper,
        private readonly ?float $bollingerMiddle,
        private readonly ?float $bollingerLower,
        private readonly ?float $atr14,
        private readonly ?float $momentum30,
        private readonly ?float $volatility20,
        private readonly ?float $avgVolume20,
        private readonly ?int $lastVolume,
        private readonly ?float $high52w,
        private readonly ?float $low52w,
        private readonly int $historyCount,
        /**
         * Momentum 12-1 (v2.76): retorno de 250 sesiones EXCLUYENDO el
         * ultimo mes. Va al final y con default para no romper las
         * construcciones posicionales ya existentes.
         */
        private readonly ?float $momentum12m1 = null
    ) {
    }

    /**
     * Momentum "12 menos 1": lo que el valor se ha revalorizado en el
     * ultimo año sin contar el ultimo mes.
     *
     * Medido sobre 10 años (ver versions.md v2.76), el momentum de 30
     * sesiones ordena AL REVES el retorno a 20 dias (spread decil alto -
     * decil bajo de -1,94 pp en largecap60 y -1,59 en ibex35), porque a ese
     * plazo domina la reversion a corto. Excluir el ultimo mes es
     * justamente lo que quita esa contaminacion: el mismo periodo de 250
     * sesiones SIN excluirlo sigue invertido (-0,45), y excluyendolo pasa a
     * +1,15.
     */
    public function getMomentum12m1(): ?float
    {
        return $this->momentum12m1;
    }

    public function getSma20(): ?float
    {
        return $this->sma20;
    }

    public function getSma50(): ?float
    {
        return $this->sma50;
    }

    public function getEma12(): ?float
    {
        return $this->ema12;
    }

    public function getEma26(): ?float
    {
        return $this->ema26;
    }

    public function getRsi14(): ?float
    {
        return $this->rsi14;
    }

    public function getMacd(): ?float
    {
        return $this->macd;
    }

    public function getMacdSignal(): ?float
    {
        return $this->macdSignal;
    }

    public function getMacdHistogram(): ?float
    {
        return $this->macdHistogram;
    }

    /**
     * Histograma MACD una sesion antes de la ultima definida. Se usa para
     * distinguir un cruce alcista reciente (sesion anterior <= 0, actual
     * > 0) de un histograma positivo ya sostenido (ver
     * TechnicalScoreAnalyzer::technical() y versions.md).
     */
    public function getMacdHistogramPrevious(): ?float
    {
        return $this->macdHistogramPrevious;
    }

    public function getBollingerUpper(): ?float
    {
        return $this->bollingerUpper;
    }

    public function getBollingerMiddle(): ?float
    {
        return $this->bollingerMiddle;
    }

    public function getBollingerLower(): ?float
    {
        return $this->bollingerLower;
    }

    public function getAtr14(): ?float
    {
        return $this->atr14;
    }

    public function getMomentum30(): ?float
    {
        return $this->momentum30;
    }

    public function getVolatility20(): ?float
    {
        return $this->volatility20;
    }

    public function getAvgVolume20(): ?float
    {
        return $this->avgVolume20;
    }

    public function getLastVolume(): ?int
    {
        return $this->lastVolume;
    }

    public function getHigh52w(): ?float
    {
        return $this->high52w;
    }

    public function getLow52w(): ?float
    {
        return $this->low52w;
    }

    public function getHistoryCount(): int
    {
        return $this->historyCount;
    }

    /**
     * Volumen reciente respecto a la media de 20 sesiones (1.0 = igual a
     * la media). Se calcula aqui en lugar de guardarse como campo propio
     * porque es un cociente derivado de otros dos campos de este mismo
     * objeto.
     */
    public function getVolumeRatio(): ?float
    {
        if ($this->lastVolume === null || $this->avgVolume20 === null || $this->avgVolume20 <= 0) {
            return null;
        }

        return $this->lastVolume / $this->avgVolume20;
    }

    /**
     * Numero total de indicadores independientes que
     * `Analyzer\TechnicalScoreAnalyzer` puede puntuar con dato real (los
     * 10 "huecos" de sus tres categorias: precio vs SMA20, precio vs
     * SMA50, cruce de medias, MACD, Bandas de Bollinger y Volumen en
     * TECHNICAL; Momentum 12-1 y RSI14 en MOMENTUM; Volatilidad20 y ATR14
     * en RISK). Cuando falta uno, `TechnicalScoreAnalyzer` no lo deja en
     * blanco: le asigna un relleno "neutro" (la mitad de sus puntos) para
     * no romper la suma -- correcto para UN hueco aislado, pero si faltan
     * casi todos, el resultado no es "señales mixtas", es "no hay dato
     * para opinar". Ver `availableIndicatorCount()`/
     * `hasSufficientTechnicalData()`.
     */
    public const TOTAL_INDICATOR_COUNT = 10;

    /**
     * Cuantos de los `TOTAL_INDICATOR_COUNT` indicadores tienen dato real
     * (no relleno neutro) en este snapshot. El cruce de medias y las
     * Bandas de Bollinger cuentan como UN indicador cada uno (igual que
     * los puntua `TechnicalScoreAnalyzer`: hace falta el PAR completo para
     * calcularlos), no dos.
     */
    public function availableIndicatorCount(): int
    {
        return count(array_filter([
            $this->sma20 !== null,
            $this->sma50 !== null,
            $this->sma20 !== null && $this->sma50 !== null,
            $this->macdHistogram !== null,
            $this->bollingerUpper !== null && $this->bollingerLower !== null,
            $this->getVolumeRatio() !== null,
            $this->momentum12m1 !== null,
            $this->rsi14 !== null,
            $this->volatility20 !== null,
            $this->atr14 !== null,
        ]));
    }

    /**
     * Umbral minimo (2026-09-06, correccion del bug real senalado por
     * Astra/Codex en `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, P1): con TODOS
     * los indicadores ausentes, TECHNICAL+MOMENTUM+RISK suman exactamente
     * la mitad de su maximo (15+5+5 de 30+10+10 = 25 de 50 = 50%), que
     * `Score::recommendationFor()` clasifica como `SELL` -- "no tengo
     * datos" se convertia en una orden de venta. La mitad de los 10
     * indicadores es el corte mas simple y defendible que evita ese caso
     * extremo (y cualquiera con una mayoria de huecos) sin descartar un
     * ticker por faltarle uno o dos indicadores aislados, que es una
     * situacion normal (una OPV reciente sin 250 sesiones de historico
     * para Momentum 12-1, por ejemplo).
     */
    private const MIN_AVAILABLE_INDICATORS = self::TOTAL_INDICATOR_COUNT / 2;

    /**
     * `false` cuando hay demasiados indicadores ausentes para que
     * TECHNICAL/MOMENTUM/RISK signifiquen "señales mixtas" en vez de
     * "no hay dato para opinar" -- ver `MIN_AVAILABLE_INDICATORS`. Quien
     * consuma esto debe mostrar un estado de "datos insuficientes" en vez
     * de la recomendacion normal (`DTO\StockAnalysis::getRecommendation()`
     * ya lo hace).
     */
    public function hasSufficientTechnicalData(): bool
    {
        return $this->availableIndicatorCount() >= self::MIN_AVAILABLE_INDICATORS;
    }
}
