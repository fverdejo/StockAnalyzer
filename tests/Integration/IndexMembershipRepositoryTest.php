<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\DTO\IndexMembershipRecord;
use StockAnalyzer\Repository\IndexMembershipRepository;

/**
 * Repositorio normalizado de membresia de indice (`index_membership`,
 * migracion 022, roadmap.md "Segundo bloque" punto 2). `isMemberAt()` es la
 * consulta que usa `BacktestingService::runCrossSectional()` para el
 * universo point-in-time (punto 5): estos tests cubren la logica SQL en
 * MySQL de verdad, complementando `IndexMembershipRecordTest` (la misma
 * logica en PHP puro) y `BacktestingServicePointInTimeUniverseTest` (el
 * doble en memoria).
 */
final class IndexMembershipRepositoryTest extends IntegrationTestCase
{
    private IndexMembershipRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new IndexMembershipRepository($this->connection());
    }

    private function record(
        string $ticker,
        ?string $start,
        ?string $end,
        bool $isActiveNow = false,
        bool $isDelisted = false
    ): IndexMembershipRecord {
        return new IndexMembershipRecord(
            ticker: $ticker,
            indexCode: 'GSPC',
            companyName: 'Empresa de prueba',
            startDate: $start !== null ? new DateTimeImmutable($start) : null,
            endDate: $end !== null ? new DateTimeImmutable($end) : null,
            isActiveNow: $isActiveNow,
            isDelisted: $isDelisted
        );
    }

    public function testMiembroDentroDelTramoResuelveTrue(): void
    {
        $this->repository->storeAll([$this->record('AAL', '2015-03-23', '2024-09-23')]);

        self::assertTrue($this->repository->isMemberAt('AAL', 'GSPC', new DateTimeImmutable('2020-01-01')));
    }

    public function testFechaAntesDeEntrarResuelveFalse(): void
    {
        $this->repository->storeAll([$this->record('AAL', '2015-03-23', '2024-09-23')]);

        self::assertFalse($this->repository->isMemberAt('AAL', 'GSPC', new DateTimeImmutable('2014-01-01')));
    }

    public function testFechaDespuesDeSalirResuelveFalse(): void
    {
        $this->repository->storeAll([$this->record('AAL', '2015-03-23', '2024-09-23')]);

        self::assertFalse($this->repository->isMemberAt('AAL', 'GSPC', new DateTimeImmutable('2025-01-01')));
    }

    public function testTickerNuncaMiembroResuelveFalse(): void
    {
        $this->repository->storeAll([$this->record('AAL', '2015-03-23', '2024-09-23')]);

        self::assertFalse($this->repository->isMemberAt('ZZZZ', 'GSPC', new DateTimeImmutable('2020-01-01')));
    }

    public function testReInsertarElMismoTickerIndiceActualizaEnVezDeDuplicar(): void
    {
        $this->repository->storeAll([$this->record('AAPL', '1982-11-30', null, isActiveNow: true)]);
        $this->repository->storeAll([$this->record('AAPL', '1982-11-30', null, isActiveNow: true)]);

        self::assertSame(1, $this->repository->count('GSPC'));
    }

    public function testFormerMembersNotInExcluyeLosQueSiguenEnElUniverso(): void
    {
        $this->repository->storeAll([
            $this->record('AAL', '2015-03-23', '2024-09-23'),
            $this->record('ABMD', '2018-05-31', '2022-12-22', isDelisted: true),
            $this->record('MSFT', '1994-01-01', null, isActiveNow: true),
        ]);

        $former = $this->repository->formerMembersNotIn('GSPC', ['MSFT']);

        self::assertEqualsCanonicalizing(['AAL', 'ABMD'], $former);
    }
}
