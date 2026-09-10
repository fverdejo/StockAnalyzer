<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\FundamentalChangeAssessment;
use StockAnalyzer\Enums\FundamentalChangeVerdict;
use StockAnalyzer\Enums\PositionDecisionAction;
use StockAnalyzer\Enums\StopLossCheckState;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Services\PositionDecisionAdvisor;

/**
 * `PositionDecisionAdvisor` (P2 de `MEJORAS_MOTOR_ASTRA_2026-09-06.md`,
 * 2026-09-06): combina el score con el contexto de cartera para decidir
 * que hacer con UN ticker. Cubre los cinco comportamientos de ejemplo del
 * propio encargo, salvo la reduccion cuantificada por limite de
 * exposicion de cartera (fuera de alcance de esta version, ver docblock
 * de la clase).
 */
final class PositionDecisionAdvisorTest extends TestCase
{
    private PositionDecisionAdvisor $advisor;

    protected function setUp(): void
    {
        $this->advisor = new PositionDecisionAdvisor();
    }

    private function position(): Holding
    {
        return new Holding('ACME', 10.0, 100.0, 110.0);
    }

    private function changeWithVerdict(FundamentalChangeVerdict $verdict): FundamentalChangeAssessment
    {
        return new FundamentalChangeAssessment(false, $verdict, []);
    }

    /**
     * "Sin posicion y sin regla de entrada respaldada": esperar.
     */
    public function testSinPosicionYSinBuyEspera(): void
    {
        foreach (['HOLD', 'SELL', 'STRONG SELL', 'DATOS_INSUFICIENTES'] as $recommendation) {
            $decision = $this->advisor->decide($recommendation, null, StopLossCheckState::SIN_EVALUAR, null);

            self::assertSame(PositionDecisionAction::ESPERAR, $decision->action, "recomendacion: {$recommendation}");
            self::assertFalse($decision->isManagementRule);
        }
    }

    /**
     * Sin posicion pero el score respalda entrar: candidata a estudiar, no
     * una orden de compra -- el motivo debe dejar claro que no hay ventaja
     * de retorno validada.
     */
    public function testSinPosicionYBuyEsCandidata(): void
    {
        $decision = $this->advisor->decide('BUY', null, StopLossCheckState::SIN_EVALUAR, null);

        self::assertSame(PositionDecisionAction::CANDIDATA, $decision->action);
        self::assertFalse($decision->isManagementRule);
        self::assertStringContainsString('ventaja de retorno validada', $decision->reason);
    }

    /**
     * "Condicion de salida predefinida activada": el stop-loss adoptado se
     * ha perdido. Tiene prioridad sobre el diagnostico fundamental (una
     * posicion puede tener ambas cosas a la vez; el precio manda primero).
     */
    public function testConPosicionYStopPerdidoEsSalir(): void
    {
        $decision = $this->advisor->decide(
            'HOLD',
            $this->position(),
            StopLossCheckState::CRUZADO,
            $this->changeWithVerdict(FundamentalChangeVerdict::DETERIORANDO)
        );

        self::assertSame(PositionDecisionAction::SALIR, $decision->action);
        self::assertTrue($decision->isManagementRule);
    }

    /**
     * Hallazgo real de Astra (`PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`,
     * Entrega 1): sin poder confirmar el estado del stop (falta precio o
     * `RiskLevels`), la decision no puede ser `MANTENER` con el texto
     * "dentro de su stop-loss adoptado" -- eso afirmaria una proteccion que
     * no esta confirmada. Tampoco es una orden automatica de vender: es una
     * condicion no evaluable, con su propia accion.
     */
    public function testConPosicionYStopNoEvaluableEsRevisarStop(): void
    {
        $decision = $this->advisor->decide(
            'HOLD',
            $this->position(),
            StopLossCheckState::SIN_EVALUAR,
            null
        );

        self::assertSame(PositionDecisionAction::REVISAR_STOP, $decision->action);
        self::assertFalse($decision->isManagementRule);
        self::assertStringNotContainsString('dentro de su stop-loss', $decision->reason);
    }

    /**
     * El stop no evaluable tiene prioridad sobre el deterioro fundamental,
     * igual que el stop cruzado: no evaluar un mecanismo de seguridad de
     * precio no debe quedar oculto detras de una alarma mas lenta.
     */
    public function testStopNoEvaluableTienePrioridadSobreDeterioroFundamental(): void
    {
        $decision = $this->advisor->decide(
            'HOLD',
            $this->position(),
            StopLossCheckState::SIN_EVALUAR,
            $this->changeWithVerdict(FundamentalChangeVerdict::DETERIORANDO)
        );

        self::assertSame(PositionDecisionAction::REVISAR_STOP, $decision->action);
    }

    /**
     * "Deterioro fundamental observado": revisar la tesis, mostrando que
     * ha cambiado -- nunca una orden automatica de vender.
     */
    public function testConPosicionYDeterioroFundamentalEsRevisarTesis(): void
    {
        $decision = $this->advisor->decide(
            'HOLD',
            $this->position(),
            StopLossCheckState::DENTRO,
            $this->changeWithVerdict(FundamentalChangeVerdict::DETERIORANDO)
        );

        self::assertSame(PositionDecisionAction::REVISAR_TESIS, $decision->action);
        self::assertFalse($decision->isManagementRule);
    }

    /**
     * "Posicion dentro del plan, sin condicion de salida activada":
     * mantener, sin afirmar por ello que el precio vaya a subir.
     */
    public function testConPosicionSinAlarmasEsMantener(): void
    {
        $decision = $this->advisor->decide(
            'SELL',
            $this->position(),
            StopLossCheckState::DENTRO,
            $this->changeWithVerdict(FundamentalChangeVerdict::ESTABLE)
        );

        self::assertSame(PositionDecisionAction::MANTENER, $decision->action);
        self::assertTrue($decision->isManagementRule);
        self::assertStringNotContainsString('subir', $decision->reason);
    }

    /**
     * Mejorando/Mixto/No_evaluable no disparan "revisar tesis": solo
     * Deteriorando lo hace.
     */
    public function testSoloDeteriorandoDisparaRevisarTesis(): void
    {
        foreach ([FundamentalChangeVerdict::MEJORANDO, FundamentalChangeVerdict::MIXTO, FundamentalChangeVerdict::ESTABLE, FundamentalChangeVerdict::NO_EVALUABLE] as $verdict) {
            $decision = $this->advisor->decide('HOLD', $this->position(), StopLossCheckState::DENTRO, $this->changeWithVerdict($verdict));

            self::assertSame(PositionDecisionAction::MANTENER, $decision->action, $verdict->value);
        }
    }

    public function testSinDiagnosticoFundamentalDisponibleEsMantenerSiNoHayOtraAlarma(): void
    {
        $decision = $this->advisor->decide('HOLD', $this->position(), StopLossCheckState::DENTRO, null);

        self::assertSame(PositionDecisionAction::MANTENER, $decision->action);
    }

    /**
     * Toda decision trae una condicion de revision explicita, nunca vacia.
     */
    public function testTodaDecisionTraeCondicionDeRevision(): void
    {
        $decisiones = [
            $this->advisor->decide('SELL', null, StopLossCheckState::SIN_EVALUAR, null),
            $this->advisor->decide('BUY', null, StopLossCheckState::SIN_EVALUAR, null),
            $this->advisor->decide('HOLD', $this->position(), StopLossCheckState::CRUZADO, null),
            $this->advisor->decide('HOLD', $this->position(), StopLossCheckState::SIN_EVALUAR, null),
            $this->advisor->decide('HOLD', $this->position(), StopLossCheckState::DENTRO, $this->changeWithVerdict(FundamentalChangeVerdict::DETERIORANDO)),
            $this->advisor->decide('HOLD', $this->position(), StopLossCheckState::DENTRO, null),
        ];

        foreach ($decisiones as $decision) {
            self::assertNotSame('', trim($decision->reviewTrigger));
            self::assertNotSame('', trim($decision->reason));
        }
    }
}
