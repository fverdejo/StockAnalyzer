<?php

declare(strict_types=1);

namespace StockAnalyzer\Enums;

/**
 * Accion propuesta por Services\PositionDecisionAdvisor (P2 de
 * `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, 2026-09-06): combina la
 * recomendacion del score con el contexto de cartera (posicion abierta,
 * stop-loss activo, diagnostico fundamental interanual) para responder
 * "que hago con ESTA posicion", no solo "que dice el score hoy".
 */
enum PositionDecisionAction: string
{
    /**
     * Sin posicion abierta y el score no respalda entrar. No es una
     * prediccion de caida, es ausencia de una razon de peso para comprar.
     */
    case ESPERAR = 'esperar';

    /**
     * Sin posicion abierta, pero el score si respalda entrar. Candidata a
     * estudiar, no una orden de compra: el score no tiene ventaja de
     * retorno validada (ver panel-note de la ficha).
     */
    case CANDIDATA = 'candidata';

    /**
     * Posicion abierta, sin condicion de salida activada ni deterioro
     * fundamental detectado. Seguir dentro del plan no implica que el
     * precio vaya a subir.
     */
    case MANTENER = 'mantener';

    /**
     * Posicion abierta con deterioro fundamental interanual (D2)
     * observado: revisar la tesis con la que se abrio, no una orden
     * automatica de vender.
     */
    case REVISAR_TESIS = 'revisar_tesis';

    /**
     * Posicion abierta que ha perdido su stop-loss ACTIVO (ver
     * Services\AlertService::checkStopLossBreach(), corregido el
     * 2026-09-06): condicion de salida predefinida, activada.
     */
    case SALIR = 'salir';

    public function label(): string
    {
        return match ($this) {
            self::ESPERAR => 'Esperar',
            self::CANDIDATA => 'Candidata a estudiar',
            self::MANTENER => 'Mantener según el plan',
            self::REVISAR_TESIS => 'Revisar la tesis',
            self::SALIR => 'Salida activada',
        };
    }
}
