<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\DTO;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\PriceChartSeries;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;

/**
 * Bug real senalado por Astra/Codex el 2026-09-06
 * (`MEJORAS_MOTOR_ASTRA_2026-09-06.md`, P1): `TechnicalScoreAnalyzer`
 * rellena cada indicador ausente con un valor neutro (la mitad de sus
 * puntos) para no romper la suma de TECHNICAL+MOMENTUM+RISK -- correcto
 * para UN hueco aislado, pero con TODOS los indicadores ausentes el
 * resultado es exactamente el 50% del maximo (15+5+5 de 30+10+10), que
 * `Score::recommendationFor()` clasifica como `SELL`: "no tengo datos" se
 * convertia en una orden de venta. Un historico de una sola sesion (una
 * OPV reciente) es el caso real y verificable que lo demuestra.
 *
 * `StockAnalysis::getRecommendation()` es el punto unico donde se corrige:
 * `Score::recommendationFor()` (formula pura sobre un porcentaje, tambien
 * usada por `BacktestingService` sobre percentiles historicos que no
 * tienen este concepto) y `TechnicalScoreAnalyzer` (que sigue rellenando
 * neutro para un hueco aislado, correcto) no se tocan.
 */
final class StockAnalysisRecommendationTest extends TestCase
{
    private function stock(): Stock
    {
        return new Stock(
            new Company('ACME', 'Acme Corp', 'Technology', 'Software', 'NASDAQ', 'USD'),
            new Quote(100.0, 99.0, 101.0, 98.0, 100.0, 1_000_000, new DateTimeImmutable('2026-08-01')),
            Fundamentals::empty()
        );
    }

    /**
     * Mismo criterio que RecommendationExplainerTest::scoreWithPercentage():
     * toda la ponderacion en TECHNICAL (max 100, resto a 0) para que
     * $percentage sea EXACTAMENTE el porcentaje resultante, sin depender de
     * los pesos reales de config/weights.php.
     */
    private function scoreAt(float $percentage): Score
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

    private function analysisWithSnapshot(TechnicalSnapshot $snapshot, float $percentage = 50.0): StockAnalysis
    {
        return new StockAnalysis(
            $this->stock(),
            $this->scoreAt($percentage),
            $snapshot,
            [],
            new PriceChartSeries([], [], [], [], [], [], [], [], [])
        );
    }

    private function emptySnapshot(): TechnicalSnapshot
    {
        return new TechnicalSnapshot(
            null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, 1
        );
    }

    private function fullSnapshot(): TechnicalSnapshot
    {
        return new TechnicalSnapshot(
            100.0, 95.0, 99.0, 97.0, 60.0, 2.0, 1.5, 0.5, 0.3, 110.0, 100.0, 90.0, 3.0, 5.0, 2.0, 1_000_000.0, 900_000, 120.0, 80.0, 250, 15.0
        );
    }

    public function testDiezIndicadoresAusentesCuentaCero(): void
    {
        self::assertSame(0, $this->emptySnapshot()->availableIndicatorCount());
        self::assertFalse($this->emptySnapshot()->hasSufficientTechnicalData());
    }

    public function testDiezIndicadoresPresentesCuentaDiez(): void
    {
        self::assertSame(10, $this->fullSnapshot()->availableIndicatorCount());
        self::assertTrue($this->fullSnapshot()->hasSufficientTechnicalData());
    }

    /**
     * El cruce de medias y las Bandas de Bollinger necesitan el PAR
     * completo: tener solo una de las dos mitades no cuenta como
     * indicador disponible, igual que TechnicalScoreAnalyzer no las
     * puntua sin el par.
     */
    public function testElCruceYBollingerNecesitanElParCompleto(): void
    {
        $soloSma20 = new TechnicalSnapshot(
            100.0, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, 1
        );

        // sma20 disponible (1), pero el cruce necesita sma20 Y sma50 (no
        // cuenta): total 1, no 2.
        self::assertSame(1, $soloSma20->availableIndicatorCount());

        $soloBollingerSuperior = new TechnicalSnapshot(
            null, null, null, null, null, null, null, null, null, 110.0, null, null, null, null, null, null, null, null, null, 1
        );

        self::assertSame(0, $soloBollingerSuperior->availableIndicatorCount());
    }

    /**
     * El caso real que motivo la correccion: TODOS los indicadores
     * ausentes (una OPV con una sola sesion de historico) aterrizaba
     * exactamente en el 50% del score (relleno neutro de
     * TECHNICAL+MOMENTUM+RISK), que Score::recommendationFor() clasifica
     * como SELL.
     */
    public function testSinNingunIndicadorNoDevuelveSell(): void
    {
        $analysis = $this->analysisWithSnapshot($this->emptySnapshot(), 50.0);

        // Confirma la premisa del bug: el Score en bruto SI caeria en SELL.
        self::assertSame('SELL', $analysis->getScore()->getRecommendation());

        // Pero el analisis completo no debe devolver eso.
        self::assertSame('DATOS_INSUFICIENTES', $analysis->getRecommendation());
        self::assertNotSame('SELL', $analysis->getRecommendation());
    }

    public function testConDatosSuficientesDelegaEnScore(): void
    {
        $analysis = $this->analysisWithSnapshot($this->fullSnapshot(), 80.0);

        self::assertSame('BUY', $analysis->getRecommendation());
    }

    /**
     * Datos PARCIALES (varios indicadores ausentes, pero no la mayoria):
     * sigue delegando en Score con normalidad. Ej. una OPV reciente sin
     * los 250 dias de historico que exige Momentum 12-1, pero con el
     * resto de indicadores ya calculables.
     */
    public function testConDatosParcialesPeroSuficientesDelegaEnScore(): void
    {
        $snapshot = new TechnicalSnapshot(
            100.0, 95.0, null, null, 60.0, null, null, null, null, null, null, null, 3.0, null, 2.0, null, null, null, null, 60
        );

        self::assertSame(6, $snapshot->availableIndicatorCount());
        self::assertTrue($snapshot->hasSufficientTechnicalData());

        $analysis = $this->analysisWithSnapshot($snapshot, 65.0);

        self::assertSame('HOLD', $analysis->getRecommendation());
    }

    /**
     * Justo en el umbral (5 de 10, la mitad): todavia se considera
     * suficiente. 4 de 10 ya no. `sma20`+`sma50` cuentan 3 veces (los dos
     * mas el cruce, que necesita el par completo).
     */
    public function testElUmbralEsInclusivoEnLaMitad(): void
    {
        $conCinco = new TechnicalSnapshot(
            100.0, 95.0, null, null, 60.0, null, null, null, null, null, null, null, null, null, 2.0, null, null, null, null, 1
        );
        // sma20, sma50, cruce, rsi14, volatilidad20 = 5.
        self::assertSame(5, $conCinco->availableIndicatorCount());
        self::assertTrue($conCinco->hasSufficientTechnicalData());

        $conCuatro = new TechnicalSnapshot(
            100.0, 95.0, null, null, 60.0, null, null, null, null, null, null, null, null, null, null, null, null, null, null, 1
        );
        // sma20, sma50, cruce, rsi14 = 4 (sin volatilidad20).
        self::assertSame(4, $conCuatro->availableIndicatorCount());
        self::assertFalse($conCuatro->hasSufficientTechnicalData());
    }

    /**
     * Recuperacion de cobertura: el mismo ticker, mas adelante, con
     * suficiente historico ya acumulado, vuelve a comportarse con
     * normalidad -- no hay ningun estado "pegajoso".
     */
    public function testRecuperarCoberturaVuelveADelegarEnScore(): void
    {
        $insuficiente = $this->analysisWithSnapshot($this->emptySnapshot(), 50.0);
        self::assertSame('DATOS_INSUFICIENTES', $insuficiente->getRecommendation());

        $recuperado = $this->analysisWithSnapshot($this->fullSnapshot(), 50.0);
        self::assertSame('SELL', $recuperado->getRecommendation());
    }
}
