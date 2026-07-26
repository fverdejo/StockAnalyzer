<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Enums\ScoreCategory;

/**
 * Resultado de evaluar una ScoreCategory: la puntuacion obtenida junto con
 * las Signals que la justifican. Lo produce cada analizador
 * (TechnicalScoreAnalyzer, FundamentalAnalyzer...) y lo consume tanto
 * Score (para el total) como RecommendationExplainer (para el texto).
 */
class CategoryResult
{
    /**
     * @param list<Signal> $signals
     */
    public function __construct(
        private readonly ScoreCategory $category,
        private readonly float $score,
        private readonly array $signals
    ) {
    }

    public function getCategory(): ScoreCategory
    {
        return $this->category;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    /**
     * @return list<Signal>
     */
    public function getSignals(): array
    {
        return $this->signals;
    }
}
