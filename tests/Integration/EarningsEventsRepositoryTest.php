<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\DTO\CalendarEarningsEvent;
use StockAnalyzer\Repository\EarningsEventsRepository;

/**
 * `earnings_events` (migracion 026) y su procedencia: historial
 * append-only en `earnings_events_normalization_log` (migracion 030) y
 * ESTADO VIGENTE en `earnings_events_current_state` (migracion 031,
 * correccion del 2026-09-18 a un bug real senalado por Astra en
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B1: la version
 * anterior de `isNormalizedFromSource()` confundia "este hash se vio
 * alguna vez" con "este hash es el estado vigente ahora").
 */
final class EarningsEventsRepositoryTest extends IntegrationTestCase
{
    private const NORMALIZER_VERSION = 1;

    private EarningsEventsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EarningsEventsRepository($this->connection());
    }

    private function event(string $fiscalPeriodEnd, string $reportDate, float $actual = 1.0, float $estimate = 0.9): CalendarEarningsEvent
    {
        return new CalendarEarningsEvent(
            'AAPL',
            new DateTimeImmutable($fiscalPeriodEnd),
            new DateTimeImmutable($reportDate),
            null,
            $actual,
            $estimate,
            $actual - $estimate,
            ($actual - $estimate) / abs($estimate) * 100,
            'USD'
        );
    }

    private function replace(string $ticker, array $events, string $sourceHash, string $capturedAt): int
    {
        return $this->repository->replaceForTicker(
            $ticker,
            $events,
            $sourceHash,
            new DateTimeImmutable($capturedAt),
            self::NORMALIZER_VERSION
        );
    }

    private function isNormalized(string $ticker, string $sourceHash): bool
    {
        return $this->repository->isNormalizedFromSource($ticker, $sourceHash, self::NORMALIZER_VERSION);
    }

    public function testReplaceForTickerEscribeLasFilasYElTotal(): void
    {
        $written = $this->replace(
            'AAPL',
            [$this->event('2025-12-31', '2026-01-30'), $this->event('2026-03-31', '2026-05-01')],
            'hash-a',
            '2026-09-01'
        );

        self::assertSame(2, $written);
        self::assertSame(2, $this->repository->countTotal());
        self::assertSame(1, $this->repository->countDistinctTickers());
    }

    public function testReplaceForTickerSustituyeElHistoricoCompletoDelTicker(): void
    {
        $this->replace('AAPL', [$this->event('2025-12-31', '2026-01-30')], 'hash-a', '2026-09-01');
        $this->replace('AAPL', [$this->event('2026-03-31', '2026-05-01')], 'hash-b', '2026-09-02');

        self::assertSame(1, $this->repository->countTotal(), 'La segunda captura reemplaza, no acumula.');
    }

    /**
     * Hallazgo real de Astra (tarea A2): un ticker con CERO eventos (vacio
     * valido) no dejaba ninguna fila en `earnings_events`, asi que
     * `isNormalizedFromSource()` nunca podia confirmarlo como "ya
     * normalizado" -- se renormalizaba en cada ejecucion indefinidamente.
     */
    public function testUnVacioValidoQuedaMarcadoComoNormalizadoAunqueNoEscribaFilas(): void
    {
        $written = $this->replace('ANR', [], 'hash-vacio', '2026-09-01');

        self::assertSame(0, $written);
        self::assertSame(0, $this->repository->countTotal());
        self::assertTrue(
            $this->isNormalized('ANR', 'hash-vacio'),
            'El vacio valido debe confirmarse como normalizado, no reprocesarse en cada ejecucion.'
        );
    }

    public function testIsNormalizedFromSourceDistingueElHashExacto(): void
    {
        $this->replace('AAPL', [$this->event('2025-12-31', '2026-01-30')], 'hash-a', '2026-09-01');

        self::assertTrue($this->isNormalized('AAPL', 'hash-a'));
        self::assertFalse($this->isNormalized('AAPL', 'hash-b'));
        self::assertFalse($this->isNormalized('MSFT', 'hash-a'));
    }

    public function testIsNormalizedFromSourceDistingueLaVersionDelNormalizador(): void
    {
        $this->replace('AAPL', [$this->event('2025-12-31', '2026-01-30')], 'hash-a', '2026-09-01');

        self::assertTrue($this->repository->isNormalizedFromSource('AAPL', 'hash-a', self::NORMALIZER_VERSION));
        self::assertFalse(
            $this->repository->isNormalizedFromSource('AAPL', 'hash-a', self::NORMALIZER_VERSION + 1),
            'Una version distinta del normalizador debe forzar la renormalizacion aunque el hash coincida.'
        );
    }

    /**
     * Fixture LITERAL de Astra (tarea B1): A (EPS 1) -> B (EPS 2) -> A
     * recapturado. La version anterior confundia "hashA visto alguna vez"
     * con "estado vigente", asi que la tercera captura (A) se saltaba y
     * el contenido publicado seguia siendo B. Ahora debe confirmarse
     * "no normalizado" (el estado vigente es B, no A) para que el CLI
     * vuelva a normalizar y publique A.
     */
    public function testSecuenciaAbaElEstadoVigenteEsElUltimoNoElPrimeroVistoConEseHash(): void
    {
        $this->replace('AAPL', [$this->event('2025-12-31', '2026-01-30', 1.0)], 'hash-a', '2026-09-01');
        self::assertTrue($this->isNormalized('AAPL', 'hash-a'));

        $this->replace('AAPL', [$this->event('2025-12-31', '2026-01-30', 2.0)], 'hash-b', '2026-09-10');
        self::assertTrue($this->isNormalized('AAPL', 'hash-b'));
        self::assertFalse(
            $this->isNormalized('AAPL', 'hash-a'),
            'hash-a ya no es el estado vigente, aunque se viera antes -- debe reprocesarse si vuelve.'
        );

        // A recapturado (mismo hash que la primera captura).
        $this->replace('AAPL', [$this->event('2025-12-31', '2026-01-30', 1.0)], 'hash-a', '2026-09-20');
        self::assertTrue($this->isNormalized('AAPL', 'hash-a'), 'Tras la tercera captura, A vuelve a ser el estado vigente.');
        self::assertFalse($this->isNormalized('AAPL', 'hash-b'));
    }

    /** Mismo fixture que arriba, pero con capturas vacias en vez de EPS distintos. */
    public function testSecuenciaVacioBVacioElEstadoVigenteEsElUltimo(): void
    {
        $this->replace('ANR', [], 'hash-vacio', '2026-09-01');
        $this->replace('ANR', [$this->event('2025-12-31', '2026-01-30')], 'hash-b', '2026-09-10');
        self::assertFalse($this->isNormalized('ANR', 'hash-vacio'));

        $this->replace('ANR', [], 'hash-vacio', '2026-09-20');
        self::assertTrue($this->isNormalized('ANR', 'hash-vacio'));
        self::assertFalse($this->isNormalized('ANR', 'hash-b'));
    }

    /**
     * Renormalizar con el MISMO hash (p.ej. `--force`) no debe fallar por
     * ninguna clave unica -- el historial es append-only de verdad
     * (migracion 031 le quito la clave unica a
     * `earnings_events_normalization_log`) y el estado vigente se
     * actualiza sin error.
     */
    public function testRenormalizarConElMismoHashNoFalla(): void
    {
        $this->replace('AAPL', [$this->event('2025-12-31', '2026-01-30')], 'hash-a', '2026-09-01');
        $written = $this->replace('AAPL', [$this->event('2025-12-31', '2026-01-30')], 'hash-a', '2026-09-01');

        self::assertSame(1, $written);
        self::assertTrue($this->isNormalized('AAPL', 'hash-a'));
    }

    public function testReplaceForTickerGuardaElContextoDeLaObservacionEnElEstadoVigente(): void
    {
        $this->repository->replaceForTicker(
            'AZN.L',
            [],
            'hash-azn',
            new DateTimeImmutable('2026-09-01'),
            self::NORMALIZER_VERSION,
            'AZN.LSE',
            new DateTimeImmutable('1970-01-01'),
            new DateTimeImmutable('2028-01-01')
        );

        self::assertTrue($this->isNormalized('AZN.L', 'hash-azn'));
    }
}
