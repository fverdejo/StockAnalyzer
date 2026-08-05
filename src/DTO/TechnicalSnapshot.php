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
        private readonly int $historyCount
    ) {
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
}
