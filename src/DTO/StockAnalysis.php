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

    /**
     * El valor canonico (`'BUY'`/`'HOLD'`/`'SELL'`/`'STRONG SELL'`/
     * `'DATOS_INSUFICIENTES'`) que debe mostrarse/usarse para ESTE
     * analisis en vivo -- unico punto de entrada para saber la
     * recomendacion de un `StockAnalysis`, en vez de leer
     * `getScore()->getRecommendation()` directamente.
     *
     * **Correccion del 2026-09-06** (bug real senalado por Astra/Codex,
     * `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, P1): `TechnicalScoreAnalyzer`
     * rellena con un valor neutro cada indicador ausente para no romper la
     * suma, pero si faltan casi todos, el resultado no son "señales
     * mixtas": es que no hay dato para opinar. Sin este metodo, un ticker
     * sin historico tecnico utilizable (ej. una OPV con una sola sesion)
     * aterrizaba exactamente en el 50% del score (TECHNICAL+MOMENTUM+RISK
     * todo relleno neutro), que `Score::recommendationFor()` clasifica
     * como `SELL` -- "no tengo datos" se convertia en una orden de venta.
     *
     * Deliberadamente NO se toca `Score::recommendationFor()` (formula
     * pura sobre un porcentaje, reutilizada tal cual por
     * `BacktestingService` sobre percentiles historicos que no tienen
     * este concepto de cobertura en vivo) ni `TechnicalScoreAnalyzer`
     * (sigue rellenando neutro para UN hueco aislado, que es correcto):
     * la comprobacion vive aqui, en el analisis EN VIVO de un ticker
     * concreto, que es donde "casi todo ausente" puede ocurrir de verdad.
     */
    public function getRecommendation(): string
    {
        if (!$this->technicalSnapshot->hasSufficientTechnicalData()) {
            return 'DATOS_INSUFICIENTES';
        }

        return $this->score->getRecommendation();
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
