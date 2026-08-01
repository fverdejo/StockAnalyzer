<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * Series historicas alineadas por fecha, listas para dibujar en un grafico
 * (Chart.js). A diferencia de TechnicalSnapshot, que solo guarda el ultimo
 * valor de cada indicador, aqui se conserva el historico completo.
 */
class PriceChartSeries
{
    /**
     * @param list<string> $labels Fechas en formato Y-m-d
     * @param list<float> $closes
     * @param list<float> $highs
     * @param list<float> $lows
     * @param list<float|null> $sma20
     * @param list<float|null> $sma50
     * @param list<float|null> $bollingerUpper
     * @param list<float|null> $bollingerLower
     * @param list<int> $volumes
     * @param list<float|null> $macd
     * @param list<float|null> $macdSignal
     * @param list<float|null> $macdHistogram
     * @param list<float|null> $rsi14
     */
    public function __construct(
        private readonly array $labels,
        private readonly array $closes,
        private readonly array $highs,
        private readonly array $lows,
        private readonly array $sma20,
        private readonly array $sma50,
        private readonly array $bollingerUpper,
        private readonly array $bollingerLower,
        private readonly array $volumes,
        private readonly array $macd = [],
        private readonly array $macdSignal = [],
        private readonly array $macdHistogram = [],
        private readonly array $rsi14 = []
    ) {
    }

    /**
     * @return list<string>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * @return list<float>
     */
    public function getCloses(): array
    {
        return $this->closes;
    }

    /**
     * @return list<float>
     */
    public function getHighs(): array
    {
        return $this->highs;
    }

    /**
     * @return list<float>
     */
    public function getLows(): array
    {
        return $this->lows;
    }

    /**
     * @return list<float|null>
     */
    public function getSma20(): array
    {
        return $this->sma20;
    }

    /**
     * @return list<float|null>
     */
    public function getSma50(): array
    {
        return $this->sma50;
    }

    /**
     * @return list<float|null>
     */
    public function getBollingerUpper(): array
    {
        return $this->bollingerUpper;
    }

    /**
     * @return list<float|null>
     */
    public function getBollingerLower(): array
    {
        return $this->bollingerLower;
    }

    /**
     * @return list<int>
     */
    public function getVolumes(): array
    {
        return $this->volumes;
    }

    /**
     * @return list<float|null>
     */
    public function getMacd(): array
    {
        return $this->macd;
    }

    /**
     * @return list<float|null>
     */
    public function getMacdSignal(): array
    {
        return $this->macdSignal;
    }

    /**
     * @return list<float|null>
     */
    public function getMacdHistogram(): array
    {
        return $this->macdHistogram;
    }

    /**
     * @return list<float|null>
     */
    public function getRsi14(): array
    {
        return $this->rsi14;
    }
}
