<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use DateTimeImmutable;

/**
 * Una fila de `/api/calendar/earnings` de EODHD ya validada y tipada, antes
 * de escribirse en la tabla `earnings_events` (Bloque C del plan de Codex
 * del 2026-09-04, `EodhdEarningsEventsNormalizer`).
 *
 * Distinto a proposito de `StockAnalyzer\DTO\EarningsEvent`: ese DTO
 * representa una fila de `Earnings.History` (Fundamentals, otro endpoint
 * de EODHD con su propia forma de JSON); este representa una fila del
 * calendario de resultados (`/api/calendar/earnings`). Los campos
 * coinciden en su mayoria porque describen el mismo hecho del mundo real
 * (un anuncio de resultados), pero acoplar ambos a un unico DTO haria que
 * cambiar el parseo de una fuente arriesgara romper en silencio al
 * consumidor de la otra.
 *
 * `$epsDifference`/`$epsSurprisePercent` se calculan en
 * `EodhdEarningsEventsNormalizer`, NO se copian del JSON de EODHD: ver el
 * docblock de esa clase para el porque (EODHD deja `difference=0`, no
 * `null`, cuando falta `actual`/`estimate`).
 */
final class CalendarEarningsEvent
{
    public function __construct(
        public readonly string $ticker,
        public readonly DateTimeImmutable $fiscalPeriodEnd,
        public readonly DateTimeImmutable $reportDate,
        public readonly ?string $beforeAfterMarket,
        public readonly ?float $epsActual,
        public readonly ?float $epsEstimate,
        public readonly ?float $epsDifference,
        public readonly ?float $epsSurprisePercent,
        public readonly ?string $currency
    ) {
    }
}
