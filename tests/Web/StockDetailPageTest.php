<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\Explanation;
use StockAnalyzer\DTO\PriceChartSeries;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Web\StockDetailPage;

/**
 * Cubre el matiz "no abrir posicion" (sin holding) frente a
 * "mantener/reducir/vigilar" (con holding) que se añade junto al badge de
 * recomendacion en la ficha de detalle cuando el veredicto es SELL/STRONG
 * SELL (ver roadmap.md "Cuarto bloque" y versions.md 2026-09-02). El texto
 * en si lo genera `PositionRecommendationNotice`, cubierto aparte; aqui solo
 * se comprueba que `StockDetailPage::render()` lo conecta con el parametro
 * `$position` que ya recibia desde v2.72.
 */
final class StockDetailPageTest extends TestCase
{
    public function testSinPosicionYSellRecomiendaNoAbrir(): void
    {
        $html = $this->render($this->scoreWithPercentage(50.0), null);

        self::assertStringContainsString('No se recomienda abrir posicion en este valor.', $html);
        self::assertStringNotContainsString('Tienes una posicion abierta en este valor', $html);
    }

    public function testConPosicionAbiertaYSellMatizaQueNoEsOrdenDeLiquidar(): void
    {
        $html = $this->render($this->scoreWithPercentage(50.0), new Holding('ACME', 10.0, 100.0, 90.0));

        self::assertStringContainsString('Tienes una posicion abierta en este valor', $html);
        self::assertStringContainsString('no es una orden automatica de liquidarla', $html);
        self::assertStringNotContainsString('No se recomienda abrir posicion', $html);
    }

    public function testBuyNoMuestraNingunAvisoDePosicionConOSinHolding(): void
    {
        $conHolding = $this->render($this->scoreWithPercentage(80.0), new Holding('ACME', 10.0, 100.0, 110.0));
        $sinHolding = $this->render($this->scoreWithPercentage(80.0), null);

        self::assertStringNotContainsString('No se recomienda abrir posicion', $conHolding);
        self::assertStringNotContainsString('Tienes una posicion abierta en este valor', $conHolding);
        self::assertStringNotContainsString('No se recomienda abrir posicion', $sinHolding);
        self::assertStringNotContainsString('Tienes una posicion abierta en este valor', $sinHolding);
    }

    private function render(Score $score, ?Holding $position): string
    {
        $analysis = $this->analysis($score);
        $explanation = new Explanation('Resumen de prueba.', [], [], []);

        return StockDetailPage::render(
            $analysis,
            $explanation,
            '?page=dashboard',
            null,
            'token',
            false,
            null,
            null,
            null,
            null,
            $position,
            []
        );
    }

    private function analysis(Score $score): StockAnalysis
    {
        $stock = new Stock(
            new Company('ACME', 'Acme Corp', 'Technology', 'Software', 'NASDAQ', 'USD'),
            new Quote(100.0, 99.0, 101.0, 98.0, 100.0, 1_000_000, new DateTimeImmutable('2026-08-01')),
            Fundamentals::empty()
        );

        $snapshot = new TechnicalSnapshot(
            null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, 0
        );

        return new StockAnalysis(
            $stock,
            $score,
            $snapshot,
            [new CategoryResult(ScoreCategory::TECHNICAL, 0, [])],
            new PriceChartSeries([], [], [], [], [], [], [], [], [])
        );
    }

    /**
     * Mismo criterio que RecommendationExplainerTest::scoreWithPercentage():
     * toda la ponderacion en TECHNICAL para no depender de los pesos reales
     * de config/weights.php.
     */
    private function scoreWithPercentage(float $percentage): Score
    {
        $weights = new ScoreWeights([
            'technical' => 100.0,
            'fundamental' => 0.0,
            'valuation' => 0.0,
            'news' => 0.0,
            'momentum' => 0.0,
            'risk' => 0.0,
            'quality' => 0.0,
            'dividend' => 0.0,
        ]);

        return (new Score($weights))->add(ScoreCategory::TECHNICAL, $percentage);
    }
}
