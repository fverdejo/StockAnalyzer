<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\FundamentalChangeAssessment;
use StockAnalyzer\Enums\FundamentalChangeVerdict;
use StockAnalyzer\Enums\PositionDecisionAction;
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
            $decision = $this->advisor->decide($recommendation, null, false, null);

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
        $decision = $this->advisor->decide('BUY', null, false, null);

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
            true,
            $this->changeWithVerdict(FundamentalChangeVerdict::DETERIORANDO)
        );

        self::assertSame(PositionDecisionAction::SALIR, $decision->action);
        self::assertTrue($decision->isManagementRule);
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
            false,
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
            false,
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
            $decision = $this->advisor->decide('HOLD', $this->position(), false, $this->changeWithVerdict($verdict));

            self::assertSame(PositionDecisionAction::MANTENER, $decision->action, $verdict->value);
        }
    }

    public function testSinDiagnosticoFundamentalDisponibleEsMantenerSiNoHayOtraAlarma(): void
    {
        $decision = $this->advisor->decide('HOLD', $this->position(), false, null);

        self::assertSame(PositionDecisionAction::MANTENER, $decision->action);
    }

    /**
     * Toda decision trae una condicion de revision explicita, nunca vacia.
     */
    public function testTodaDecisionTraeCondicionDeRevision(): void
    {
        $decisiones = [
            $this->advisor->decide('SELL', null, false, null),
            $this->advisor->decide('BUY', null, false, null),
            $this->advisor->decide('HOLD', $this->position(), true, null),
            $this->advisor->decide('HOLD', $this->position(), false, $this->changeWithVerdict(FundamentalChangeVerdict::DETERIORANDO)),
            $this->advisor->decide('HOLD', $this->position(), false, null),
        ];

        foreach ($decisiones as $decision) {
            self::assertNotSame('', trim($decision->reviewTrigger));
            self::assertNotSame('', trim($decision->reason));
        }
    }
}
