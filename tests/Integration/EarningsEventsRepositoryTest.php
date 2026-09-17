<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\DTO\CalendarEarningsEvent;
use StockAnalyzer\Repository\EarningsEventsRepository;

/**
 * `earnings_events` (migracion 026) y su procedencia
 * (`earnings_events_normalization_log`, migracion 030, correccion del
 * 2026-09-16 a un hueco real senalado por Astra en
 * `AUDITORIA_Y_TAREAS_EODHD_ASTRA_2026-09-16.md`, tarea A2).
 */
final class EarningsEventsRepositoryTest extends IntegrationTestCase
{
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

    public function testReplaceForTickerEscribeLasFilasYElTotal(): void
    {
        $written = $this->repository->replaceForTicker(
            'AAPL',
            [$this->event('2025-12-31', '2026-01-30'), $this->event('2026-03-31', '2026-05-01')],
            'hash-a',
            new DateTimeImmutable('2026-09-01')
        );

        self::assertSame(2, $written);
        self::assertSame(2, $this->repository->countTotal());
        self::assertSame(1, $this->repository->countDistinctTickers());
    }

    public function testReplaceForTickerSustituyeElHistoricoCompletoDelTicker(): void
    {
        $this->repository->replaceForTicker('AAPL', [$this->event('2025-12-31', '2026-01-30')], 'hash-a', new DateTimeImmutable('2026-09-01'));
        $this->repository->replaceForTicker('AAPL', [$this->event('2026-03-31', '2026-05-01')], 'hash-b', new DateTimeImmutable('2026-09-02'));

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
        $written = $this->repository->replaceForTicker('ANR', [], 'hash-vacio', new DateTimeImmutable('2026-09-01'));

        self::assertSame(0, $written);
        self::assertSame(0, $this->repository->countTotal());
        self::assertTrue(
            $this->repository->isNormalizedFromSource('ANR', 'hash-vacio'),
            'El vacio valido debe confirmarse como normalizado, no reprocesarse en cada ejecucion.'
        );
    }

    public function testIsNormalizedFromSourceDistingueElHashExacto(): void
    {
        $this->repository->replaceForTicker('AAPL', [$this->event('2025-12-31', '2026-01-30')], 'hash-a', new DateTimeImmutable('2026-09-01'));

        self::assertTrue($this->repository->isNormalizedFromSource('AAPL', 'hash-a'));
        self::assertFalse($this->repository->isNormalizedFromSource('AAPL', 'hash-b'));
        self::assertFalse($this->repository->isNormalizedFromSource('MSFT', 'hash-a'));
    }

    /**
     * Renormalizar con el MISMO hash (p.ej. `--force`) no debe fallar por
     * la clave unica de `earnings_events_normalization_log` -- actualiza el
     * registro existente en vez de duplicarlo o lanzar.
     */
    public function testRenormalizarConElMismoHashNoFalla(): void
    {
        $this->repository->replaceForTicker('AAPL', [$this->event('2025-12-31', '2026-01-30')], 'hash-a', new DateTimeImmutable('2026-09-01'));
        $written = $this->repository->replaceForTicker('AAPL', [$this->event('2025-12-31', '2026-01-30')], 'hash-a', new DateTimeImmutable('2026-09-01'));

        self::assertSame(1, $written);
        self::assertTrue($this->repository->isNormalizedFromSource('AAPL', 'hash-a'));
    }
}
