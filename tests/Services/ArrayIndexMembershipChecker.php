<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use StockAnalyzer\DTO\IndexMembershipRecord;
use StockAnalyzer\Interfaces\IndexMembershipCheckerInterface;

/**
 * Doble en memoria de `IndexMembershipRepository` (roadmap.md, "Segundo
 * bloque" punto 5), para probar el filtro point-in-time de
 * `BacktestingService::runCrossSectional()` sin hablar con MySQL.
 *
 * Cada entrada declara un tramo de membresia con fechas de tipo string
 * ('Y-m-d'); `null` en `endDate` significa "sigue siendo miembro
 * indefinidamente". Delega en `IndexMembershipRecord::coversDate()` (misma
 * logica que usa la clase real, no una reimplementacion paralela que
 * pudiera divergir con el tiempo).
 */
final class ArrayIndexMembershipChecker implements IndexMembershipCheckerInterface
{
    /** @var list<IndexMembershipRecord> */
    private array $records;

    /**
     * @param list<array{ticker: string, indexCode: string, startDate: ?string, endDate: ?string}> $memberships
     */
    public function __construct(array $memberships)
    {
        $this->records = array_map(
            static fn (array $membership): IndexMembershipRecord => new IndexMembershipRecord(
                ticker: strtoupper($membership['ticker']),
                indexCode: strtoupper($membership['indexCode']),
                companyName: null,
                startDate: $membership['startDate'] !== null ? new DateTimeImmutable($membership['startDate']) : null,
                endDate: $membership['endDate'] !== null ? new DateTimeImmutable($membership['endDate']) : null,
                isActiveNow: $membership['endDate'] === null,
                isDelisted: false
            ),
            $memberships
        );
    }

    public function isMemberAt(string $ticker, string $indexCode, DateTimeImmutable $date): bool
    {
        foreach ($this->records as $record) {
            if ($record->ticker !== strtoupper($ticker) || $record->indexCode !== strtoupper($indexCode)) {
                continue;
            }

            if ($record->coversDate($date)) {
                return true;
            }
        }

        return false;
    }
}
