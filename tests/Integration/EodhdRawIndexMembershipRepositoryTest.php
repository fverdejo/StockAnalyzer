<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Repository\EodhdRawIndexMembershipRepository;

/**
 * Archivo crudo de composicion de indice (`eodhd_raw_index_membership`,
 * migracion 021, roadmap.md "Segundo bloque" punto 1). Mismo patron UPSERT
 * que `EodhdRawFundamentalsRepositoryTest`, con `index_code` en vez de
 * `ticker`.
 */
final class EodhdRawIndexMembershipRepositoryTest extends IntegrationTestCase
{
    private EodhdRawIndexMembershipRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EodhdRawIndexMembershipRepository($this->connection());
    }

    public function testUnIndiceNuncaArchivadoNoExiste(): void
    {
        self::assertFalse($this->repository->has('GSPC'));
        self::assertNull($this->repository->find('GSPC'));
    }

    public function testStoreYFindRedondeanElMismoPayload(): void
    {
        $payload = '{"General":{"Code":"GSPC"}}';

        $this->repository->store('GSPC', $payload, true, new DateTimeImmutable('2026-09-02 10:00:00'));

        self::assertTrue($this->repository->has('GSPC'));
        self::assertSame($payload, $this->repository->find('GSPC'));
    }

    public function testReArchivarElMismoIndiceSobrescribeYNoDuplica(): void
    {
        $this->repository->store('GSPC', '{"v":1}', true, new DateTimeImmutable('2026-09-01'));
        $this->repository->store('GSPC', '{"v":2}', true, new DateTimeImmutable('2026-09-02'));

        self::assertSame('{"v":2}', $this->repository->find('GSPC'));
        self::assertSame(1, $this->repository->count());
    }

    public function testCountReflejaVariosIndices(): void
    {
        $this->repository->store('GSPC', '{}', true, new DateTimeImmutable());
        $this->repository->store('MID', '{}', false, new DateTimeImmutable());
        $this->repository->store('SML', '{}', false, new DateTimeImmutable());
        $this->repository->store('OEX', '{}', false, new DateTimeImmutable());

        self::assertSame(4, $this->repository->count());
    }

    public function testElCodigoSeNormalizaAMayusculas(): void
    {
        $this->repository->store('gspc', '{}', true, new DateTimeImmutable());

        self::assertTrue($this->repository->has('GSPC'));
    }
}
