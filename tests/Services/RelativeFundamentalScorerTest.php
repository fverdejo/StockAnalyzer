<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\RelativeFundamentalScorer;

/**
 * Prototipo `feature/solo-tecnico` (ver versions.md): antes de conectar
 * `RelativeFundamentalScorer` a un backtest real, sus dos reglas puras
 * tienen que estar probadas por si solas. `percentileRank()` decide "quien
 * gana" y `pointsFor()` decide "cuanto vale ganar"; un error en cualquiera
 * de las dos contaminaria cualquier medicion posterior sin dar ningun
 * error visible.
 */
final class RelativeFundamentalScorerTest extends TestCase
{
    private function scorer(): RelativeFundamentalScorer
    {
        return new RelativeFundamentalScorer();
    }

    public function testMejorQueTodosLosPeersDaPercentilCien(): void
    {
        $peers = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0];

        self::assertSame(100.0, $this->scorer()->percentileRank(9.0, $peers, higherIsBetter: true));
    }

    public function testPeorQueTodosLosPeersDaPercentilCero(): void
    {
        $peers = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0];

        self::assertSame(0.0, $this->scorer()->percentileRank(0.0, $peers, higherIsBetter: true));
    }

    public function testPosicionIntermediaDaElPorcentajeDePeersSuperados(): void
    {
        // 6 de los 8 peers valen menos o igual que 7.0: percentil 75.
        $peers = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 8.0, 9.0];

        self::assertSame(75.0, $this->scorer()->percentileRank(7.0, $peers, higherIsBetter: true));
    }

    /**
     * Deuda/patrimonio: menos es mejor. El mismo valor de entrada tiene que
     * dar un percentil distinto (y aqui, opuesto) segun `$higherIsBetter`.
     */
    public function testMenorEsMejorInvierteElSentidoDelPercentil(): void
    {
        $peers = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0];

        self::assertSame(0.0, $this->scorer()->percentileRank(9.0, $peers, higherIsBetter: false));
        self::assertSame(100.0, $this->scorer()->percentileRank(0.0, $peers, higherIsBetter: false));
    }

    public function testEmpateConElPeorDeLosPeersCuentaAFavor(): void
    {
        $peers = [1.0, 1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0];

        self::assertSame(25.0, $this->scorer()->percentileRank(1.0, $peers, higherIsBetter: true));
    }

    /**
     * Con menos peers que el minimo (`MIN_PEERS = 8`), el percentil no
     * significa nada: mejor no puntuar por relatividad que inventar una
     * posicion sobre una muestra que no la sostiene.
     */
    public function testMenosDelMinimoDePeersDevuelveNull(): void
    {
        $peers = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0];

        self::assertNull($this->scorer()->percentileRank(4.5, $peers, higherIsBetter: true));
    }

    public function testSinPeersDevuelveNull(): void
    {
        self::assertNull($this->scorer()->percentileRank(4.5, [], higherIsBetter: true));
    }

    public function testPointsForEsLinealEntreCeroYElMaximo(): void
    {
        $scorer = $this->scorer();

        self::assertSame(0.0, $scorer->pointsFor(0.0, 12.0));
        self::assertSame(6.0, $scorer->pointsFor(50.0, 12.0));
        self::assertSame(12.0, $scorer->pointsFor(100.0, 12.0));
        self::assertSame(9.0, $scorer->pointsFor(75.0, 12.0));
    }

    /**
     * Sin percentil (peers insuficientes), el criterio es el mismo que usa
     * `FundamentalAnalyzer` cuando falta el dato en si: punto medio, ni
     * castiga ni premia.
     */
    public function testPointsForSinPercentilDaElPuntoMedio(): void
    {
        self::assertSame(6.0, $this->scorer()->pointsFor(null, 12.0));
        self::assertSame(4.0, $this->scorer()->pointsFor(null, 8.0));
    }

    public function testPointsForNoSePasaDelMaximoNiBajaDeCero(): void
    {
        $scorer = $this->scorer();

        self::assertSame(12.0, $scorer->pointsFor(150.0, 12.0));
        self::assertSame(0.0, $scorer->pointsFor(-10.0, 12.0));
    }
}
