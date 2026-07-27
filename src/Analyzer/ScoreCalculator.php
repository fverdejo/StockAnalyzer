<?php

declare(strict_types=1);

namespace StockAnalyzer\Analyzer;

use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\ScoreResult;
use StockAnalyzer\DTO\TechnicalSnapshot;
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
 * NEWS se delega en NewsAnalyzer cuando esta disponible. Si no se inyecta,
 * la categoria queda neutra para mantener la aplicacion usable sin tabla de
 * noticias ni importacion previa.
 */
class ScoreCalculator
{
    private readonly ScoreWeights $weights;
    private readonly TechnicalScoreAnalyzer $technicalScoreAnalyzer;
    private readonly FundamentalAnalyzer $fundamentalAnalyzer;
    private readonly ?NewsAnalyzer $newsAnalyzer;

    public function __construct(?ScoreWeights $weights = null, ?NewsAnalyzer $newsAnalyzer = null)
    {
        $this->weights = $weights ?? new ScoreWeights();
        $this->technicalScoreAnalyzer = new TechnicalScoreAnalyzer($this->weights);
        $this->fundamentalAnalyzer = new FundamentalAnalyzer($this->weights);
        $this->newsAnalyzer = $newsAnalyzer;
    }

    public function calculate(Stock $stock, TechnicalSnapshot $technical): ScoreResult
    {
        $categoryResults = [
            ...$this->technicalScoreAnalyzer->analyze($stock, $technical),
            ...$this->fundamentalAnalyzer->analyze($stock->getFundamentals()),
            $this->newsAnalyzer?->analyze($stock) ?? $this->newsPlaceholder(),
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
            \StockAnalyzer\Enums\ScoreCategory::NEWS,
            $this->weights->getMax(\StockAnalyzer\Enums\ScoreCategory::NEWS) / 2,
            [new \StockAnalyzer\DTO\Signal(
                'Noticias',
                \StockAnalyzer\Enums\SignalVerdict::NEUTRAL,
                'No hay analizador de noticias configurado; esta categoria se mantiene neutra.'
            )]
        );
    }
}
