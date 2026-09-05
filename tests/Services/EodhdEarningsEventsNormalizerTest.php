<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\EodhdEarningsEventsNormalizer;

final class EodhdEarningsEventsNormalizerTest extends TestCase
{
    public function testExtraeOrdenaYConvierteElCalendarioRealistaDeEodhd(): void
    {
        $payload = json_encode([
            'type' => 'Earnings',
            'description' => 'desc',
            'symbols' => 'AAPL.US',
            'earnings' => [
                [
                    'code' => 'AAPL.US',
                    'report_date' => '2026-01-30',
                    'date' => '2025-12-31',
                    'before_after_market' => null,
                    'currency' => 'USD',
                    'actual' => 2.40,
                    'estimate' => 2.35,
                    'difference' => 0.05,
                    'percent' => 2.1277,
                ],
                [
                    'code' => 'AAPL.US',
                    'report_date' => '2026-05-01',
                    'date' => '2026-03-31',
                    'before_after_market' => 'AfterMarket',
                    'currency' => 'USD',
                    'actual' => 1.65,
                    'estimate' => 1.60,
                    'difference' => 0.05,
                    'percent' => 3.125,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $events = (new EodhdEarningsEventsNormalizer())->parse('aapl', $payload);

        self::assertCount(2, $events);
        self::assertSame('AAPL', $events[0]->ticker);
        self::assertSame('2026-01-30', $events[0]->reportDate->format('Y-m-d'));
        self::assertSame('2025-12-31', $events[0]->fiscalPeriodEnd->format('Y-m-d'));
        self::assertSame('2026-05-01', $events[1]->reportDate->format('Y-m-d'));
        self::assertSame('AfterMarket', $events[1]->beforeAfterMarket);
        self::assertNull($events[0]->beforeAfterMarket);
        self::assertSame('USD', $events[1]->currency);
    }

    public function testCalculaDiferenciaYSorpresaEnVezDeCopiarLasDeEodhd(): void
    {
        $payload = $this->payloadConUnaFila([
            'actual' => 0.02,
            'estimate' => 0.03,
        ]);

        $events = (new EodhdEarningsEventsNormalizer())->parse('AAPL', $payload);

        self::assertCount(1, $events);
        self::assertEqualsWithDelta(-0.01, $events[0]->epsDifference, 1e-9);
        self::assertEqualsWithDelta(-33.3333, $events[0]->epsSurprisePercent, 1e-3);
    }

    public function testUsaElValorAbsolutoDelEstimateNegativoComoBaseDelPorcentaje(): void
    {
        $payload = $this->payloadConUnaFila([
            'actual' => -0.6,
            'estimate' => -0.8,
        ]);

        $events = (new EodhdEarningsEventsNormalizer())->parse('APC', $payload);

        self::assertEqualsWithDelta(0.2, $events[0]->epsDifference, 1e-9);
        self::assertEqualsWithDelta(25.0, $events[0]->epsSurprisePercent, 1e-6);
    }

    public function testEstimateCeroDejaElPorcentajeNuloPeroNoLaDiferencia(): void
    {
        $payload = $this->payloadConUnaFila([
            'actual' => 5.09,
            'estimate' => 0,
        ]);

        $events = (new EodhdEarningsEventsNormalizer())->parse('IBN', $payload);

        self::assertEqualsWithDelta(5.09, $events[0]->epsDifference, 1e-9);
        self::assertNull($events[0]->epsSurprisePercent);
    }

    public function testActualAusenteDejaDiferenciaYPorcentajeNulos(): void
    {
        $payload = $this->payloadConUnaFila([
            'actual' => null,
            'estimate' => 1.98,
        ]);

        $events = (new EodhdEarningsEventsNormalizer())->parse('AAPL', $payload);

        self::assertCount(1, $events);
        self::assertNull($events[0]->epsActual);
        self::assertSame(1.98, $events[0]->epsEstimate);
        self::assertNull($events[0]->epsDifference);
        self::assertNull($events[0]->epsSurprisePercent);
    }

    public function testDescartaFilasSinFechaValidaDeReporteODePeriodo(): void
    {
        $payload = json_encode([
            'earnings' => [
                ['report_date' => '2026-01-30', 'date' => 'bad-date', 'actual' => 1.0, 'estimate' => 1.0],
                ['report_date' => 'sin-fecha', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
                ['report_date' => null, 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
                ['report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
            ],
        ], JSON_THROW_ON_ERROR);

        $events = (new EodhdEarningsEventsNormalizer())->parse('MSFT', $payload);

        self::assertCount(1, $events);
        self::assertSame('2025-12-31', $events[0]->fiscalPeriodEnd->format('Y-m-d'));
    }

    public function testTickerSinSeccionEarningsDevuelveListaVacia(): void
    {
        $payload = json_encode(['type' => 'Earnings', 'symbols' => 'ANR.US', 'earnings' => []], JSON_THROW_ON_ERROR);

        self::assertSame([], (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload));
    }

    public function testPayloadSinClaveEarningsDevuelveListaVacia(): void
    {
        $payload = json_encode(['type' => 'Earnings', 'symbols' => 'ANR.US'], JSON_THROW_ON_ERROR);

        self::assertSame([], (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload));
    }

    public function testConservaDosFilasConLaMismaFechaDeReporteYDistintoPeriodoFiscal(): void
    {
        $payload = json_encode([
            'earnings' => [
                [
                    'report_date' => '2026-05-28',
                    'date' => '2026-02-28',
                    'actual' => 4.93,
                    'estimate' => 4.54,
                ],
                [
                    'report_date' => '2026-05-28',
                    'date' => '2026-05-31',
                    'actual' => 4.93,
                    'estimate' => 4.98,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $events = (new EodhdEarningsEventsNormalizer())->parse('COST', $payload);

        self::assertCount(2, $events);
        self::assertSame('2026-02-28', $events[0]->fiscalPeriodEnd->format('Y-m-d'));
        self::assertSame('2026-05-31', $events[1]->fiscalPeriodEnd->format('Y-m-d'));
    }

    public function testDosFilasConElMismoPeriodoFiscalSeQuedanSoloConLaUltima(): void
    {
        $payload = json_encode([
            'earnings' => [
                ['report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => null, 'estimate' => 2.10],
                ['report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 2.15, 'estimate' => 2.10],
            ],
        ], JSON_THROW_ON_ERROR);

        $events = (new EodhdEarningsEventsNormalizer())->parse('AAPL', $payload);

        self::assertCount(1, $events);
        self::assertSame(2.15, $events[0]->epsActual);
    }

    public function testJsonInvalidoFallaDeFormaExplicita(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON');

        (new EodhdEarningsEventsNormalizer())->parse('AAPL', '{');
    }

    public function testTickerVacioFallaDeFormaExplicita(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EodhdEarningsEventsNormalizer())->parse('   ', '{"earnings":[]}');
    }

    /**
     * @param array<string,mixed> $row
     */
    private function payloadConUnaFila(array $row): string
    {
        $row += ['report_date' => '2026-01-30', 'date' => '2025-12-31', 'before_after_market' => null, 'currency' => 'USD'];

        return json_encode(['earnings' => [$row]], JSON_THROW_ON_ERROR);
    }
}
