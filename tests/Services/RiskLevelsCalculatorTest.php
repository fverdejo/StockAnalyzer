<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Services\RiskLevelsCalculator;

/**
 * Cubre los guardarrayas de datos acordados con fiabilidad-datos-mercado
 * (ver versions.md): sin ATR14, con poco historico, o con ATR14
 * despreciable frente al precio, no debe sugerirse ningun nivel.
 */
final class RiskLevelsCalculatorTest extends TestCase
{
    private function calculator(): RiskLevelsCalculator
    {
        return new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0));
    }

    private function snapshot(?float $atr14, int $historyCount): TechnicalSnapshot
    {
        return new TechnicalSnapshot(
            null, // sma20
            null, // sma50
            null, // ema12
            null, // ema26
            null, // rsi14
            null, // macd
            null, // macdSignal
            null, // macdHistogram
            null, // bollingerUpper
            null, // bollingerMiddle
            null, // bollingerLower
            $atr14,
            null, // momentum30
            null, // volatility20
            null, // avgVolume20
            null, // lastVolume
            null, // high52w
            null, // low52w
            $historyCount
        );
    }

    public function testCalculaNivelesConDatosSuficientes(): void
    {
        $snapshot = $this->snapshot(4.0, 40);

        $riskLevels = $this->calculator()->compute($snapshot, 100.0);

        self::assertNotNull($riskLevels);
        self::assertEqualsWithDelta(90.0, $riskLevels->getStopLoss(), 0.0001);
        self::assertEqualsWithDelta(120.0, $riskLevels->getTarget(), 0.0001);
    }

    public function testSinAtr14DevuelveNull(): void
    {
        $snapshot = $this->snapshot(null, 200);

        self::assertNull($this->calculator()->compute($snapshot, 100.0));
    }

    public function testConHistoricoInsuficienteDevuelveNull(): void
    {
        // TechnicalSnapshot::getAtr14() ya seria null con historyCount <= 14
        // (ver TechnicalAnalyzer::atr()); aqui se prueba el umbral mas
        // exigente propio de RiskLevelsCalculator (39 < 40).
        $snapshot = $this->snapshot(4.0, 39);

        self::assertNull($this->calculator()->compute($snapshot, 100.0));
    }

    public function testConAtrDespreciableFrenteAlPrecioDevuelveNull(): void
    {
        // atr14/precio = 0.4/1000 = 0,04% < 0,05%
        $snapshot = $this->snapshot(0.4, 200);

        self::assertNull($this->calculator()->compute($snapshot, 1000.0));
    }

    public function testConAtrJustoPorEncimaDelUmbralCalculaNiveles(): void
    {
        // atr14/precio = 0.6/1000 = 0,06% >= 0,05%
        $snapshot = $this->snapshot(0.6, 200);

        self::assertNotNull($this->calculator()->compute($snapshot, 1000.0));
    }

    /**
     * Umbral exacto de MIN_ATR_TO_PRICE_RATIO (0,05%): la comprobacion en
     * RiskLevelsCalculator es `< MIN_ATR_TO_PRICE_RATIO`, no `<=`, asi que
     * un ratio exactamente igual al umbral SI debe calcular niveles (no es
     * "despreciable", es justo el limite aceptable). Verificado que
     * 0.5/1000 da en PHP el mismo double que el literal 0.0005 usado en la
     * comparacion (no hay imprecision de coma flotante que lo empuje por
     * debajo del umbral en este caso concreto).
     */
    public function testConRatioAtrPrecioExactamenteEnElUmbralCalculaNiveles(): void
    {
        $snapshot = $this->snapshot(0.5, 200);

        self::assertNotNull($this->calculator()->compute($snapshot, 1000.0));
    }

    /**
     * Caso limite: atr14=0.0 (no null, pero despreciable). 0/precio = 0,
     * siempre por debajo del umbral minimo, asi que nunca debe calcular
     * niveles con una serie totalmente plana.
     */
    public function testConAtrCeroDevuelveNull(): void
    {
        $snapshot = $this->snapshot(0.0, 200);

        self::assertNull($this->calculator()->compute($snapshot, 1000.0));
    }

    /**
     * Guardarraya no cubierto hasta ahora: precio <= 0. No tiene sentido
     * de negocio (un precio de mercado nunca es 0 o negativo), pero
     * RiskLevelsCalculator::compute() lo comprueba explicitamente antes de
     * delegar en RiskLevels::compute() (que si se le pasa precio=0 da un
     * stop-loss negativo sin ninguna proteccion, ver
     * RiskLevelsTest::testComputeConPrecioCeroPuedeDarStopLossNegativo).
     */
    public function testConPrecioCeroDevuelveNull(): void
    {
        $snapshot = $this->snapshot(4.0, 200);

        self::assertNull($this->calculator()->compute($snapshot, 0.0));
    }

    public function testConPrecioNegativoDevuelveNull(): void
    {
        $snapshot = $this->snapshot(4.0, 200);

        self::assertNull($this->calculator()->compute($snapshot, -50.0));
    }
}
