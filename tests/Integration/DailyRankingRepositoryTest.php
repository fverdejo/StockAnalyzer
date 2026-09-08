<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Repository\DailyRankingRepository;

final class DailyRankingRepositoryTest extends IntegrationTestCase
{
    private DailyRankingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DailyRankingRepository($this->connection());
    }

    public function testLatestDateSinNingunRankingGuardadoDevuelveNull(): void
    {
        self::assertNull($this->repository->latestDate('largecap60'));
    }

    public function testLatestDateDevuelveLaFechaMasReciente(): void
    {
        $this->repository->save('largecap60', ['AAPL'], ['count' => 1], new DateTimeImmutable('2026-09-01'));
        $this->repository->save('largecap60', ['AAPL'], ['count' => 1], new DateTimeImmutable('2026-09-03'));
        $this->repository->save('largecap60', ['AAPL'], ['count' => 1], new DateTimeImmutable('2026-09-02'));

        self::assertSame('2026-09-03', $this->repository->latestDate('largecap60')?->format('Y-m-d'));
    }

    public function testLatestDateSoloMiraElNombreIndicado(): void
    {
        $this->repository->save('largecap60', ['AAPL'], ['count' => 1], new DateTimeImmutable('2026-09-05'));
        $this->repository->save('sp400', ['ACAD'], ['count' => 1], new DateTimeImmutable('2026-09-06'));

        self::assertSame('2026-09-05', $this->repository->latestDate('largecap60')?->format('Y-m-d'));
        self::assertSame('2026-09-06', $this->repository->latestDate('sp400')?->format('Y-m-d'));
    }
}
