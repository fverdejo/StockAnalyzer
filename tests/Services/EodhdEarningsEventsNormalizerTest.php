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

    /**
     * C5 (Astra 2026-09-21), caso 1: fecha imposible en TODAS las filas. Antes
     * salia `[]` (indistinguible del vacio valido) y el llamador borraba el
     * historico del ticker.
     */
    public function testUnaListaNoVaciaSinNingunaFechaValidaLanzaExcepcionEnVezDeDevolverVacio(): void
    {
        $payload = json_encode([
            'earnings' => [
                ['report_date' => '2026-02-31', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
                ['report_date' => '2026-01-30', 'date' => '2025-13-01', 'actual' => 1.0, 'estimate' => 1.0],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ninguna de las 2 filas');

        (new EodhdEarningsEventsNormalizer())->parse('MSFT', $payload);
    }

    /** C5, caso 2: mezcla de simbolo ajeno con fila mal formada (ninguna aceptable). */
    public function testMezclaDeSimboloAjenoYFilaMalFormadaLanzaExcepcion(): void
    {
        $payload = json_encode([
            'earnings' => [
                ['code' => 'AAPL.US', 'report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
                ['code' => 'MSFT.US', 'report_date' => 'sin-fecha', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
                'esto no es una fila',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('simbolo distinto de MSFT.US: 1, fecha invalida: 1, fila que no es objeto: 1');

        (new EodhdEarningsEventsNormalizer())->parse('MSFT', $payload);
    }

    /** Aceptacion parcial (declarada): con al menos una fila valida las demas se descartan Y SE CUENTAN. */
    public function testConAlMenosUnaFilaAceptadaLasRechazadasSeContabilizanPorMotivo(): void
    {
        $payload = json_encode([
            'earnings' => [
                ['code' => 'MSFT.US', 'report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
                ['code' => 'AAPL.US', 'report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
                ['report_date' => 'sin-fecha', 'date' => '2025-12-31'],
                7,
            ],
        ], JSON_THROW_ON_ERROR);

        $report = (new EodhdEarningsEventsNormalizer())->parseWithReport('MSFT', $payload);

        self::assertCount(1, $report['events']);
        self::assertSame(4, $report['rows_in_payload']);
        self::assertSame(3, $report['rejected_total']);
        self::assertSame(['fila_no_objeto' => 1, 'simbolo_ajeno' => 1, 'fecha_invalida' => 1], $report['rejected']);
    }

    public function testElUnicoVacioValidoSigueSiendoEarningsListaVacia(): void
    {
        $report = (new EodhdEarningsEventsNormalizer())->parseWithReport('MSFT', '{"earnings": []}');

        self::assertSame([], $report['events']);
        self::assertSame(0, $report['rows_in_payload']);
        self::assertSame(0, $report['rejected_total']);
    }

    public function testTickerSinSeccionEarningsDevuelveListaVacia(): void
    {
        $payload = json_encode(['type' => 'Earnings', 'symbols' => 'ANR.US', 'earnings' => []], JSON_THROW_ON_ERROR);

        self::assertSame([], (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload));
    }

    /**
     * Corregido el 2026-09-16 (hallazgo real de Astra, tarea A3): antes esto
     * devolvia `[]`, indistinguible de `{"earnings":[]}` (vacio valido).
     * `replaceForTicker()` habria borrado el historico real del ticker sin
     * ningun aviso. Ahora es un error explicito ANTES de tocar la base.
     */
    public function testPayloadSinClaveEarningsLanzaExcepcionEnVezDeVaciarElHistorico(): void
    {
        $payload = json_encode(['type' => 'Earnings', 'symbols' => 'ANR.US'], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload);
    }

    /**
     * Fixture literal de Astra (tarea A3): un cuerpo de error de EODHD, sin
     * clave "earnings", no puede confundirse con un vacio valido.
     */
    public function testCuerpoDeErrorSinClaveEarningsLanzaExcepcion(): void
    {
        $payload = json_encode(['error' => 'synthetic upstream failure'], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload);
    }

    /**
     * Fixture literal de Astra (tarea A3): "earnings" con el tipo
     * equivocado (cadena, no lista) tampoco es un vacio valido.
     */
    public function testEarningsDeTipoEquivocadoLanzaExcepcion(): void
    {
        $payload = json_encode(['earnings' => 'unavailable'], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload);
    }

    /**
     * Fixture literal de Astra (tarea A3): la seccion equivocada
     * ("trends" en vez de "earnings") tampoco puede leerse como vacio valido.
     */
    public function testSeccionEquivocadaLanzaExcepcion(): void
    {
        $payload = json_encode(['trends' => []], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload);
    }

    /**
     * Fixture literal de Astra (tarea B2): `{"earnings":{}}` decodificado
     * con `associative: true` produce el MISMO array PHP vacio que
     * `{"earnings":[]}` -- indistinguibles sin una segunda decodificacion
     * estricta. Un objeto no es una lista, aunque este vacio.
     */
    public function testEarningsComoObjetoVacioLanzaExcepcionAunqueSeaIndistinguibleTrasDecodificarAsociativo(): void
    {
        $payload = '{"earnings":{}}';

        $this->expectException(InvalidArgumentException::class);

        (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload);
    }

    /** Fixture literal de Astra (tarea B2): un objeto de error, no una lista. */
    public function testEarningsComoObjetoDeErrorLanzaExcepcion(): void
    {
        $payload = '{"earnings":{"error":"unavailable"}}';

        $this->expectException(InvalidArgumentException::class);

        (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload);
    }

    /**
     * Fixture literal de Astra (tarea B2): un objeto que contiene UNA fila
     * en vez de una LISTA de filas -- tampoco es la forma valida.
     */
    public function testEarningsComoUnaSolaFilaSinListaLanzaExcepcion(): void
    {
        $payload = json_encode([
            'earnings' => ['report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        (new EodhdEarningsEventsNormalizer())->parse('ANR', $payload);
    }

    /**
     * Hallazgo real de Astra (tarea B2): una fila con `code` distinto del
     * simbolo esperado no puede atribuirse en silencio al ticker pedido
     * -- una respuesta mal recortada o mezclada entre tickers debe
     * rechazarse, no aceptarse como si fuera del ticker correcto.
     */
    public function testUnaFilaConCodeDistintoDelSimboloEsperadoSeDescarta(): void
    {
        $payload = json_encode([
            'earnings' => [
                ['code' => 'MSFT.US', 'report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
                ['code' => 'AAPL.US', 'report_date' => '2026-05-01', 'date' => '2026-03-31', 'actual' => 1.65, 'estimate' => 1.60],
            ],
        ], JSON_THROW_ON_ERROR);

        $events = (new EodhdEarningsEventsNormalizer())->parse('AAPL', $payload);

        self::assertCount(1, $events, 'Solo la fila con code=AAPL.US pertenece al ticker pedido.');
        self::assertSame('2026-03-31', $events[0]->fiscalPeriodEnd->format('Y-m-d'));
    }

    /**
     * Si TODAS las filas tienen un `code` distinto del esperado (mezcla
     * total, no un caso aislado), no puede devolverse `[]` -- se leeria
     * como vacio valido. Debe fallar de forma explicita.
     */
    public function testSiTodasLasFilasTienenCodeDistintoSeLanzaExcepcion(): void
    {
        $payload = json_encode([
            'earnings' => [
                ['code' => 'MSFT.US', 'report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);

        (new EodhdEarningsEventsNormalizer())->parse('AAPL', $payload);
    }

    /**
     * El simbolo esperado puede pasarse explicitamente (tickers `_OLD` o
     * internacionales con sufijo remapeado, donde el simbolo real de
     * EODHD no coincide con el ticker interno).
     */
    public function testElSimboloEsperadoExplicitoSustituyeElFallbackPorDefecto(): void
    {
        $payload = json_encode([
            'earnings' => [
                ['code' => 'AZN.LSE', 'report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
            ],
        ], JSON_THROW_ON_ERROR);

        $events = (new EodhdEarningsEventsNormalizer())->parse('AZN.L', $payload, 'AZN.LSE');

        self::assertCount(1, $events);
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
