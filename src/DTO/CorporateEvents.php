<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use DateTimeImmutable;

/**
 * Proxima fecha de resultados financieros y proxima fecha EX-DIVIDENDO de
 * una empresa, pensadas para alimentar una futura alerta de watchlist ("te
 * avisamos antes de que toque comprar para tener derecho al dividendo").
 * Ambos campos se obtienen siempre de Yahoo Finance (modulo calendarEvents
 * de quoteSummary), independientemente del proveedor de mercado activo.
 *
 * IMPORTANTE: nextExDividendDate de Yahoo no es siempre estrictamente
 * futura. Cuando la empresa aun no ha anunciado el proximo reparto, Yahoo
 * puede seguir devolviendo la fecha ex-dividendo ya pasada del ultimo
 * reparto conocido (observado en pruebas reales contra la API con
 * SAN.MC: devolvio 2026-04-30 estando ya a 2026-08-02). Quien consuma
 * este DTO para disparar una alerta DEBE comprobar que la fecha es
 * posterior a "hoy" antes de tratarla como "proxima" y avisar.
 */
class CorporateEvents
{
    public function __construct(
        private readonly ?DateTimeImmutable $nextEarningsDate,
        private readonly ?DateTimeImmutable $nextExDividendDate,
        private readonly bool $isEarningsDateEstimate = false
    ) {
    }

    /**
     * Usado cuando no se pudo obtener el modulo calendarEvents de Yahoo
     * (ticker no encontrado, fallo de red, cambio de formato...). El resto
     * de la aplicacion debe tratar ambas fechas null como "dato no
     * disponible", nunca como "no hay proximo evento".
     */
    public static function empty(): self
    {
        return new self(null, null, false);
    }

    public function getNextEarningsDate(): ?DateTimeImmutable
    {
        return $this->nextEarningsDate;
    }

    public function getNextExDividendDate(): ?DateTimeImmutable
    {
        return $this->nextExDividendDate;
    }

    /**
     * true cuando Yahoo marca la fecha de resultados como estimada (todavia
     * no confirmada por la empresa), false cuando es una fecha confirmada.
     */
    public function isEarningsDateEstimate(): bool
    {
        return $this->isEarningsDateEstimate;
    }
}
