<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;

class StockAnalysis
{
    /**
     * @param list<CategoryResult> $categoryResults
     */
    public function __construct(
        private readonly Stock $stock,
        private readonly Score $score,
        private readonly TechnicalSnapshot $technicalSnapshot,
        private readonly array $categoryResults,
        private readonly PriceChartSeries $chartSeries,
        private readonly ?RiskLevels $riskLevels = null
    ) {
    }

    public function getStock(): Stock
    {
        return $this->stock;
    }

    public function getScore(): Score
    {
        return $this->score;
    }

    public function getTechnicalSnapshot(): TechnicalSnapshot
    {
        return $this->technicalSnapshot;
    }

    /**
     * @return list<CategoryResult>
     */
    public function getCategoryResults(): array
    {
        return $this->categoryResults;
    }

    public function getChartSeries(): PriceChartSeries
    {
        return $this->chartSeries;
    }

    /**
     * Stop-loss/objetivo sugeridos basados en ATR14 (ver
     * Services\RiskLevelsCalculator). Null cuando no hay datos suficientes
     * para calcularlo de forma fiable (historico insuficiente, ATR14 no
     * disponible o despreciable frente al precio).
     */
    public function getRiskLevels(): ?RiskLevels
    {
        return $this->riskLevels;
    }
}
