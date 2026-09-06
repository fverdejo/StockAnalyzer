<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use DateTimeImmutable;

/**
 * El nivel de stop-loss ADOPTADO para la posicion abierta actual de un
 * ticker (correccion del 2026-09-06 al bug senalado por Astra/Codex en
 * `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, P0: comparar el precio contra un
 * stop recien recalculado con ese mismo precio hacia que la alerta de
 * perdida de stop-loss nunca pudiera dispararse).
 *
 * `$price` se fija UNA VEZ, la primera vez que se observa la posicion
 * abierta, y no se recalcula mientras la misma racha continua siga
 * abierta -- por eso este DTO existe aparte de `RiskLevels`, que SI se
 * recalcula en cada visita con el precio de ese momento (referencia
 * informativa, no el nivel de alerta).
 *
 * `$positionOpenedAt` es la fecha de inicio de esa racha continua (ver
 * Services\PortfolioService::currentPositionOpenedAt()): permite detectar
 * si un stop guardado pertenece a la posicion actual o a un ciclo anterior
 * ya cerrado (vender del todo y volver a comprar el mismo ticker).
 */
final class ActiveStopLoss
{
    public function __construct(
        public readonly float $price,
        public readonly DateTimeImmutable $positionOpenedAt
    ) {
    }
}
