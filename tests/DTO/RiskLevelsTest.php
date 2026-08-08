<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\DTO;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\RiskLevels;

final class RiskLevelsTest extends TestCase
{
    public function testComputeAplicaMultiplicadorYRatioRiesgoBeneficio(): void
    {
        // precio=100, atr14=4, multiplicador=2.5, ratio=2:1
        // riesgo = 2.5 * 4 = 10
        // stop = 100 - 10 = 90
        // objetivo = 100 + (2 * 10) = 120
        $riskLevels = RiskLevels::compute(100.0, 4.0, 2.5, 2.0);

        self::assertEqualsWithDelta(90.0, $riskLevels->getStopLoss(), 0.0001);
        self::assertEqualsWithDelta(120.0, $riskLevels->getTarget(), 0.0001);
    }

    /**
     * Caso limite: atr14=0.0 (serie totalmente plana). riesgo = multiplicador
     * * 0 = 0, asi que tanto el stop como el objetivo colapsan sobre el
     * propio precio. No hay ninguna proteccion especial para este caso
     * dentro de compute() (es una formula pura, ver docblock de la clase);
     * la decision de si atr14=0 debe mostrarse o no vive en
     * Services\RiskLevelsCalculator (ver RiskLevelsCalculatorTest).
     */
    public function testComputeConAtrCeroColapsaStopYObjetivoEnElPrecio(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 0.0, 2.5, 2.0);

        self::assertEqualsWithDelta(100.0, $riskLevels->getStopLoss(), 0.0001);
        self::assertEqualsWithDelta(100.0, $riskLevels->getTarget(), 0.0001);
    }

    /**
     * Ratio riesgo/beneficio 1:1: el objetivo debe quedar tan lejos del
     * precio como el stop-loss (misma distancia, direcciones opuestas).
     */
    public function testComputeConRatioRiesgoBeneficio1a1EsSimetrico(): void
    {
        // riesgo = 2.5 * 4 = 10
        $riskLevels = RiskLevels::compute(100.0, 4.0, 2.5, 1.0);

        self::assertEqualsWithDelta(90.0, $riskLevels->getStopLoss(), 0.0001);
        self::assertEqualsWithDelta(110.0, $riskLevels->getTarget(), 0.0001);
    }

    /**
     * Caso limite: precio=0. compute() es deliberadamente una formula pura
     * sin logica de "cuando aplicarla" (ver docblock de la clase); ese
     * guardarraya (precio <= 0) vive en RiskLevelsCalculator, no aqui. Este
     * test documenta el comportamiento real y actual de la formula cuando
     * se le pasa un precio invalido: el stop-loss da negativo, lo cual
     * solo tiene sentido porque RiskLevelsCalculator nunca deberia llegar
     * a llamar a compute() con precio <= 0 (ver
     * RiskLevelsCalculatorTest::testConPrecioCeroDevuelveNull y
     * ::testConPrecioNegativoDevuelveNull).
     */
    public function testComputeConPrecioCeroPuedeDarStopLossNegativo(): void
    {
        // riesgo = 2.5 * 4 = 10
        $riskLevels = RiskLevels::compute(0.0, 4.0, 2.5, 2.0);

        self::assertEqualsWithDelta(-10.0, $riskLevels->getStopLoss(), 0.0001);
        self::assertEqualsWithDelta(20.0, $riskLevels->getTarget(), 0.0001);
    }

    /**
     * portfolioValue=10000, riskPercent=1.5%, precio=100, stopLoss=90
     * (riesgo por accion = 10). riesgo total = 10000 * 1.5% = 150.
     * cantidad = 150 / 10 = 15 acciones = 1.500 = 15% de la cartera, por
     * debajo del peso maximo por posicion (20% = 20 acciones), asi que
     * manda el riesgo por operacion y no se acota.
     */
    public function testSuggestedQuantityCalculaSegunElRiesgoPorOperacion(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 4.0, 2.5, 2.0);

        self::assertEqualsWithDelta(15.0, $riskLevels->suggestedQuantity(10000.0, 1.5, 100.0), 0.0001);
        self::assertFalse($riskLevels->isLimitedByMaxPositionWeight(10000.0, 1.5, 100.0));
    }

    /**
     * Con un valor poco volatil (ATR14 bajo), el stop queda cerca del
     * precio y la regla del riesgo por operacion pide una posicion enorme:
     * ahi manda el peso maximo por posicion (ver versions.md v2.65).
     *
     * Caso real medido sobre la cartera del usuario (ELE.MC, 2026-08-08):
     * portfolioValue=2182,83, riskPercent=1,5%, precio=42,24,
     * ATR14=0,79508 -> stop=40,2523 (riesgo por accion 1,9877).
     * cantidad por riesgo = (2182,83*1,5%)/1,9877 = 16,47 acciones = 31,9%
     * de la cartera; el tope del 20% deja exactamente
     * (2182,83*20%)/42,24 = 10,335... acciones.
     */
    public function testSuggestedQuantitySeAcotaAlPesoMaximoPorPosicion(): void
    {
        $riskLevels = RiskLevels::compute(42.24, 0.79508, 2.5, 2.0);

        $expected = (2182.83 * 0.20) / 42.24;

        self::assertEqualsWithDelta($expected, $riskLevels->suggestedQuantity(2182.83, 1.5, 42.24, 20.0), 0.000001);
        self::assertTrue($riskLevels->isLimitedByMaxPositionWeight(2182.83, 1.5, 42.24, 20.0));
    }

    /**
     * El peso maximo por posicion es el tope por defecto (20%), sin tener
     * que pasarlo: la misma llamada de tres argumentos que hacia el codigo
     * anterior a v2.65 ya queda acotada.
     */
    public function testSuggestedQuantityAplicaElPesoMaximoPorDefecto(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 0.4, 2.5, 2.0);

        // cantidad por riesgo = (1000*1,5%)/1 = 15 acciones (1.500, un 150%
        // de la cartera); tope del 20% = (1000*20%)/100 = 2 acciones.
        self::assertEqualsWithDelta(2.0, $riskLevels->suggestedQuantity(1000.0, 1.5, 100.0), 0.0001);
    }

    /**
     * Retrocompatibilidad: con maxPositionPercent=100 el tope es el valor
     * entero de la cartera, exactamente el comportamiento anterior a
     * v2.65 (portfolioValue / price = 10 acciones).
     */
    public function testSuggestedQuantityConPesoMaximo100SeComportaComoAntes(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 0.4, 2.5, 2.0);

        self::assertEqualsWithDelta(10.0, $riskLevels->suggestedQuantity(1000.0, 1.5, 100.0, 100.0), 0.0001);
        self::assertTrue($riskLevels->isLimitedByMaxPositionWeight(1000.0, 1.5, 100.0, 100.0));
    }

    /**
     * Si no hay ninguna cantidad con sentido (stop-loss al mismo nivel o
     * por encima del precio), tampoco hay nada que explicar: false, no una
     * comparacion sobre un numero que no existe.
     */
    public function testIsLimitedByMaxPositionWeightConInputsInvalidosEsFalse(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 0.0, 2.5, 2.0);

        self::assertFalse($riskLevels->isLimitedByMaxPositionWeight(10000.0, 1.5, 100.0));

        $levels = RiskLevels::compute(100.0, 4.0, 2.5, 2.0);

        self::assertFalse($levels->isLimitedByMaxPositionWeight(0.0, 1.5, 100.0));
        self::assertFalse($levels->isLimitedByMaxPositionWeight(10000.0, 0.0, 100.0));
        self::assertFalse($levels->isLimitedByMaxPositionWeight(10000.0, 1.5, 0.0));
    }

    /**
     * Si el stop-loss sugerido esta al mismo nivel o por encima del precio
     * actual (riesgo por accion <= 0: puede pasar con ATR14 muy pequeno y
     * un precio que ya cayo desde que se calcularon los niveles), no hay
     * ninguna cantidad que tenga sentido: null en vez de una division por
     * cero o un numero negativo.
     */
    public function testSuggestedQuantityConStopLossIgualOMayorQuePrecioDevuelveNull(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 0.0, 2.5, 2.0);

        self::assertNull($riskLevels->suggestedQuantity(10000.0, 1.5, 100.0));
    }

    public function testSuggestedQuantityConPortfolioValueCeroDevuelveNull(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 4.0, 2.5, 2.0);

        self::assertNull($riskLevels->suggestedQuantity(0.0, 1.5, 100.0));
    }

    public function testSuggestedQuantityConRiskPercentCeroDevuelveNull(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 4.0, 2.5, 2.0);

        self::assertNull($riskLevels->suggestedQuantity(10000.0, 0.0, 100.0));
    }

    public function testSuggestedQuantityConPrecioCeroDevuelveNull(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 4.0, 2.5, 2.0);

        self::assertNull($riskLevels->suggestedQuantity(10000.0, 1.5, 0.0));
    }
}
