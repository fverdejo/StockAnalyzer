<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Models\Score;

/**
 * Salida de ScoreCalculator: el Score agregado (para el ranking) junto con
 * el detalle por categoria (para la pantalla de detalle y el explicador de
 * recomendaciones).
 */
class ScoreResult
{
    /**
     * @param list<CategoryResult> $categoryResults
     */
    public function __construct(
        private readonly Score $score,
        private readonly array $categoryResults
    ) {
    }

    public function getScore(): Score
    {
        return $this->score;
    }

    /**
     * @return list<CategoryResult>
     */
    public function getCategoryResults(): array
    {
        return $this->categoryResults;
    }

    /**
     * @return list<Signal>
     */
    public function getAllSignals(): array
    {
        $signals = [];

        foreach ($this->categoryResults as $result) {
            foreach ($result->getSignals() as $signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }
}
