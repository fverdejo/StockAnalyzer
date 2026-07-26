<?php

declare(strict_types=1);

namespace StockAnalyzer\Analyzer;

use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\ScoreResult;
use StockAnalyzer\DTO\Signal;
use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Enums\SignalVerdict;
use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;

/**
 * Orquesta los analizadores especializados (tecnico y fundamental), suma
 * sus puntuaciones en un Score y conserva las Signals de cada uno para que
 * RecommendationExplainer pueda explicar el resultado sin recalcular nada.
 *
 * Los pesos (ScoreWeights) se cargan una vez aqui y se pasan tanto a los
 * analizadores como al Score final, para que los tres usen siempre el
 * mismo maximo por categoria y no puedan desincronizarse.
 *
 * NEWS sigue siendo un valor fijo: no hay todavia un proveedor de noticias
 * (ver roadmap v1.7), asi que se deja como neutro en lugar de simularlo.
 */
class ScoreCalculator
{
    private readonly ScoreWeights $weights;
    private readonly TechnicalScoreAnalyzer $technicalScoreAnalyzer;
    private readonly FundamentalAnalyzer $fundamentalAnalyzer;

    public function __construct(?ScoreWeights $weights = null)
    {
        $this->weights = $weights ?? new ScoreWeights();
        $this->technicalScoreAnalyzer = new TechnicalScoreAnalyzer($this->weights);
        $this->fundamentalAnalyzer = new FundamentalAnalyzer($this->weights);
    }

    public function calculate(Stock $stock, TechnicalSnapshot $technical): ScoreResult
    {
        $categoryResults = [
            ...$this->technicalScoreAnalyzer->analyze($stock, $technical),
            ...$this->fundamentalAnalyzer->analyze($stock->getFundamentals()),
            $this->newsPlaceholder(),
        ];

        $score = new Score($this->weights);

        foreach ($categoryResults as $categoryResult) {
            $score->add($categoryResult->getCategory(), $categoryResult->getScore());
        }

        return new ScoreResult($score, $categoryResults);
    }

    public function getWeights(): ScoreWeights
    {
        return $this->weights;
    }

    private function newsPlaceholder(): CategoryResult
    {
        return new CategoryResult(
            ScoreCategory::NEWS,
            $this->weights->getMax(ScoreCategory::NEWS) / 2,
            [new Signal(
                'Noticias',
                SignalVerdict::NEUTRAL,
                'El analisis de noticias todavia no esta implementado; esta categoria se mantiene neutra y no influye en la recomendacion.'
            )]
        );
    }
}
