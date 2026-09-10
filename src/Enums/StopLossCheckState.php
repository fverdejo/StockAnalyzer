<?php

declare(strict_types=1);

namespace StockAnalyzer\Enums;

/**
 * Resultado de comparar el precio actual contra el stop-loss ACTIVO de una
 * posicion abierta (ver Services\AlertService::checkStopLossBreach()).
 *
 * Existe como tipo aparte, separado del ultimo estado que se persiste para
 * decidir si toca alertar (`TickerStopLossAlertStateRepository::getLastState()`),
 * a raiz de un hallazgo real de Astra
 * (`PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`, Entrega 1): antes,
 * `checkStopLossBreach()` abandonaba sin hacer nada en cuanto faltaban
 * `RiskLevels` nuevos (indicadores tecnicos insuficientes), y quien
 * necesitaba saber el estado (`PositionDecisionAdvisor`) leia por separado
 * el ULTIMO estado guardado como si fuera el de HOY -- con precio
 * disponible y solo los niveles ausentes, esa lectura podia estar
 * desactualizada en cualquier direccion: un stop perdido de verdad seguia
 * pareciendo "por encima" (ninguna alerta, `MANTENER`), o un precio ya
 * recuperado seguia pareciendo "perdido" (`SALIR` de mas). `SIN_EVALUAR`
 * hace ese hueco explicito en vez de convertirlo en `false` por defecto.
 */
enum StopLossCheckState
{
    /** El precio disponible esta por encima del stop-loss activo adoptado. */
    case DENTRO;

    /** El precio disponible esta en o por debajo del stop-loss activo adoptado. */
    case CRUZADO;

    /**
     * No se puede determinar: falta precio o fecha de apertura de la
     * posicion, o no hay ningun stop adoptado todavia para esta racha y no
     * llegaron `RiskLevels` nuevos con los que adoptar uno.
     */
    case SIN_EVALUAR;
}
