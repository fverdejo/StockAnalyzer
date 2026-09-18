<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\DTO\IndexMembershipRecord;
use StockAnalyzer\Repository\IndexMembershipRepository;
use StockAnalyzer\Repository\PreloadedIndexMembershipChecker;

/**
 * `PreloadedIndexMembershipChecker` (Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B7): resuelve
 * `isMemberAt()` en memoria tras `preload()`. Comprueba EQUIVALENCIA
 * contra `IndexMembershipRepository::isMemberAt()` (la consulta SQL real)
 * para un abanico de fechas, tal como exigio Astra explicitamente.
 */
final class PreloadedIndexMembershipCheckerTest extends IntegrationTestCase
{
    private IndexMembershipRepository $repository;
    private PreloadedIndexMembershipChecker $preloaded;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new IndexMembershipRepository($this->connection());
        $this->preloaded = new PreloadedIndexMembershipChecker($this->repository);
    }

    private function record(string $ticker, ?string $start, ?string $end): IndexMembershipRecord
    {
        return new IndexMembershipRecord(
            ticker: $ticker,
            indexCode: 'GSPC',
            companyName: 'Empresa de prueba',
            startDate: $start !== null ? new DateTimeImmutable($start) : null,
            endDate: $end !== null ? new DateTimeImmutable($end) : null,
            isActiveNow: $end === null,
            isDelisted: false
        );
    }

    public function testIsMemberAtEsEquivalenteALaConsultaSqlRealDentroYFueraDelTramo(): void
    {
        $this->repository->storeAll([$this->record('AAPL', '2020-01-01', '2024-06-30')]);
        $this->preloaded->preload('AAPL', 'GSPC');

        $fechas = ['2019-12-31', '2020-01-01', '2022-06-15', '2024-06-30', '2024-07-01'];

        foreach ($fechas as $fecha) {
            $date = new DateTimeImmutable($fecha);

            self::assertSame(
                $this->repository->isMemberAt('AAPL', 'GSPC', $date),
                $this->preloaded->isMemberAt('AAPL', 'GSPC', $date),
                "isMemberAt('AAPL', 'GSPC', $fecha) debe coincidir entre la version real y la precargada."
            );
        }
    }

    public function testEquivalenteConTramoAbiertoPorAmbosLados(): void
    {
        $this->repository->storeAll([$this->record('MSFT', null, null)]);
        $this->preloaded->preload('MSFT', 'GSPC');

        $date = new DateTimeImmutable('1990-01-01');

        self::assertTrue($this->preloaded->isMemberAt('MSFT', 'GSPC', $date));
        self::assertSame(
            $this->repository->isMemberAt('MSFT', 'GSPC', $date),
            $this->preloaded->isMemberAt('MSFT', 'GSPC', $date)
        );
    }

    public function testSinNingunaMembresiaDevuelveFalseIgualQueLaVersionReal(): void
    {
        $this->preloaded->preload('ZZZZ', 'GSPC');

        self::assertFalse($this->preloaded->isMemberAt('ZZZZ', 'GSPC', new DateTimeImmutable('2024-01-01')));
    }

    public function testUnaCombinacionNoPrecargadaCaeAlRepositorioRealSinRomper(): void
    {
        $this->repository->storeAll([$this->record('GOOGL', '2020-01-01', null)]);
        $this->preloaded->preload('MSFT', 'GSPC'); // se precarga OTRA combinacion

        self::assertSame(
            $this->repository->isMemberAt('GOOGL', 'GSPC', new DateTimeImmutable('2024-01-01')),
            $this->preloaded->isMemberAt('GOOGL', 'GSPC', new DateTimeImmutable('2024-01-01'))
        );
    }
}
