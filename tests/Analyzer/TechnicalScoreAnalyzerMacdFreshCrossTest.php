<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Analyzer;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StockAnalyzer\Analyzer\TechnicalScoreAnalyzer;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\Signal;
use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Enums\SignalVerdict;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;

/**
 * Cubre la mejora v2.53 (ver versions.md): dentro de
 * TechnicalScoreAnalyzer::technical(), el sub-bloque MACD ya no puntua solo
 * por la magnitud del histograma, sino que distingue un cruce alcista
 * reciente (histograma <=0 hace una sesion, positivo hoy) de un histograma
 * ya positivo de forma sostenida. Backtest interno (analista-mercado,
 * horizontes 20/40 dias, 40+ tickers): retorno futuro medio de un cruce
 * fresco 2,56% (win rate 61,0%) frente a 0,64% (51,1%) de un histograma
 * positivo sostenido >=5 sesiones, de ahi que el cruce fresco pase a
 * puntuar el maximo del sub-bloque (6,0) y el resto de tramos positivos se
 * reduzcan un punto (5,0/4,0 en vez de los 6,0/4,5 anteriores). Los
 * umbrales del lado bajista no cambian: el analista no encontro el mismo
 * patron limpio ahi.
 *
 * `technical()` es privado, asi que se testea de forma indirecta a traves
 * del metodo publico `analyze()`, filtrando dentro de la lista de Signals
 * la que tiene label 'MACD' (mismo patron que
 * TechnicalScoreAnalyzerBollingerTest).
 */
final class TechnicalScoreAnalyzerMacdFreshCrossTest extends TestCase
{
    private function analyzer(): TechnicalScoreAnalyzer
    {
        // Pesos explicitos e iguales a los valores por defecto de
        // ScoreCategory::maxScore(), para que TechnicalScoreAnalyzer::scale()
        // sea un no-op y el test no dependa del contenido, mutable, de
        // config/weights.php.
        return new TechnicalScoreAnalyzer(new ScoreWeights([
            'technical' => 30.0,
            'fundamental' => 30.0,
            'valuation' => 20.0,
            'news' => 10.0,
            'momentum' => 10.0,
            'risk' => 10.0,
            'quality' => 10.0,
            'dividend' => 5.0,
        ]));
    }

    private function stock(float $price): Stock
    {
        return new Stock(
            new Company('TST', 'Test Corp', 'Technology', 'Software', 'NASDAQ', 'USD'),
            new Quote($price, $price, $price, $price, $price, 1_000_000, new DateTimeImmutable('2026-07-30')),
            Fundamentals::empty()
        );
    }

    /**
     * Construye un TechnicalSnapshot dejando SMA, Bollinger y volumen en
     * null (ramas "dato ausente" de puntuacion fija), de forma que el test
     * pueda aislar la contribucion del sub-bloque MACD.
     */
    private function snapshot(?float $macdHistogram, ?float $macdHistogramPrevious): TechnicalSnapshot
    {
        return new TechnicalSnapshot(
            null, // sma20 -> rama else, +3.0 fijo
            null, // sma50 -> rama else, +3.0 fijo
            null, // ema12, no usado en technical()
            null, // ema26, no usado en technical()
            null, // rsi14, no usado en technical()
            null, // macd, no usado en technical()
            null, // macdSignal, no usado en technical()
            $macdHistogram,
            $macdHistogramPrevious,
            null, // bollingerUpper -> rama else, +2.0 fijo
            null, // bollingerMiddle, no usado en technical()
            null, // bollingerLower -> rama else, +2.0 fijo
            null, // atr14, no usado en technical()
            null, // momentum30, no usado en technical()
            null, // volatility20, no usado en technical()
            null, // avgVolume20 -> getVolumeRatio() null -> rama else, +2.0 fijo
            null, // lastVolume
            null, // high52w, no usado en technical()
            null, // low52w, no usado en technical()
            300   // historyCount, no influye en technical()
        );
    }

    private function technicalResult(Stock $stock, TechnicalSnapshot $snapshot): CategoryResult
    {
        foreach ($this->analyzer()->analyze($stock, $snapshot) as $result) {
            if ($result->getCategory() === ScoreCategory::TECHNICAL) {
                return $result;
            }
        }

        throw new RuntimeException('No se encontro CategoryResult::TECHNICAL en analyze().');
    }

    private function macdSignal(CategoryResult $technical): Signal
    {
        foreach ($technical->getSignals() as $signal) {
            if ($signal->getLabel() === 'MACD') {
                return $signal;
            }
        }

        throw new RuntimeException('No se encontro la señal "MACD" en el CategoryResult.');
    }

    /**
     * Histograma pequeño-positivo (0,1% del precio, tramo que antes daba
     * 4,5/4,0) pero con el histograma anterior negativo: cruce alcista
     * reciente, debe dar el maximo del sub-bloque (6,0) y un verdict
     * POSITIVE con el texto de cruce, no el texto de magnitud habitual.
     */
    public function testCruceAlcistaFrescoDaElMaximoDelSubbloque(): void
    {
        $stock = $this->stock(100.0);
        $snapshot = $this->snapshot(0.10, -0.05);

        $technical = $this->technicalResult($stock, $snapshot);
        $macd = $this->macdSignal($technical);

        self::assertSame(SignalVerdict::POSITIVE, $macd->getVerdict());
        self::assertStringContainsString('cruce alcista reciente', $macd->getMessage());

        // - Precio vs SMA20 (sma20 null): +3,0 fijo
        // - Precio vs SMA50 (sma50 null): +3,0 fijo
        // - Cruce de medias (alguna SMA null): +2,0 fijo
        // - MACD (cruce alcista fresco): +6,0
        // - Bollinger (bandas null): +2,0 fijo
        // - Volumen (ratio null): +2,0 fijo
        // Total = 3 + 3 + 2 + 6 + 2 + 2 = 18,0
        self::assertEqualsWithDelta(18.0, $technical->getScore(), 0.0001);
    }

    /**
     * Histograma grande-positivo (1% del precio, > 0,5%) con el histograma
     * anterior tambien positivo (sostenido, no fresco): debe caer en el
     * tramo `$histPercent > 0.5` y dar 5,0 puntos (antes de esta mejora
     * daba 6,0), con el texto de magnitud habitual, no el de cruce.
     */
    public function testHistogramaSostenidoDaCincoPuntosNoElMaximo(): void
    {
        $stock = $this->stock(100.0);
        $snapshot = $this->snapshot(1.0, 0.8);

        $technical = $this->technicalResult($stock, $snapshot);
        $macd = $this->macdSignal($technical);

        self::assertSame(SignalVerdict::POSITIVE, $macd->getVerdict());
        self::assertStringNotContainsString('cruce alcista reciente', $macd->getMessage());
        self::assertStringContainsString('impulso alcista fuerte', $macd->getMessage());

        // - Precio vs SMA20 (sma20 null): +3,0 fijo
        // - Precio vs SMA50 (sma50 null): +3,0 fijo
        // - Cruce de medias (alguna SMA null): +2,0 fijo
        // - MACD (positivo sostenido, > 0,5%): +5,0
        // - Bollinger (bandas null): +2,0 fijo
        // - Volumen (ratio null): +2,0 fijo
        // Total = 3 + 3 + 2 + 5 + 2 + 2 = 17,0
        self::assertEqualsWithDelta(17.0, $technical->getScore(), 0.0001);
    }

    /**
     * Histograma ausente (null): debe seguir cayendo en la rama "dato
     * ausente" de siempre (+3,0 fijo, sin señal 'MACD' en la lista),
     * exactamente igual que antes de esta mejora, sin depender de
     * macdHistogramPrevious.
     */
    public function testHistogramaAusenteMantieneElComportamientoAnterior(): void
    {
        $stock = $this->stock(100.0);
        $snapshot = $this->snapshot(null, -0.05);

        $technical = $this->technicalResult($stock, $snapshot);

        foreach ($technical->getSignals() as $signal) {
            self::assertNotSame('MACD', $signal->getLabel(), 'No deberia generarse señal MACD sin histograma.');
        }

        // - Precio vs SMA20 (sma20 null): +3,0 fijo
        // - Precio vs SMA50 (sma50 null): +3,0 fijo
        // - Cruce de medias (alguna SMA null): +2,0 fijo
        // - MACD (histograma null): +3,0 fijo
        // - Bollinger (bandas null): +2,0 fijo
        // - Volumen (ratio null): +2,0 fijo
        // Total = 3 + 3 + 2 + 3 + 2 + 2 = 15,0
        self::assertEqualsWithDelta(15.0, $technical->getScore(), 0.0001);
    }
}
