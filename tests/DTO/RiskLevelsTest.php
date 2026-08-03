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
     * cantidad = 150 / 10 = 15 acciones, muy por debajo de lo maximo
     * comprable (10000 / 100 = 100 acciones), asi que no se acota.
     */
    public function testSuggestedQuantityCalculaSegunElRiesgoPorOperacion(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 4.0, 2.5, 2.0);

        self::assertEqualsWithDelta(15.0, $riskLevels->suggestedQuantity(10000.0, 1.5, 100.0), 0.0001);
    }

    /**
     * Con un stop-loss muy ajustado (poco riesgo por accion), la formula de
     * riesgo por operacion pediria comprar mas acciones de las que el valor
     * de la cartera permite pagar a precio de mercado: la cantidad sugerida
     * se acota al maximo comprable, no a lo que pediria el riesgo.
     *
     * portfolioValue=1000, riskPercent=1.5%, precio=100, stopLoss=99
     * (riesgo por accion=1). riesgo total = 1000*1.5% = 15.
     * cantidad sin acotar = 15/1 = 15 acciones = 1500, mas que los 1000
     * disponibles (10 acciones maximo).
     */
    public function testSuggestedQuantitySeAcotaALoMaximoComprable(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 0.4, 2.5, 2.0);

        self::assertEqualsWithDelta(10.0, $riskLevels->suggestedQuantity(1000.0, 1.5, 100.0), 0.0001);
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
