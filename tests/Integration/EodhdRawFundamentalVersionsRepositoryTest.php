<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;

/**
 * Historial versionado del archivo crudo de EODHD (`eodhd_raw_fundamental_versions`,
 * migracion 025, Bloque A del plan de Codex del 2026-09-04). El UNIQUE KEY
 * `(ticker, api_version, section, payload_hash)` que hace posible la
 * deduplicacion no se puede probar sin MySQL de verdad (mismo motivo que
 * `EodhdRawFundamentalsRepositoryTest`).
 */
final class EodhdRawFundamentalVersionsRepositoryTest extends IntegrationTestCase
{
    private EodhdRawFundamentalVersionsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EodhdRawFundamentalVersionsRepository($this->connection());
    }

    public function testUnTickerSinVersionesDevuelveVacio(): void
    {
        self::assertNull($this->repository->latestFor('AAPL', 'legacy', 'full'));
        self::assertSame([], $this->repository->allVersionsFor('AAPL'));
        self::assertSame(0, $this->repository->count());
        self::assertSame(0, $this->repository->countDistinctTickers());
    }

    public function testStoreYLatestForReproducenElMismoPayload(): void
    {
        $payload = '{"Financials":{"Income_Statement":{"quarterly":[]}}}';

        $this->repository->store('AAPL', $payload, 'legacy', 'full', new DateTimeImmutable('2026-09-01 10:00:00'));

        self::assertSame($payload, $this->repository->latestFor('AAPL', 'legacy', 'full'));
    }

    /**
     * El ciclo comprimir/descomprimir tiene que reproducir EXACTAMENTE el
     * JSON original, incluso para un payload grande y con caracteres no
     * ASCII (acentos, simbolos de moneda) que podrian revelar un problema
     * de codificacion que un payload de prueba minimo no revelaria.
     */
    public function testElCicloComprimirDescomprimirReproduceUnPayloadGrandeExacto(): void
    {
        $rows = [];

        for ($i = 0; $i < 20000; ++$i) {
            $rows[] = [
                'date' => sprintf('20%02d-%02d-01', $i % 100, ($i % 12) + 1),
                'totalRevenue' => $i * 1234.5678,
                'netIncome' => -$i * 12.34,
                'currency' => 'EUR/€/日本語',
                'nota' => 'información con acentos y ñ, número ' . $i,
            ];
        }

        $payload = json_encode(['Financials' => ['Income_Statement' => ['quarterly' => $rows]]], JSON_THROW_ON_ERROR);
        self::assertGreaterThan(1_000_000, strlen($payload), 'el payload de prueba debe ser grande de verdad');

        $this->repository->store('AAPL', $payload, 'legacy', 'full', new DateTimeImmutable('2026-09-01'));

        self::assertSame($payload, $this->repository->latestFor('AAPL', 'legacy', 'full'));
    }

    public function testElTickerSeNormalizaAMayusculas(): void
    {
        $this->repository->store('aapl', '{}', 'legacy', 'full', new DateTimeImmutable());

        self::assertSame('{}', $this->repository->latestFor('AAPL', 'legacy', 'full'));
    }

    /**
     * Dos capturas con contenido IDENTICO (mismo hash) para el mismo
     * `(ticker, api_version, section)` no se duplican: es lo que hace
     * posible re-archivar sin gastar espacio cuando EODHD no ha cambiado
     * nada.
     */
    public function testDosCapturasConElMismoContenidoNoSeDuplican(): void
    {
        $this->repository->store('AAPL', '{"v":1}', 'legacy', 'full', new DateTimeImmutable('2026-09-01'));
        $this->repository->store('AAPL', '{"v":1}', 'legacy', 'full', new DateTimeImmutable('2026-09-02'));

        self::assertSame(1, $this->repository->count());
    }

    /**
     * Un contenido DISTINTO para el mismo ticker/api_version/section si se
     * conserva como una version nueva -- es la razon de ser de esta tabla,
     * a diferencia de `eodhd_raw_fundamentals`, que la sobrescribiria.
     */
    public function testUnContenidoDistintoSeConservaComoVersionNueva(): void
    {
        $this->repository->store('AAPL', '{"v":1}', 'legacy', 'full', new DateTimeImmutable('2026-09-01 08:00:00'));
        $this->repository->store('AAPL', '{"v":2}', 'legacy', 'full', new DateTimeImmutable('2026-09-02 08:00:00'));

        self::assertSame(2, $this->repository->count());
        self::assertSame('{"v":2}', $this->repository->latestFor('AAPL', 'legacy', 'full'));

        $versions = $this->repository->allVersionsFor('AAPL');
        self::assertCount(2, $versions);
        // De mas reciente a mas antigua.
        self::assertSame('2026-09-02 08:00:00', $versions[0]['fetched_at']);
        self::assertSame('2026-09-01 08:00:00', $versions[1]['fetched_at']);
    }

    /**
     * api_version/section distintos para el mismo ticker y el mismo
     * contenido NO cuentan como duplicado: la clave unica incluye ambos
     * campos (prepara el hueco para Fundamentals v1.1 y secciones
     * parciales del Bloque B del plan, aunque hoy solo se usen
     * 'legacy'/'full').
     */
    public function testApiVersionYSeccionDistintasNoCuentanComoDuplicado(): void
    {
        $this->repository->store('AAPL', '{"v":1}', 'legacy', 'full', new DateTimeImmutable('2026-09-01'));
        $this->repository->store('AAPL', '{"v":1}', 'v1.1', 'full', new DateTimeImmutable('2026-09-01'));
        $this->repository->store('AAPL', '{"v":1}', 'legacy', 'Financials', new DateTimeImmutable('2026-09-01'));

        self::assertSame(3, $this->repository->count());
        self::assertSame(1, $this->repository->countDistinctTickers());
    }

    public function testAllVersionsForNoIncluyeElPayloadCompleto(): void
    {
        $this->repository->store(
            'AAPL',
            '{"v":1}',
            'legacy',
            'full',
            new DateTimeImmutable('2026-09-01 12:00:00'),
            200,
            'AAPL.US'
        );

        $versions = $this->repository->allVersionsFor('AAPL');

        self::assertCount(1, $versions);
        self::assertArrayNotHasKey('payload_compressed', $versions[0]);
        self::assertSame('AAPL', $versions[0]['ticker']);
        self::assertSame('legacy', $versions[0]['api_version']);
        self::assertSame('full', $versions[0]['section']);
        self::assertSame(200, $versions[0]['http_status']);
        self::assertSame('AAPL.US', $versions[0]['source_symbol']);
        self::assertNull($versions[0]['parse_status']);
        self::assertNull($versions[0]['error_message']);
    }

    /**
     * hasVersion() (Bloque B1 del plan de Codex del 2026-09-04) es lo que
     * hace reanudable `bin/archive-eodhd-fundamentals-v11.php`: distingue
     * por api_version/section igual que el resto del repositorio, y no se
     * confunde por un ticker con version en OTRO api_version/section.
     */
    public function testHasVersionDistingueApiVersionYSeccion(): void
    {
        self::assertFalse($this->repository->hasVersion('AAPL', 'v1.1', 'full'));

        $this->repository->store('AAPL', '{"v":1}', 'legacy', 'full', new DateTimeImmutable('2026-09-01'));

        self::assertTrue($this->repository->hasVersion('AAPL', 'legacy', 'full'));
        self::assertFalse($this->repository->hasVersion('AAPL', 'v1.1', 'full'));
        self::assertFalse($this->repository->hasVersion('MSFT', 'legacy', 'full'));

        $this->repository->store('AAPL', '{"v":1}', 'v1.1', 'full', new DateTimeImmutable('2026-09-01'));

        self::assertTrue($this->repository->hasVersion('AAPL', 'v1.1', 'full'));
    }

    public function testHasVersionSeNormalizaAMayusculas(): void
    {
        $this->repository->store('aapl', '{}', 'v1.1', 'full', new DateTimeImmutable());

        self::assertTrue($this->repository->hasVersion('AAPL', 'v1.1', 'full'));
        self::assertTrue($this->repository->hasVersion('aapl', 'v1.1', 'full'));
    }

    public function testCountYCountDistinctTickersReflejanLoGuardado(): void
    {
        $this->repository->store('AAPL', '{"v":1}', 'legacy', 'full', new DateTimeImmutable('2026-09-01'));
        $this->repository->store('AAPL', '{"v":2}', 'legacy', 'full', new DateTimeImmutable('2026-09-02'));
        $this->repository->store('MSFT', '{"v":1}', 'legacy', 'full', new DateTimeImmutable('2026-09-01'));

        self::assertSame(3, $this->repository->count());
        self::assertSame(2, $this->repository->countDistinctTickers());
    }

    /**
     * `allPayloadsFor()` (2026-09-05, preparacion de la validacion de E2):
     * a diferencia de `latestFor()`, expone TODAS las versiones para poder
     * comparar una captura antigua contra una mas reciente.
     */
    public function testAllPayloadsForDevuelveVacioSinVersiones(): void
    {
        self::assertSame([], $this->repository->allPayloadsFor('AAPL', 'calendar', 'earnings'));
    }

    public function testAllPayloadsForDevuelveTodasLasVersionesDeMasAntiguaAMasReciente(): void
    {
        $this->repository->store('AAPL', '{"v":1}', 'calendar', 'earnings', new DateTimeImmutable('2026-09-01 10:00:00'));
        $this->repository->store('AAPL', '{"v":2}', 'calendar', 'earnings', new DateTimeImmutable('2026-09-20 10:00:00'));

        $payloads = $this->repository->allPayloadsFor('AAPL', 'calendar', 'earnings');

        self::assertCount(2, $payloads);
        self::assertSame('{"v":1}', $payloads[0]['payload']);
        self::assertSame('{"v":2}', $payloads[1]['payload']);
        self::assertSame('2026-09-01 10:00:00', $payloads[0]['fetched_at']);
        self::assertSame('2026-09-20 10:00:00', $payloads[1]['fetched_at']);
    }

    public function testAllPayloadsForNoMezclaOtroTickerNiOtraSeccion(): void
    {
        $this->repository->store('AAPL', '{"earnings":1}', 'calendar', 'earnings', new DateTimeImmutable('2026-09-01'));
        $this->repository->store('AAPL', '{"trends":1}', 'calendar', 'trends', new DateTimeImmutable('2026-09-01'));
        $this->repository->store('MSFT', '{"earnings":1}', 'calendar', 'earnings', new DateTimeImmutable('2026-09-01'));

        $payloads = $this->repository->allPayloadsFor('AAPL', 'calendar', 'earnings');

        self::assertCount(1, $payloads);
        self::assertSame('{"earnings":1}', $payloads[0]['payload']);
    }
}
