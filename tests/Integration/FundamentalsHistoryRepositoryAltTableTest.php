<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use InvalidArgumentException;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;

/**
 * FundamentalsHistoryRepository apuntando a la tabla PARALELA
 * `fundamentals_history_v2110` (migracion 020, roadmap.md "Prioridad
 * cero" punto 4): la regeneracion con la formula corregida no debe
 * escribir NUNCA en `fundamentals_history`, la tabla real que usa hoy la
 * aplicacion.
 */
final class FundamentalsHistoryRepositoryAltTableTest extends IntegrationTestCase
{
    private function fundamentals(float $per): Fundamentals
    {
        return new Fundamentals(
            per: $per,
            peg: null,
            roe: null,
            roic: null,
            eps: null,
            marketCap: null,
            debtToEquity: null,
            freeCashFlow: null,
            evToEbitda: null,
            priceToBook: null,
            dividendYield: null,
            payoutRatio: null,
            grossMargin: null,
            operatingMargin: null,
            netMargin: null,
            revenueGrowth: null,
            currentRatio: null
        );
    }

    public function testEscribirEnLaTablaAlternativaNoTocaLaReal(): void
    {
        $real = new FundamentalsHistoryRepository($this->connection());
        $v2110 = new FundamentalsHistoryRepository($this->connection(), 'fundamentals_history_v2110');

        $fecha = new DateTimeImmutable('2026-01-15');
        $v2110->recordSnapshot('AAPL', $this->fundamentals(20.0), $fecha);

        self::assertSame(0, $real->countSnapshots('AAPL'), 'La tabla real no debe recibir nada.');
        self::assertSame(1, $v2110->countSnapshots('AAPL'));
        self::assertNull($real->findAsOf('AAPL', $fecha));
        self::assertNotNull($v2110->findAsOf('AAPL', $fecha));
        self::assertSame(20.0, $v2110->findAsOf('AAPL', $fecha)['per']);
    }

    public function testLasDosTablasSonIndependientesParaElMismoTickerYFecha(): void
    {
        $real = new FundamentalsHistoryRepository($this->connection());
        $v2110 = new FundamentalsHistoryRepository($this->connection(), 'fundamentals_history_v2110');

        $fecha = new DateTimeImmutable('2026-01-15');
        $real->recordSnapshot('AAPL', $this->fundamentals(81.08), $fecha);
        $v2110->recordSnapshot('AAPL', $this->fundamentals(20.08), $fecha);

        self::assertSame(81.08, $real->findAsOf('AAPL', $fecha)['per']);
        self::assertSame(20.08, $v2110->findAsOf('AAPL', $fecha)['per']);
    }

    public function testUnNombreDeTablaInvalidoLanzaExcepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FundamentalsHistoryRepository($this->connection(), 'fundamentals_history; DROP TABLE users;--');
    }
}
