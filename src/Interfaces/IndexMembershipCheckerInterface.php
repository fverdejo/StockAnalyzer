<?php

declare(strict_types=1);

namespace StockAnalyzer\Interfaces;

use DateTimeImmutable;

/**
 * Contrato minimo que necesita `BacktestingService::runCrossSectional()`
 * para filtrar un universo point-in-time (roadmap.md, "Segundo bloque"
 * punto 5, 2026-09-02): en la fecha $date, ¿$ticker era miembro de
 * $indexCode?
 *
 * Interfaz separada (en vez de acoplar directamente a
 * `IndexMembershipRepository`, que habla con MySQL) para que los tests de
 * `BacktestingService` puedan inyectar una implementacion en memoria sin
 * base de datos, mismo criterio que `MarketDataProviderInterface` ya
 * permite hacer con los precios.
 */
interface IndexMembershipCheckerInterface
{
    public function isMemberAt(string $ticker, string $indexCode, DateTimeImmutable $date): bool;
}
