<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\DTO;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\IndexMembershipRecord;

/**
 * Cubre `IndexMembershipRecord::coversDate()`, la logica point-in-time que
 * decide si un ticker "existia" en un indice en una fecha D (roadmap.md,
 * "Segundo bloque" punto 5, 2026-09-02). El criterio de salida del plan
 * exige exactamente estos dos casos: un componente que entro DESPUES de D
 * no cuenta, uno que salio ANTES de D tampoco.
 */
final class IndexMembershipRecordTest extends TestCase
{
    private function record(?string $start, ?string $end, bool $isActiveNow = false): IndexMembershipRecord
    {
        return new IndexMembershipRecord(
            ticker: 'TEST',
            indexCode: 'GSPC',
            companyName: null,
            startDate: $start !== null ? new DateTimeImmutable($start) : null,
            endDate: $end !== null ? new DateTimeImmutable($end) : null,
            isActiveNow: $isActiveNow,
            isDelisted: false
        );
    }

    public function testFechaDentroDelTramoCuenta(): void
    {
        $record = $this->record('2015-01-01', '2020-01-01');

        self::assertTrue($record->coversDate(new DateTimeImmutable('2017-06-01')));
    }

    public function testFechaAntesDeEntrarNoCuenta(): void
    {
        $record = $this->record('2015-01-01', '2020-01-01');

        self::assertFalse($record->coversDate(new DateTimeImmutable('2014-12-31')));
    }

    public function testFechaDespuesDeSalirNoCuenta(): void
    {
        $record = $this->record('2015-01-01', '2020-01-01');

        self::assertFalse($record->coversDate(new DateTimeImmutable('2020-01-02')));
    }

    public function testSinFechaDeInicioCuentaComoMiembroDesdeSiempre(): void
    {
        $record = $this->record(null, '2020-01-01');

        self::assertTrue($record->coversDate(new DateTimeImmutable('1999-01-01')));
    }

    public function testSinFechaDeSalidaYActivoCuentaHoy(): void
    {
        $record = $this->record('2015-01-01', null, isActiveNow: true);

        self::assertTrue($record->coversDate(new DateTimeImmutable('2026-09-02')));
    }

    public function testLimitesInclusivos(): void
    {
        $record = $this->record('2015-01-01', '2020-01-01');

        self::assertTrue($record->coversDate(new DateTimeImmutable('2015-01-01')));
        self::assertTrue($record->coversDate(new DateTimeImmutable('2020-01-01')));
    }
}
