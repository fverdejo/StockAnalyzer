<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\PriceChartSeries;
use StockAnalyzer\DTO\Signal;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Enums\SignalVerdict;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Services\RecommendationExplainer;

/**
 * Cubre RecommendationExplainer, sin test hasta ahora pese a ser la clase
 * que arreglo el bug real de v2.17: el resumen tomaba "las 3 primeras"
 * señales del array combinado y, como el analisis tecnico genera mas
 * Signal por accion que el fundamental, casi siempre dejaba fuera el
 * motivo fundamental aunque si contara para la puntuacion. La correccion
 * (separar `$highlighted` en `technicalReasons`/`fundamentalReasons` y
 * tomar hasta 2 de cada) es justo lo que estos tests protegen de una
 * regresion silenciosa.
 */
final class RecommendationExplainerTest extends TestCase
{
    public function testBuyResumenIncluyeMotivoTecnicoYFundamentalAunqueHayaMasTecnicos(): void
    {
        // 5 señales tecnicas positivas contra 1 fundamental: el bug de
        // v2.17 se disparaba justo con esta forma (tecnicas dominando en
        // numero), tomando "las 3 primeras" del array combinado -> siempre
        // tecnicas, nunca la fundamental.
        $signals = [
            $this->signal('SMA20>SMA50', SignalVerdict::POSITIVE, 'Cruce alcista de medias.', ScoreCategory::TECHNICAL),
            $this->signal('RSI', SignalVerdict::POSITIVE, 'RSI en zona neutral.', ScoreCategory::TECHNICAL),
            $this->signal('MACD', SignalVerdict::POSITIVE, 'MACD cruza al alza.', ScoreCategory::TECHNICAL),
            $this->signal('Momentum', SignalVerdict::POSITIVE, 'Momentum 12-1 positivo.', ScoreCategory::MOMENTUM),
            $this->signal('Volatilidad', SignalVerdict::POSITIVE, 'Volatilidad contenida.', ScoreCategory::RISK),
            $this->signal('ROE', SignalVerdict::POSITIVE, 'ROE por encima del sector.', ScoreCategory::FUNDAMENTAL),
        ];

        $explanation = $this->explain($this->scoreWithPercentage(80.0), $signals);

        self::assertStringContainsString('En el analisis tecnico:', $explanation->getSummary());
        self::assertStringContainsString('En el analisis fundamental:', $explanation->getSummary());
        self::assertStringContainsString('ROE por encima del sector.', $explanation->getSummary());
    }

    public function testBuyResumenLimitaCadaBloqueADosSenalesComoMaximo(): void
    {
        $signals = [
            $this->signal('a', SignalVerdict::POSITIVE, 'Mensaje tecnico A.', ScoreCategory::TECHNICAL),
            $this->signal('b', SignalVerdict::POSITIVE, 'Mensaje tecnico B.', ScoreCategory::TECHNICAL),
            $this->signal('c', SignalVerdict::POSITIVE, 'Mensaje tecnico C.', ScoreCategory::TECHNICAL),
            $this->signal('d', SignalVerdict::POSITIVE, 'Mensaje tecnico D.', ScoreCategory::TECHNICAL),
        ];

        $explanation = $this->explain($this->scoreWithPercentage(90.0), $signals);

        $ocurrencias = 0;
        foreach (['Mensaje tecnico A.', 'Mensaje tecnico B.', 'Mensaje tecnico C.', 'Mensaje tecnico D.'] as $mensaje) {
            if (str_contains($explanation->getSummary(), $mensaje)) {
                $ocurrencias++;
            }
        }

        self::assertSame(2, $ocurrencias, 'pickRandom() debe limitar a 2 señales por bloque, no citar las 4');
    }

    public function testSellResumenDestacaNegativasNoPositivas(): void
    {
        $signals = [
            $this->signal('SMA', SignalVerdict::POSITIVE, 'Señal positiva que no deberia citarse.', ScoreCategory::TECHNICAL),
            $this->signal('RSI', SignalVerdict::NEGATIVE, 'RSI en sobrecompra.', ScoreCategory::TECHNICAL),
            $this->signal('Deuda', SignalVerdict::NEGATIVE, 'Deuda/Patrimonio elevada.', ScoreCategory::FUNDAMENTAL),
        ];

        $explanation = $this->explain($this->scoreWithPercentage(50.0), $signals);

        self::assertStringContainsString('acumula mas señales en contra que a favor', $explanation->getSummary());
        self::assertStringContainsString('RSI en sobrecompra.', $explanation->getSummary());
        self::assertStringContainsString('Deuda/Patrimonio elevada.', $explanation->getSummary());
        self::assertStringNotContainsString('Señal positiva que no deberia citarse.', $explanation->getSummary());
    }

    public function testStrongSellUsaIntroDeCombinacionClaramenteDesfavorable(): void
    {
        $explanation = $this->explain($this->scoreWithPercentage(20.0), [
            $this->signal('RSI', SignalVerdict::NEGATIVE, 'RSI en sobrecompra.', ScoreCategory::TECHNICAL),
        ]);

        self::assertStringContainsString('presenta una combinacion claramente desfavorable de señales', $explanation->getSummary());
        self::assertStringContainsString('(STRONG SELL)', $explanation->getSummary());
    }

    public function testHoldNoDestacaSenalesYAnadeMensajeDeSesgoMixto(): void
    {
        $signals = [
            $this->signal('SMA', SignalVerdict::POSITIVE, 'Positiva que no deberia citarse en HOLD.', ScoreCategory::TECHNICAL),
            $this->signal('RSI', SignalVerdict::NEGATIVE, 'Negativa que no deberia citarse en HOLD.', ScoreCategory::TECHNICAL),
        ];

        $explanation = $this->explain($this->scoreWithPercentage(65.0), $signals);

        self::assertStringContainsString('no muestra un sesgo claro entre señales favorables y desfavorables', $explanation->getSummary());
        self::assertStringContainsString('no hay ahora mismo una razon de peso ni para comprar ni para vender', $explanation->getSummary());
        self::assertStringNotContainsString('Positiva que no deberia citarse en HOLD.', $explanation->getSummary());
        self::assertStringNotContainsString('Negativa que no deberia citarse en HOLD.', $explanation->getSummary());
    }

    public function testBuyConNegativasAnadeAvisoAunAsi(): void
    {
        $signals = [
            $this->signal('SMA', SignalVerdict::POSITIVE, 'Cruce alcista.', ScoreCategory::TECHNICAL),
            $this->signal('RSI', SignalVerdict::NEGATIVE, 'RSI ya elevado.', ScoreCategory::TECHNICAL),
        ];

        $explanation = $this->explain($this->scoreWithPercentage(80.0), $signals);

        self::assertStringContainsString('Aun asi, conviene tener en cuenta: RSI ya elevado.', $explanation->getSummary());
    }

    public function testAgrupaSenalesPorVeredictoIndependientementeDeLaCategoria(): void
    {
        $signals = [
            $this->signal('a', SignalVerdict::POSITIVE, 'Positiva tecnica.', ScoreCategory::TECHNICAL),
            $this->signal('b', SignalVerdict::POSITIVE, 'Positiva fundamental.', ScoreCategory::FUNDAMENTAL),
            $this->signal('c', SignalVerdict::NEGATIVE, 'Negativa.', ScoreCategory::RISK),
            $this->signal('d', SignalVerdict::NEUTRAL, 'Neutral.', ScoreCategory::MOMENTUM),
        ];

        $explanation = $this->explain($this->scoreWithPercentage(80.0), $signals);

        self::assertCount(2, $explanation->getPositives());
        self::assertCount(1, $explanation->getNegatives());
        self::assertCount(1, $explanation->getNeutrals());
    }

    /**
     * @param list<Signal> $signals
     */
    private function explain(Score $score, array $signals): \StockAnalyzer\DTO\Explanation
    {
        $analysis = $this->analysis($score, $signals);

        return (new RecommendationExplainer())->explain($analysis);
    }

    /**
     * @param list<Signal> $signals
     */
    private function analysis(Score $score, array $signals): StockAnalysis
    {
        $stock = new Stock(
            new Company('ACME', 'Acme Corp', 'Technology', 'Software', 'NASDAQ', 'USD'),
            new Quote(100.0, 99.0, 101.0, 98.0, 100.0, 1_000_000, new \DateTimeImmutable('2026-08-01')),
            Fundamentals::empty()
        );

        $snapshot = new TechnicalSnapshot(
            null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, 0
        );

        return new StockAnalysis(
            $stock,
            $score,
            $snapshot,
            [new CategoryResult(ScoreCategory::TECHNICAL, 0, $signals)],
            new PriceChartSeries([], [], [], [], [], [], [], [], [])
        );
    }

    /**
     * Score cuyo porcentaje es exactamente $percentage: toda la ponderacion
     * se concentra en TECHNICAL (max 100, resto a 0) para no depender de
     * los pesos reales de config/weights.php ni de ScoreCategory::maxScore()
     * (que hoy pone FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND a 0 en la rama
     * solo-tecnico: si cambiaran, este test no deberia romperse por eso).
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

    private function signal(string $label, SignalVerdict $verdict, string $message, ScoreCategory $category): Signal
    {
        return new Signal($label, $verdict, $message, $category);
    }
}
