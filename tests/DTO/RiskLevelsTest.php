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
}
