<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\DTO\FundamentalChangeAssessment;
use StockAnalyzer\DTO\PositionDecision;
use StockAnalyzer\Enums\FundamentalChangeVerdict;
use StockAnalyzer\Enums\PositionDecisionAction;
use StockAnalyzer\Enums\StopLossCheckState;
use StockAnalyzer\Models\Holding;

/**
 * Combina la recomendacion del score con el contexto de cartera de UN
 * ticker (posicion abierta, stop-loss activo, diagnostico fundamental
 * interanual) para responder "que hago con esto", no solo "que dice el
 * score hoy" (P2 de `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, 2026-09-06).
 *
 * Formula pura: no llama a ningun repositorio ni proveedor, todo lo que
 * necesita se le pasa ya calculado (mismo patron que
 * Services\RiskLevelsCalculator/FundamentalHealthAssessor). No recalcula
 * ninguna puntuacion ni sustituye a `RecommendationExplainer` -- es una
 * capa aparte, pensada para leerse junto al resto de la ficha, no en su
 * lugar.
 *
 * **Alcance deliberadamente reducido frente a la propuesta original de
 * Astra**: no incluye la reduccion cuantificada por limite de exposicion
 * de cartera ("posicion por encima de un limite adoptado por el usuario")
 * porque ese calculo necesita el peso de la posicion sobre la cartera
 * COMPLETA (`Services\PortfolioConcentrationCalculator`), que hoy solo se
 * calcula al renderizar "Mi cartera", no en la ficha de un unico ticker
 * -- añadirlo aqui exigiria calcular la cartera entera (todas las
 * posiciones, todos los precios) solo para ver una ficha, un coste que no
 * se justifica todavia. Documentado como pendiente, no una tarea de hoy.
 */
final class PositionDecisionAdvisor
{
    /**
     * @param string $recommendation ver DTO\StockAnalysis::getRecommendation()
     *        ('BUY'/'HOLD'/'SELL'/'STRONG SELL'/'DATOS_INSUFICIENTES')
     * @param StopLossCheckState $stopLossState ver Services\AlertService::checkStopLossBreach():
     *        estado del stop-loss ACTIVO adoptado para esta posicion tras la
     *        comprobacion de HOY (irrelevante sin posicion abierta). `SIN_EVALUAR`
     *        (falta precio o niveles con los que adoptar un stop por primera vez)
     *        NUNCA se trata como "dentro" -- corregido el 2026-09-10, hallazgo de
     *        Astra en `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`, Entrega 1.
     */
    public function decide(
        string $recommendation,
        ?Holding $position,
        StopLossCheckState $stopLossState,
        ?FundamentalChangeAssessment $fundamentalChange
    ): PositionDecision {
        if ($position === null) {
            return $this->decideWithoutPosition($recommendation);
        }

        if ($stopLossState === StopLossCheckState::CRUZADO) {
            return new PositionDecision(
                PositionDecisionAction::SALIR,
                'El precio ha perdido el stop-loss adoptado para esta posicion.',
                'Se recalcula un stop nuevo si cierras del todo la posicion y la vuelves a abrir.',
                true
            );
        }

        if ($stopLossState === StopLossCheckState::SIN_EVALUAR) {
            return new PositionDecision(
                PositionDecisionAction::REVISAR_STOP,
                'No se puede confirmar hoy si el precio sigue por encima del stop-loss adoptado: faltan precio o indicadores tecnicos suficientes.',
                'Vuelve a la ficha cuando haya precio y niveles de riesgo disponibles para confirmar el estado del stop.',
                false
            );
        }

        if ($fundamentalChange !== null && $fundamentalChange->verdict === FundamentalChangeVerdict::DETERIORANDO) {
            return new PositionDecision(
                PositionDecisionAction::REVISAR_TESIS,
                'El diagnostico fundamental interanual (D2) muestra deterioro en la mayoria de los factores comparables.',
                'Revisa si el proximo cambio interanual deja de mostrar deterioro, o si el precio pierde el stop-loss adoptado.',
                false
            );
        }

        return new PositionDecision(
            PositionDecisionAction::MANTENER,
            'La posicion sigue dentro de su stop-loss adoptado, sin deterioro fundamental interanual detectado.',
            'Revisa si el precio pierde el stop-loss adoptado o si el diagnostico fundamental pasa a mostrar deterioro.',
            true
        );
    }

    private function decideWithoutPosition(string $recommendation): PositionDecision
    {
        if ($recommendation === 'BUY') {
            return new PositionDecision(
                PositionDecisionAction::CANDIDATA,
                'El score actual respalda entrar, pero ninguna version de este motor ha demostrado ventaja de retorno validada.',
                'Revisa si el score deja de decir BUY, o estudia una entrada con tu propio criterio de tamano y horizonte.',
                false
            );
        }

        return new PositionDecision(
            PositionDecisionAction::ESPERAR,
            'Sin posicion abierta y sin una razon de peso del score para entrar ahora mismo.',
            'Revisa si el score pasa a BUY.',
            false
        );
    }
}
