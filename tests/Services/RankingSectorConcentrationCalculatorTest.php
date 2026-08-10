<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\RankingSectorConcentrationCalculator;

/**
 * "Las 10 mejores de hoy" pueden ser en la practica una apuesta sectorial:
 * medido sobre `largecap60`, el sector dominante ocupa de media 3,6 de las
 * 10 primeras posiciones y llega a 6 de 10 (ver versions.md v2.75).
 */
final class RankingSectorConcentrationCalculatorTest extends TestCase
{
    /**
     * @param list<string> $sectors
     * @return list<array{sector: string, count: int, percent: float}>|null
     */
    private function weights(array $sectors): ?array
    {
        return (new RankingSectorConcentrationCalculator())->computeFromSectors($sectors);
    }

    public function testOrdenaLosSectoresDeMayorAMenorPeso(): void
    {
        $weights = $this->weights([
            'Technology', 'Technology', 'Technology', 'Technology', 'Technology', 'Technology',
            'Energy', 'Energy', 'Healthcare', 'Utilities',
        ]);

        self::assertNotNull($weights);
        self::assertSame('Technology', $weights[0]['sector']);
        self::assertSame(6, $weights[0]['count']);
        self::assertSame(60.0, $weights[0]['percent']);
        self::assertSame('Energy', $weights[1]['sector']);
    }

    public function testDetectaElSectorQueSuperaElUmbral(): void
    {
        $calculator = new RankingSectorConcentrationCalculator();
        $weights = $calculator->computeFromSectors([
            'Technology', 'Technology', 'Technology', 'Technology', 'Technology',
            'Energy', 'Energy', 'Healthcare', 'Utilities', 'Financial Services',
        ]);

        $overweight = $calculator->overweightSectors($weights);

        self::assertCount(1, $overweight);
        self::assertSame('Technology', $overweight[0]['sector']);
        self::assertSame(50.0, $overweight[0]['percent']);
    }

    /**
     * Justo en el umbral (40%) no hay aviso: el criterio es "> 40%", el
     * mismo que aplica PortfolioConcentration a la cartera.
     */
    public function testExactamenteEnElUmbralNoAvisa(): void
    {
        $calculator = new RankingSectorConcentrationCalculator();
        $weights = $calculator->computeFromSectors([
            'Technology', 'Technology', 'Technology', 'Technology',
            'Energy', 'Energy', 'Energy', 'Healthcare', 'Utilities', 'Financial Services',
        ]);

        self::assertSame(40.0, $weights[0]['percent']);
        self::assertSame([], $calculator->overweightSectors($weights));
    }

    public function testSoloMiraLasPrimerasPosiciones(): void
    {
        // 10 primeras todas de Technology, y luego 20 de otros sectores que
        // no deben diluir el aviso: nadie compra la posicion 25 del ranking.
        $sectors = array_merge(
            array_fill(0, 10, 'Technology'),
            array_fill(0, 20, 'Energy')
        );

        $weights = $this->weights($sectors);

        self::assertNotNull($weights);
        self::assertCount(1, $weights);
        self::assertSame(100.0, $weights[0]['percent']);
    }

    /**
     * El porcentaje se calcula sobre los valores CON sector conocido: si de
     * 10 solo 4 traen sector y 3 son del mismo, ese sector es el 75% de lo
     * clasificado, no el 30% del top. Quedarse corto justo en el aviso
     * seria el peor sitio para hacerlo.
     */
    public function testElPorcentajeSeCalculaSobreLoClasificado(): void
    {
        $weights = $this->weights([
            'Technology', 'Technology', 'Technology', 'Energy',
            '', '', '', '', '', '',
        ]);

        self::assertNotNull($weights);
        self::assertSame(75.0, $weights[0]['percent']);
        self::assertSame(3, $weights[0]['count']);
    }

    public function testSinSectoresConocidosDevuelveNull(): void
    {
        self::assertNull($this->weights(['', '', '']));
    }

    /**
     * Con un solo resultado, "el 100% es de un sector" es una obviedad y no
     * un aviso.
     */
    public function testConUnSoloResultadoNoHayNadaQueMedir(): void
    {
        self::assertNull($this->weights(['Technology']));
    }

    public function testRankingVacioDevuelveNull(): void
    {
        self::assertNull((new RankingSectorConcentrationCalculator())->computeFromSectors([]));
    }
}
