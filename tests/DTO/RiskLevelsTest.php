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
     * otrasPosiciones=10000, riskPercent=1.5%, precio=100, stopLoss=90
     * (riesgo por accion = 10).
     *
     * Desde v2.83 el primer argumento es el valor de las OTRAS posiciones y
     * la cantidad es la que deja el riesgo en 1,5% de la cartera RESULTANTE:
     *   cantidad*10 = 1,5% * (10000 + cantidad*100)
     *   => cantidad = 10000*1,5 / (100*10 - 1,5*100) = 15000/850 = 17,647...
     * Son 1.764,71 de posicion sobre una cartera de 11.764,71, es decir un
     * 15% de peso: por debajo del tope del 20%, asi que manda el riesgo.
     */
    public function testSuggestedQuantityCalculaSegunElRiesgoPorOperacion(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 4.0, 2.5, 2.0);

        $expected = (10000.0 * 1.5) / ((100 * 10.0) - (1.5 * 100.0));

        self::assertEqualsWithDelta(17.647058, $expected, 0.0001);
        self::assertEqualsWithDelta($expected, $riskLevels->suggestedQuantity(10000.0, 1.5, 100.0), 0.0001);
        self::assertFalse($riskLevels->isLimitedByMaxPositionWeight(10000.0, 1.5, 100.0));
    }

    /**
     * La razon de ser de v2.83: la sugerencia es un objetivo ESTABLE. Comprada
     * la cantidad sugerida, volver a preguntar devuelve la misma cantidad, en
     * los dos regimenes (acotado por riesgo y acotado por peso). Antes no era
     * asi: la base del calculo era el valor total de la cartera, que crecia
     * con la propia compra, asi que la sugerencia subia cada vez que el
     * usuario compraba para cuadrar con ella.
     */
    public function testLaSugerenciaNoSeMueveAlComprarla(): void
    {
        // Acotada por riesgo (stop lejos: 10% del precio).
        $porRiesgo = RiskLevels::compute(100.0, 4.0, 2.5, 2.0);
        $otras = 10000.0;
        $cantidad = $porRiesgo->suggestedQuantity($otras, 1.5, 100.0);

        self::assertNotNull($cantidad);
        // Comprada: el riesgo asumido es exactamente el 1,5% de la cartera
        // resultante, y las "otras posiciones" no han cambiado, asi que la
        // sugerencia tampoco.
        self::assertEqualsWithDelta(
            1.5,
            (($cantidad * 10.0) / ($otras + ($cantidad * 100.0))) * 100,
            0.000001
        );
        self::assertEqualsWithDelta($cantidad, $porRiesgo->suggestedQuantity($otras, 1.5, 100.0), 0.000001);

        // Acotada por peso (valor poco volatil, stop muy cerca del precio).
        $porPeso = RiskLevels::compute(42.24, 0.79508, 2.5, 2.0);
        $otrasPeso = 2182.83;
        $cantidadPeso = $porPeso->suggestedQuantity($otrasPeso, 1.5, 42.24, 20.0);

        self::assertNotNull($cantidadPeso);
        self::assertTrue($porPeso->isLimitedByMaxPositionWeight($otrasPeso, 1.5, 42.24, 20.0));
        // Comprada: pesa exactamente el 20% de la cartera resultante.
        $valorPosicion = $cantidadPeso * 42.24;
        self::assertEqualsWithDelta(
            20.0,
            ($valorPosicion / ($otrasPeso + $valorPosicion)) * 100,
            0.000001
        );
    }

    /**
     * Con un valor poco volatil (ATR14 bajo), el stop queda cerca del
     * precio y la regla del riesgo por operacion pide una posicion enorme:
     * ahi manda el peso maximo por posicion (ver versions.md v2.65).
     *
     * Caso real medido sobre la cartera del usuario (ELE.MC, 2026-08-08):
     * otrasPosiciones=2182,83, riskPercent=1,5%, precio=42,24,
     * ATR14=0,79508 -> stop=40,2523 (riesgo por accion 1,9877).
     * cantidad por riesgo = 2182,83*1,5 / (100*1,9877 - 1,5*42,24) = 24,18
     * acciones; el tope del 20% deja 2182,83*20 / (42,24*80) = 12,919...
     */
    public function testSuggestedQuantitySeAcotaAlPesoMaximoPorPosicion(): void
    {
        $riskLevels = RiskLevels::compute(42.24, 0.79508, 2.5, 2.0);

        $expected = (2182.83 * 20) / (42.24 * (100 - 20));

        self::assertEqualsWithDelta(12.919212, $expected, 0.000001);
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

        // stop = 99, o sea el 1% por debajo del precio: menos que el 1,5% de
        // riesgo permitido, asi que la ecuacion del riesgo no cierra (cada
        // accion comprada añade a la cartera mas presupuesto de riesgo del que
        // consume) y manda el tope por peso, que es el que siempre acota:
        // 1000*20 / (100*80) = 2,5 acciones.
        self::assertEqualsWithDelta(2.5, $riskLevels->suggestedQuantity(1000.0, 1.5, 100.0), 0.0001);
        self::assertTrue($riskLevels->isLimitedByMaxPositionWeight(1000.0, 1.5, 100.0));
    }

    /**
     * Un peso maximo del 100% (o mas) no tiene sentido desde v2.83 y devuelve
     * null: la condicion "esta posicion pesa el 100% de una cartera que la
     * incluye" la cumple cualquier cantidad, y el punto fijo se va a infinito.
     * Antes de v2.83 ese valor servia para desactivar el tope y quedarse con
     * "lo maximo comprable" (cartera/precio), un tope que en la practica no
     * acotaba nada (ver v2.65).
     */
    public function testSuggestedQuantityConPesoMaximo100NoTieneSentidoYDevuelveNull(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 0.4, 2.5, 2.0);

        self::assertNull($riskLevels->suggestedQuantity(1000.0, 1.5, 100.0, 100.0));
        self::assertFalse($riskLevels->isLimitedByMaxPositionWeight(1000.0, 1.5, 100.0, 100.0));
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
