<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;

class StockAnalysis
{
    public function __construct(
        private readonly Stock $stock,
        private readonly Score $score,
        private readonly TechnicalSnapshot $technicalSnapshot
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
}
