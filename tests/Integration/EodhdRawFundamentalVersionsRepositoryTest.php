<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Enums\EodhdFundamentalVersionStoreOutcome;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;

/**
 * Historial versionado del archivo crudo de EODHD (`eodhd_raw_fundamental_versions`,
 * migracion 025, Bloque A del plan de Codex del 2026-09-04). El UNIQUE KEY
 * `(ticker, api_version, section, payload_hash)` que hace posible la
 * deduplicacion no se puede probar sin MySQL de verdad (mismo motivo que
 * `EodhdRawFundamentalsRepositoryTest`).
 *
 * Los tres tests `testSecuenciaA*` (final del fichero) son el criterio de
 * aceptacion LITERAL que pidio Codex el 2026-09-05/06 tras encontrar el bug
 * de `INSERT IGNORE` en `d608747` (ver `versions.md`): A->A conserva un
 * unico blob pero deja dos observaciones, A->B conserva dos blobs y dos
 * observaciones, y A->B->A deja tres observaciones (solo dos blobs
 * distintos) con `latestFor()` devolviendo A -- el ultimo ESTADO
 * observado, no el ultimo BLOB insertado.
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
        self::assertSame('2026-09-01 10:00:00', $payloads[0]['observed_at_utc']);
        self::assertSame('2026-09-20 10:00:00', $payloads[1]['observed_at_utc']);
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

    /**
     * `store()` devuelve un resultado explicito (correccion del 2026-09-06
     * al bug de `INSERT IGNORE` senalado por Codex) en vez de `void`: un
     * llamador puede ahora saber si la captura recien completada aporto un
     * blob nuevo o repitio contenido ya archivado, sin adivinarlo con un
     * `count()` antes/despues.
     */
    public function testStoreDevuelveSiElBlobEsNuevoOYaExistia(): void
    {
        $primerResultado = $this->repository->store('AAPL', '{"v":1}', 'legacy', 'full', new DateTimeImmutable('2026-09-01'));

        self::assertTrue($primerResultado->isNewVersion());
        self::assertSame(EodhdFundamentalVersionStoreOutcome::NEW_VERSION, $primerResultado->outcome);

        $segundoResultado = $this->repository->store('AAPL', '{"v":1}', 'legacy', 'full', new DateTimeImmutable('2026-09-02'));

        self::assertFalse($segundoResultado->isNewVersion());
        self::assertSame(EodhdFundamentalVersionStoreOutcome::DUPLICATE_CONTENT, $segundoResultado->outcome);
        self::assertSame($primerResultado->versionId, $segundoResultado->versionId, 'mismo contenido, mismo blob');
        self::assertNotSame(
            $primerResultado->observationId,
            $segundoResultado->observationId,
            'cada captura real deja su propia observacion, aunque comparta blob'
        );
    }

    /**
     * Criterio de aceptacion A->A pedido por Codex (`versions.md`,
     * 2026-09-06): la MISMA captura repetida dos veces conserva UN unico
     * blob (deduplicado por hash), pero deja DOS observaciones -- antes del
     * fix, `INSERT IGNORE` hacia que la segunda captura desapareciera sin
     * dejar rastro, indistinguible de "nunca se ha vuelto a capturar".
     */
    public function testSecuenciaAaConservaUnBlobPeroDejaDosObservaciones(): void
    {
        $primeraCaptura = $this->repository->store(
            'AAPL',
            '{"v":"A"}',
            'calendar',
            'earnings',
            new DateTimeImmutable('2026-09-01 10:00:00')
        );
        $segundaCaptura = $this->repository->store(
            'AAPL',
            '{"v":"A"}',
            'calendar',
            'earnings',
            new DateTimeImmutable('2026-09-20 10:00:00')
        );

        self::assertTrue($primeraCaptura->isNewVersion());
        self::assertFalse($segundaCaptura->isNewVersion());
        self::assertSame($primeraCaptura->versionId, $segundaCaptura->versionId);
        self::assertNotSame($primeraCaptura->observationId, $segundaCaptura->observationId);

        self::assertSame(1, $this->repository->count(), 'un unico blob, deduplicado por hash');

        $observaciones = $this->repository->allPayloadsFor('AAPL', 'calendar', 'earnings');
        self::assertCount(2, $observaciones, 'DOS observaciones reales, aunque compartan el mismo blob');
        self::assertSame('{"v":"A"}', $observaciones[0]['payload']);
        self::assertSame('{"v":"A"}', $observaciones[1]['payload']);
        self::assertSame($observaciones[0]['payload_hash'], $observaciones[1]['payload_hash']);
        self::assertSame('2026-09-01 10:00:00', $observaciones[0]['observed_at_utc']);
        self::assertSame('2026-09-20 10:00:00', $observaciones[1]['observed_at_utc']);

        self::assertSame('{"v":"A"}', $this->repository->latestFor('AAPL', 'calendar', 'earnings'));
    }

    /**
     * Criterio de aceptacion A->B pedido por Codex: un contenido distinto
     * deja dos blobs y dos observaciones, y `latestFor()` devuelve el mas
     * reciente (B). Ya cubierto en espiritu por
     * `testUnContenidoDistintoSeConservaComoVersionNueva`, pero aqui se
     * verifica ademas usando el vocabulario de observaciones que introduce
     * esta correccion.
     */
    public function testSecuenciaAbConservaDosBlobsYDosObservaciones(): void
    {
        $capturaA = $this->repository->store(
            'AAPL',
            '{"v":"A"}',
            'calendar',
            'earnings',
            new DateTimeImmutable('2026-09-01 10:00:00')
        );
        $capturaB = $this->repository->store(
            'AAPL',
            '{"v":"B"}',
            'calendar',
            'earnings',
            new DateTimeImmutable('2026-09-20 10:00:00')
        );

        self::assertTrue($capturaA->isNewVersion());
        self::assertTrue($capturaB->isNewVersion());
        self::assertNotSame($capturaA->versionId, $capturaB->versionId);

        self::assertSame(2, $this->repository->count());
        self::assertCount(2, $this->repository->allPayloadsFor('AAPL', 'calendar', 'earnings'));
        self::assertSame('{"v":"B"}', $this->repository->latestFor('AAPL', 'calendar', 'earnings'));
    }

    /**
     * Criterio de aceptacion A->B->A pedido por Codex, el caso que de
     * verdad exponia el bug: el valor cambia de A a B y luego VUELVE a A.
     * Solo hay dos blobs distintos (A y B), pero tres observaciones deben
     * quedar registradas -- y `latestFor()` debe devolver A, el ULTIMO
     * ESTADO observado, no B solo porque su blob se inserto despues. Antes
     * del fix, la tercera captura (A) colisionaba por hash con la primera
     * fila ya existente, `INSERT IGNORE` la descartaba, y `latestFor()`
     * (que resolvia por la fila de blob mas reciente) devolvia B.
     */
    public function testSecuenciaAbaDejaTresObservacionesYLatestForDevuelveElUltimoEstadoReal(): void
    {
        $primeraA = $this->repository->store(
            'AAPL',
            '{"v":"A"}',
            'calendar',
            'earnings',
            new DateTimeImmutable('2026-09-01 10:00:00')
        );
        $capturaB = $this->repository->store(
            'AAPL',
            '{"v":"B"}',
            'calendar',
            'earnings',
            new DateTimeImmutable('2026-09-10 10:00:00')
        );
        $segundaA = $this->repository->store(
            'AAPL',
            '{"v":"A"}',
            'calendar',
            'earnings',
            new DateTimeImmutable('2026-09-20 10:00:00')
        );

        // Solo DOS blobs distintos (A y B), pese a TRES capturas: la
        // tercera reutiliza el blob de la primera.
        self::assertTrue($primeraA->isNewVersion());
        self::assertTrue($capturaB->isNewVersion());
        self::assertFalse($segundaA->isNewVersion());
        self::assertSame($primeraA->versionId, $segundaA->versionId);
        self::assertNotSame($primeraA->versionId, $capturaB->versionId);
        self::assertSame(2, $this->repository->count(), 'solo dos blobs distintos: A y B');

        // El ultimo ESTADO observado es A, no B.
        self::assertSame(
            '{"v":"A"}',
            $this->repository->latestFor('AAPL', 'calendar', 'earnings'),
            'latestFor() debe devolver el ultimo estado observado (A), no el ultimo blob nuevo insertado (B)'
        );

        // Las TRES observaciones deben poder reconstruirse en orden -- es
        // lo que necesita E3 para estudiar como cambia una estimacion en el
        // tiempo, incluidos los cambios que se revierten despues.
        $observaciones = $this->repository->allPayloadsFor('AAPL', 'calendar', 'earnings');
        self::assertCount(3, $observaciones);
        self::assertSame('{"v":"A"}', $observaciones[0]['payload']);
        self::assertSame('{"v":"B"}', $observaciones[1]['payload']);
        self::assertSame('{"v":"A"}', $observaciones[2]['payload']);
        self::assertSame($observaciones[0]['payload_hash'], $observaciones[2]['payload_hash']);
        self::assertNotSame($observaciones[0]['payload_hash'], $observaciones[1]['payload_hash']);
    }
}
