<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\MeasurementDataFingerprint;

/**
 * `MeasurementDataFingerprint` (C4, `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`):
 * la parte PURA de la huella de datos, sin base de datos.
 */
final class MeasurementDataFingerprintTest extends TestCase
{
    private function quote(string $date, float $close): HistoricalQuote
    {
        return new HistoricalQuote(new DateTimeImmutable($date), $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
    }

    public function testDosLlamadasConLasMismasCotizacionesDanLaMismaHuella(): void
    {
        $quotes = [$this->quote('2024-01-01', 100.0), $this->quote('2024-01-02', 101.0)];
        $service = new MeasurementDataFingerprint();

        self::assertSame(
            $service->priceFingerprint($quotes, new DateTimeImmutable('2024-01-02')),
            $service->priceFingerprint($quotes, new DateTimeImmutable('2024-01-02'))
        );
    }

    public function testUnaCotizacionDistintaCambiaLaHuella(): void
    {
        $service = new MeasurementDataFingerprint();
        $a = [$this->quote('2024-01-01', 100.0)];
        $b = [$this->quote('2024-01-01', 100.01)];

        self::assertNotSame(
            $service->priceFingerprint($a, new DateTimeImmutable('2024-01-01')),
            $service->priceFingerprint($b, new DateTimeImmutable('2024-01-01'))
        );
    }

    /**
     * Un dato archivado DESPUES del corte de la medicion nunca se consume
     * (ver `BacktestingService::historyUpTo()`): no debe cambiar la huella.
     */
    public function testUnaCotizacionPosteriorAlCorteNoCambiaLaHuella(): void
    {
        $service = new MeasurementDataFingerprint();
        $base = [$this->quote('2024-01-01', 100.0)];
        $conFutura = [$this->quote('2024-01-01', 100.0), $this->quote('2024-06-01', 999.0)];
        $asOf = new DateTimeImmutable('2024-01-01');

        self::assertSame(
            $service->priceFingerprint($base, $asOf),
            $service->priceFingerprint($conFutura, $asOf)
        );
    }

    public function testLaHuellaNoDependeDelOrdenDeEntrada(): void
    {
        $service = new MeasurementDataFingerprint();
        $ordered = [$this->quote('2024-01-01', 100.0), $this->quote('2024-01-02', 101.0)];
        $reversed = array_reverse($ordered);
        $asOf = new DateTimeImmutable('2024-01-02');

        self::assertSame($service->priceFingerprint($ordered, $asOf), $service->priceFingerprint($reversed, $asOf));
    }

    public function testCombineCambiaSiCualquieraDeLasTresFuentesCambia(): void
    {
        $service = new MeasurementDataFingerprint();
        $base = $service->combine('price-a', 'fund-a', 'member-a');

        self::assertNotSame($base, $service->combine('price-b', 'fund-a', 'member-a'));
        self::assertNotSame($base, $service->combine('price-a', 'fund-b', 'member-a'));
        self::assertNotSame($base, $service->combine('price-a', 'fund-a', 'member-b'));
        self::assertSame($base, $service->combine('price-a', 'fund-a', 'member-a'));
    }

    public function testCombineTrataAusenciaDeFundamentalesOPertenenciaDeFormaEstable(): void
    {
        $service = new MeasurementDataFingerprint();

        self::assertSame(
            $service->combine('price-a', null, null),
            $service->combine('price-a', null, null)
        );
        // Ausente (null) y presente-pero-distinto siguen dando huellas distintas:
        // la normalizacion de ausencia solo colisiona con la cadena vacia literal,
        // que nunca es un hash sha256 real (siempre 64 caracteres hexadecimales).
        self::assertNotSame(
            $service->combine('price-a', null, null),
            $service->combine('price-a', 'fund-c', null)
        );
    }
}
