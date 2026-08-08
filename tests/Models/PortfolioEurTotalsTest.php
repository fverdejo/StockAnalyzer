<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Models;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;

/**
 * Los totales de la cabecera de "Mi cartera" mezclaban euros y dolares sin
 * convertir, asi que su porcentaje de rentabilidad era una media ponderada
 * con pesos equivocados: una posicion en dolares pesaba 1/0,8649 = 1,156
 * veces mas de lo que le corresponde (ver versions.md v2.68).
 *
 * Estos casos fijan la semantica de los totales en euros: coste al tipo de
 * cambio del dia de cada compra (los euros que de verdad se pagaron) y
 * valor de mercado al de hoy, de modo que el beneficio latente total
 * incluye el efecto del tipo de cambio y coincide exactamente con la suma
 * de los beneficios en euros por posicion que ya calcula v2.48.
 */
final class PortfolioEurTotalsTest extends TestCase
{
    private const USD_TO_EUR_TODAY = 0.8;

    /**
     * Cada especificacion es [ticker, precio medio, precio actual,
     * cantidad, divisa, coste en euros]. El coste en euros se prepara igual
     * que lo hace PortfolioService (tipo de cambio historico de cada
     * compra) y se deja a null para las posiciones que ya cotizan en euros,
     * como hace v2.48.
     *
     * @param list<array{0: string, 1: float, 2: float, 3: float, 4: string, 5: ?float}> $specs
     * @param array<string,?float> $ratesToEur divisa => tipo de cambio de hoy
     */
    private function portfolio(
        array $specs,
        ?float $realizedProfitEur = 0.0,
        ?float $totalBoughtAmountEur = null,
        array $ratesToEur = ['USD' => self::USD_TO_EUR_TODAY]
    ): Portfolio {
        $holdings = [];
        $prices = [];
        $currencies = [];

        foreach ($specs as [$ticker, $averagePrice, $currentPrice, $quantity, $currency, $investedEur]) {
            $rate = $ratesToEur[$currency] ?? null;
            $marketValueEur = ($currency === 'EUR' || $rate === null)
                ? null
                : $quantity * $currentPrice * $rate;

            $holdings[] = new Holding($ticker, $quantity, $averagePrice, $currentPrice, null, $investedEur, $marketValueEur);
            $prices[$ticker] = $currentPrice;
            $currencies[$ticker] = $currency;
        }

        return new Portfolio(
            $holdings,
            [],
            0.0,
            $prices,
            $currencies,
            $ratesToEur['USD'] ?? null,
            $ratesToEur,
            $realizedProfitEur,
            $totalBoughtAmountEur
        );
    }

    /**
     * Dos posiciones de 100 € de coste, una en euros y otra en dolares
     * comprada cuando el dolar valia mas (125 $ = 100 €). Hoy las dos valen
     * 110 € y la cartera vale 220 €, un 10% mas de lo invertido; la suma en
     * unidades mixtas (225 -> 247,5) dice otra cosa distinta.
     */
    public function testTotalsAreAddedInEuroAndNotInMixedUnits(): void
    {
        $portfolio = $this->portfolio([
            ['AAA.MC', 10.0, 11.0, 10.0, 'EUR', null],
            ['BBB', 12.5, 13.75, 10.0, 'USD', 100.0],
        ]);

        // Mixto (lo que se mostraba antes): 100 € + 125 $ y 110 € + 137,5 $.
        self::assertEqualsWithDelta(225.0, $portfolio->getInvestedAmount(), 0.0001);
        self::assertEqualsWithDelta(247.5, $portfolio->getMarketValue(), 0.0001);

        self::assertEqualsWithDelta(200.0, $portfolio->getInvestedAmountEur(), 0.0001);
        self::assertEqualsWithDelta(220.0, $portfolio->getMarketValueEur(), 0.0001);
        self::assertEqualsWithDelta(20.0, $portfolio->getUnrealizedProfitEur(), 0.0001);
        self::assertEqualsWithDelta(10.0, $portfolio->getUnrealizedProfitEurPercent(), 0.0001);
    }

    /**
     * El total en euros es exactamente la suma de las metricas en euros por
     * posicion de v2.48 (para las posiciones que ya cotizan en euros, su
     * metrica nativa ya es la metrica en euros). Si no lo fuera, la
     * cabecera y la tabla de posiciones estarian contando cosas distintas,
     * que es el defecto que v2.68 corrige.
     */
    public function testTheTotalIsTheSumOfThePerPositionEuroMetrics(): void
    {
        $portfolio = $this->portfolio([
            ['AAA.MC', 10.0, 11.0, 10.0, 'EUR', null],
            ['BBB', 12.5, 13.75, 10.0, 'USD', 100.0],
        ]);

        $holdings = $portfolio->getHoldings();
        $sumOfRows = $holdings[0]->getUnrealizedProfit() + $holdings[1]->getUnrealizedProfitEur();

        self::assertEqualsWithDelta($sumOfRows, $portfolio->getUnrealizedProfitEur(), 0.0001);
    }

    /**
     * Con posiciones equivalentes en euros el porcentaje no depende de la
     * divisa; con la suma mixta si dependia (la posicion en dolares pesaba
     * 1/0,8 = 1,25 veces mas de la cuenta).
     */
    public function testThePercentageNoLongerDependsOnTheCurrencyWeights(): void
    {
        $portfolio = $this->portfolio([
            ['AAA.MC', 100.0, 110.0, 1.0, 'EUR', null],
            ['BBB', 125.0, 125.0, 1.0, 'USD', 100.0],
        ]);

        // 100 € de coste que valen 110 € y 100 € de coste que siguen
        // valiendo 100 €: +5% sobre 200 € invertidos.
        self::assertEqualsWithDelta(5.0, $portfolio->getUnrealizedProfitEurPercent(), 0.0001);
        // La media ponderada en unidades mixtas daba otro numero.
        self::assertEqualsWithDelta(4.4444, (float) $portfolio->getUnrealizedProfitPercent(), 0.0001);
    }

    public function testOverallProfitInEuroAddsWhatIsAlreadyRealized(): void
    {
        $portfolio = $this->portfolio(
            [['AAA.MC', 100.0, 110.0, 1.0, 'EUR', null]],
            realizedProfitEur: 20.0,
            totalBoughtAmountEur: 200.0
        );

        self::assertEqualsWithDelta(30.0, $portfolio->getOverallProfitEur(), 0.0001);
        self::assertEqualsWithDelta(15.0, $portfolio->getOverallProfitEurPercent(), 0.0001);
    }

    /**
     * Todo o nada, mismo criterio que getMarketValueEur() (v2.66): un total
     * al que le falta una posicion no es "casi correcto", es otro numero
     * mas pequeño.
     */
    public function testWithoutTheEuroCostOfAPositionThereIsNoTotal(): void
    {
        $portfolio = $this->portfolio([
            ['AAA.MC', 10.0, 11.0, 10.0, 'EUR', null],
            ['BBB', 12.5, 13.75, 10.0, 'USD', null],
        ]);

        self::assertNull($portfolio->getInvestedAmountEur());
        self::assertNull($portfolio->getUnrealizedProfitEur());
        self::assertNull($portfolio->getUnrealizedProfitEurPercent());
        self::assertNull($portfolio->getOverallProfitEur());
    }

    public function testWithoutTheRealizedProfitInEuroThereIsNoOverallProfit(): void
    {
        $portfolio = $this->portfolio(
            [['AAA.MC', 100.0, 110.0, 1.0, 'EUR', null]],
            realizedProfitEur: null,
            totalBoughtAmountEur: null
        );

        self::assertEqualsWithDelta(10.0, $portfolio->getUnrealizedProfitEur(), 0.0001);
        self::assertNull($portfolio->getOverallProfitEur());
        self::assertNull($portfolio->getOverallProfitEurPercent());
    }

    public function testAnEmptyPortfolioHasZeroTotalsAndNoPercentage(): void
    {
        $portfolio = $this->portfolio([]);

        self::assertSame(0.0, $portfolio->getInvestedAmountEur());
        self::assertSame(0.0, $portfolio->getMarketValueEur());
        self::assertNull($portfolio->getUnrealizedProfitEurPercent());
    }
}
