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
 * Cubre la correccion v2.22 (ver versions.md), que revierte la
 * bonificacion de "tendencia confirmada" introducida en v2.18: dentro de
 * TechnicalScoreAnalyzer::technical(), la sub-señal "Bandas de Bollinger"
 * cuando el precio esta cerca de la banda superior (`$position >= 0.85`)
 * ya NO depende de `$sma20 > $sma50`. El backtest interno (6 universos,
 * 3 horizontes) no encontro que la tendencia confirmada mejorase el
 * retorno futuro desde esa zona, asi que la puntuacion pasa a ser fija
 * (1,5, un valor intermedio entre el 1,0 y el 3,0 anteriores) y el
 * verdict pasa a ser siempre NEGATIVE.
 *
 * `technical()` es privado, asi que se testea de forma indirecta a
 * traves del metodo publico `analyze()`, y se filtra dentro de la lista
 * de Signals la que tiene label 'Bandas de Bollinger' para no acoplar
 * el test a las demas sub-señales del mismo metodo (SMA20/50, cruce,
 * MACD, volumen). En los casos donde tambien se verifica el score total
 * de la categoria TECHNICAL, esas demas sub-señales se dejan en su rama
 * "dato ausente" (valor fijo documentado en cada test) precisamente para
 * poder aislar la contribucion de Bollinger en la suma.
 *
 * Bandas de Bollinger fijas en todos los casos: upper=112, lower=98 (ver
 * definicion de la tarea acordada entre los agentes anteriores).
 */
final class TechnicalScoreAnalyzerBollingerTest extends TestCase
{
    private const BOLLINGER_UPPER = 112.0;
    private const BOLLINGER_LOWER = 98.0;

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
     * Construye un TechnicalSnapshot dejando MACD, volumen y el resto de
     * campos no relevantes para el bloque de Bollinger en null, de forma
     * que esas sub-señales de technical() caigan siempre en su rama
     * "else" de puntuacion fija (ver comentarios de puntuacion en cada
     * test) y no interfieran al comparar el score total de la categoria.
     */
    private function snapshot(?float $sma20, ?float $sma50): TechnicalSnapshot
    {
        return new TechnicalSnapshot(
            $sma20,
            $sma50,
            null, // ema12, no usado en technical()
            null, // ema26, no usado en technical()
            null, // rsi14, no usado en technical()
            null, // macd, no usado en technical()
            null, // macdSignal, no usado en technical()
            null, // macdHistogram -> getMacdHistogram() null -> rama else, +3.0 fijo
            null, // macdHistogramPrevious, no usado en technical() con histograma null
            self::BOLLINGER_UPPER,
            null, // bollingerMiddle, no usado en technical()
            self::BOLLINGER_LOWER,
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

    private function bollingerSignal(CategoryResult $technical): Signal
    {
        foreach ($technical->getSignals() as $signal) {
            if ($signal->getLabel() === 'Bandas de Bollinger') {
                return $signal;
            }
        }

        throw new RuntimeException('No se encontro la señal "Bandas de Bollinger" en el CategoryResult.');
    }

    /**
     * Caso 1 (umbral acordado): tendencia confirmada (sma20=105 > sma50=100)
     * + precio cerca de banda superior: price=110, upper=112, lower=98 =>
     * position = (110-98)/(112-98) = 0,857... >= 0,85.
     *
     * v2.22: la tendencia confirmada ya no cambia el resultado de esta
     * zona; debe dar 1,5 puntos y verdict NEGATIVE, igual que el caso 2
     * (sin tendencia confirmada) con las mismas bandas y precio.
     */
    public function testBandaSuperiorConTendenciaConfirmadaEsNegativa(): void
    {
        $stock = $this->stock(110.0);
        $snapshot = $this->snapshot(105.0, 100.0);

        $technical = $this->technicalResult($stock, $snapshot);
        $bollinger = $this->bollingerSignal($technical);

        self::assertSame(SignalVerdict::NEGATIVE, $bollinger->getVerdict());
        self::assertStringContainsString('el backtest interno no encuentra', $bollinger->getMessage());

        // Resto de sub-señales de technical() en esta llamada:
        // - Precio vs SMA20 (110 > 105): +6,0
        // - Precio vs SMA50 (110 > 100): +6,0
        // - Cruce de medias (105 > 100, cruce dorado): +4,0
        // - MACD (histograma null): +3,0 fijo
        // - Bollinger (banda superior): +1,5
        // - Volumen (ratio null): +2,0 fijo
        // Total = 6 + 6 + 4 + 3 + 1,5 + 2 = 22,5
        self::assertEqualsWithDelta(22.5, $technical->getScore(), 0.0001);
    }

    /**
     * Caso 2 (umbral acordado): sin tendencia confirmada (sma20=98 <
     * sma50=100) + precio cerca de banda superior, mismas bandas y mismo
     * precio que el caso 1 (position = 0,857... >= 0,85).
     *
     * v2.22: debe dar 1,5 puntos, verdict NEGATIVE (mismo resultado que
     * el caso 1, ya que la tendencia deja de influir en esta zona).
     */
    public function testBandaSuperiorSinTendenciaConfirmadaEsNegativa(): void
    {
        $stock = $this->stock(110.0);
        $snapshot = $this->snapshot(98.0, 100.0);

        $technical = $this->technicalResult($stock, $snapshot);
        $bollinger = $this->bollingerSignal($technical);

        self::assertSame(SignalVerdict::NEGATIVE, $bollinger->getVerdict());
        self::assertStringContainsString('el backtest interno no encuentra', $bollinger->getMessage());

        // - Precio vs SMA20 (110 > 98): +6,0
        // - Precio vs SMA50 (110 > 100): +6,0
        // - Cruce de medias (98 > 100 es falso): +0,0
        // - MACD (histograma null): +3,0 fijo
        // - Bollinger (banda superior): +1,5
        // - Volumen (ratio null): +2,0 fijo
        // Total = 6 + 6 + 0 + 3 + 1,5 + 2 = 18,5
        self::assertEqualsWithDelta(18.5, $technical->getScore(), 0.0001);
    }

    /**
     * Caso 3 (umbral acordado): zona media, position = 0,50 (price=105,
     * upper=112, lower=98), probado con AMBAS combinaciones de SMA
     * (tendencia confirmada y no confirmada) para demostrar que la rama
     * "default" (ni soporte ni sobrecompra) no cambio con la correccion
     * v2.22 (no dependia de la tendencia ni antes ni ahora): debe seguir
     * dando 3,0 puntos y verdict POSITIVE siempre.
     */
    public function testZonaMediaSiempreEsPositivaIndependientementeDeLaTendencia(): void
    {
        $stock = $this->stock(105.0);

        $casos = [
            'con tendencia confirmada' => $this->snapshot(105.0, 100.0),
            'sin tendencia confirmada' => $this->snapshot(98.0, 100.0),
        ];

        foreach ($casos as $descripcion => $snapshot) {
            $technical = $this->technicalResult($stock, $snapshot);
            $bollinger = $this->bollingerSignal($technical);

            self::assertSame(
                SignalVerdict::POSITIVE,
                $bollinger->getVerdict(),
                "Zona media ($descripcion) deberia seguir siendo POSITIVE."
            );
            self::assertSame(
                'El precio se mueve en la zona media de las bandas, sin señal extrema.',
                $bollinger->getMessage(),
                "Zona media ($descripcion) deberia mantener el mensaje de la rama default."
            );
        }
    }

    /**
     * Caso 4 (umbral acordado): SMA20/SMA50 ausentes (null) con precio
     * cerca de banda superior: price=110,6, upper=112, lower=98 =>
     * position = (110,6-98)/(112-98) = 0,90 >= 0,85.
     *
     * v2.22: la puntuacion de Bollinger en banda superior ya no lee
     * $sma20/$sma50 en absoluto, asi que su ausencia no puede provocar
     * ningun error ni cambiar el resultado: debe comportarse igual que
     * los casos 1 y 2, sin excepciones.
     */
    public function testSmaAusentesNoAlteraLaPuntuacionDeBollingerSinErrores(): void
    {
        $stock = $this->stock(110.6);
        $snapshot = $this->snapshot(null, null);

        $technical = $this->technicalResult($stock, $snapshot);
        $bollinger = $this->bollingerSignal($technical);

        self::assertSame(SignalVerdict::NEGATIVE, $bollinger->getVerdict());
        self::assertStringContainsString('el backtest interno no encuentra', $bollinger->getMessage());

        // - Precio vs SMA20 (sma20 null): +3,0 fijo
        // - Precio vs SMA50 (sma50 null): +3,0 fijo
        // - Cruce de medias (alguna SMA null): +2,0 fijo
        // - MACD (histograma null): +3,0 fijo
        // - Bollinger (banda superior): +1,5
        // - Volumen (ratio null): +2,0 fijo
        // Total = 3 + 3 + 2 + 3 + 1,5 + 2 = 14,5
        self::assertEqualsWithDelta(14.5, $technical->getScore(), 0.0001);
    }
}
