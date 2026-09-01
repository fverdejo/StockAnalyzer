<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;

/**
 * Archivo crudo de EODHD (`eodhd_raw_fundamentals`, migracion 019, ver
 * roadmap.md "Prioridad cero" punto 2). El UPSERT por ticker no se puede
 * probar sin MySQL de verdad (mismo motivo que FundamentalsHistoryRepository:
 * el UNIQUE KEY y el ON DUPLICATE KEY UPDATE son comportamiento de la base,
 * no de PHP).
 */
final class EodhdRawFundamentalsRepositoryTest extends IntegrationTestCase
{
    private EodhdRawFundamentalsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EodhdRawFundamentalsRepository($this->connection());
    }

    public function testUnTickerNuncaArchivadoNoExiste(): void
    {
        self::assertFalse($this->repository->has('AAPL'));
        self::assertNull($this->repository->find('AAPL'));
    }

    public function testStoreYFindRedondeanElMismoPayload(): void
    {
        $payload = '{"Financials":{"Income_Statement":{"quarterly":[]}}}';

        $this->repository->store('AAPL', $payload, new DateTimeImmutable('2026-09-01 10:00:00'));

        self::assertTrue($this->repository->has('AAPL'));
        self::assertSame($payload, $this->repository->find('AAPL'));
    }

    /**
     * El ticker se normaliza a mayusculas, igual que en
     * FundamentalsHistoryRepository: 'aapl' y 'AAPL' son el mismo archivo.
     */
    public function testElTickerSeNormalizaAMayusculas(): void
    {
        $this->repository->store('aapl', '{}', new DateTimeImmutable());

        self::assertTrue($this->repository->has('AAPL'));
    }

    /**
     * Re-archivar el mismo ticker sobrescribe en vez de duplicar (UNIQUE
     * KEY sobre ticker): es lo que hace posible re-archivar sin red un
     * ticker corrupto sin dejar basura.
     */
    public function testReArchivarElMismoTickerSobrescribeYNoDuplica(): void
    {
        $this->repository->store('AAPL', '{"v":1}', new DateTimeImmutable('2026-09-01'));
        $this->repository->store('AAPL', '{"v":2}', new DateTimeImmutable('2026-09-02'));

        self::assertSame('{"v":2}', $this->repository->find('AAPL'));
        self::assertSame(1, $this->repository->count());
    }

    public function testCountYArchivedTickersReflejanLoGuardado(): void
    {
        $this->repository->store('AAPL', '{}', new DateTimeImmutable());
        $this->repository->store('MSFT', '{}', new DateTimeImmutable());

        self::assertSame(2, $this->repository->count());
        self::assertEqualsCanonicalizing(['AAPL', 'MSFT'], $this->repository->archivedTickers());
    }
}
