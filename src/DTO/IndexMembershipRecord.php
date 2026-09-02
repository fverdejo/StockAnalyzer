<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use DateTimeImmutable;

/**
 * Un tramo de membresia de un ticker en un indice (roadmap.md, "Segundo
 * bloque" punto 2, 2026-09-02): la unidad minima con la que se reconstruye
 * un universo point-in-time (`IndexMembershipRepository::isMemberAt()`).
 *
 * `startDate`/`endDate` pueden ser `null`: EODHD no siempre conoce la fecha
 * exacta (145/819 miembros de GSPC.INDX no traen `StartDate`, casos
 * antiguos previos al inicio de su propio tracking) ni, si el ticker sigue
 * activo, hay `EndDate` que poner. `endDate === null` con `isActiveNow ===
 * true` significa "sigue siendo miembro hoy"; `startDate === null` significa
 * "era miembro ya cuando EODHD empezo a rastrear este indice, fecha exacta
 * desconocida" -- se trata como "miembro desde siempre" (cualquier fecha D
 * cuenta como despues del inicio), no como "nunca fue miembro".
 */
final class IndexMembershipRecord
{
    public function __construct(
        public readonly string $ticker,
        public readonly string $indexCode,
        public readonly ?string $companyName,
        public readonly ?DateTimeImmutable $startDate,
        public readonly ?DateTimeImmutable $endDate,
        public readonly bool $isActiveNow,
        public readonly bool $isDelisted,
        public readonly ?string $originalSymbol = null
    ) {
    }

    /**
     * Si esta membresia cubre la fecha $date: `startDate` desconocida
     * cuenta como "ya era miembro" (ver docblock de clase); `endDate` nulo
     * con `isActiveNow` cuenta como "sigue siendo miembro hoy", asi que
     * cualquier fecha hasta hoy esta cubierta.
     */
    public function coversDate(DateTimeImmutable $date): bool
    {
        if ($this->startDate !== null && $date < $this->startDate) {
            return false;
        }

        if ($this->endDate !== null && $date > $this->endDate) {
            return false;
        }

        return true;
    }
}
