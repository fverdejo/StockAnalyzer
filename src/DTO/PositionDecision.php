<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Enums\PositionDecisionAction;

/**
 * Salida de Services\PositionDecisionAdvisor (P2 de
 * `MEJORAS_MOTOR_ASTRA_2026-09-06.md`): que hacer con la posicion (o la
 * ausencia de ella) de UN ticker concreto, con el motivo y la condicion
 * que cambiaria la decision.
 *
 * `$isManagementRule` distingue el origen de la accion (pedido
 * explicitamente por Astra, criterio de aceptacion de P2): `true` cuando
 * viene de una regla de gestion de riesgo ya elegida (el stop-loss
 * adoptado, seguir el plan mientras no salte ninguna alarma) -- algo que
 * el usuario ya decidio de antemano, no una apuesta nueva; `false` cuando
 * viene del score o del diagnostico fundamental, que son evidencia
 * observada pero SIN ventaja de retorno validada en este proyecto (ver
 * panel-note de la ficha de detalle) -- no deben presentarse con la misma
 * confianza que una regla de gestion ya asumida.
 */
final class PositionDecision
{
    public function __construct(
        public readonly PositionDecisionAction $action,
        public readonly string $reason,
        public readonly string $reviewTrigger,
        public readonly bool $isManagementRule
    ) {
    }
}
