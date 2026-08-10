<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\PortfolioConcentration;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Services\PortfolioConcentrationCalculator;

/**
 * El punto delicado de este calculo es la divisa: Holding::getMarketValue()
 * esta en divisa nativa y Portfolio::getMarketValue() suma euros con
 * dolares sin convertir (deliberado, ver versions.md v2.48), asi que un
 * peso relativo calculado sobre esa suma seria incorrecto. Estos casos
 * fijan que la conversion a euros se aplica ANTES de pesar y que sin tipo
 * de cambio no se inventa ningun reparto.
 */
final class PortfolioConcentrationCalculatorTest extends TestCase
{
    private const USD_TO_EUR = 0.5;

    /**
     * Cada especificacion es [ticker, cantidad, precio actual, divisa].
     * El valor en euros de las posiciones en divisa extranjera se prepara
     * igual que lo hace PortfolioService::getPortfolio() con el tipo de
     * cambio de hoy; con $usdToEurRate null queda a null, que es lo que
     * ocurre cuando ExchangeRateService no puede responder.
     *
     * @param list<array{0: string, 1: float, 2: float, 3: string}> $specs
     */
    private function portfolio(array $specs, ?float $usdToEurRate = self::USD_TO_EUR): Portfolio
    {
        $holdings = [];
        $prices = [];
        $currencies = [];

        foreach ($specs as [$ticker, $quantity, $price, $currency]) {
            $marketValueEur = ($currency === 'EUR' || $usdToEurRate === null)
                ? null
                : $quantity * $price * $usdToEurRate;

            $holdings[] = new Holding($ticker, $quantity, $price, $price, null, null, $marketValueEur);
            $prices[$ticker] = $price;
            $currencies[$ticker] = $currency;
        }

        return new Portfolio($holdings, [], 0.0, $prices, $currencies, $usdToEurRate);
    }

    private function calculator(): PortfolioConcentrationCalculator
    {
        return new PortfolioConcentrationCalculator();
    }

    public function testLosPesosDeCadaCriterioSumanCien(): void
    {
        $portfolio = $this->portfolio([
            ['MSA', 10.0, 100.0, 'USD'],
            ['TRV', 5.0, 200.0, 'USD'],
            ['REP.MC', 40.0, 15.0, 'EUR'],
        ]);

        $concentration = $this->calculator()->compute($portfolio, [
            'MSA' => 'Industrials',
            'TRV' => 'Financial Services',
            'REP.MC' => 'Energy',
        ]);

        self::assertNotNull($concentration);
        self::assertEqualsWithDelta(100.0, array_sum($concentration->getPositionWeights()), 0.0001);
        self::assertEqualsWithDelta(100.0, array_sum($concentration->getSectorWeights()), 0.0001);
        self::assertEqualsWithDelta(100.0, array_sum($concentration->getCurrencyWeights()), 0.0001);
        // 1.000 $ + 1.000 $ -> 500 € + 500 €; mas 600 € nativos: 1.600 € en total.
        self::assertEqualsWithDelta(1600.0, $concentration->getTotalValueEur(), 0.0001);
    }

    public function testLosPesosPorPosicionVienenEnOrdenDescendente(): void
    {
        $portfolio = $this->portfolio([
            ['AAA', 1.0, 100.0, 'EUR'],
            ['BBB', 1.0, 300.0, 'EUR'],
            ['CCC', 1.0, 200.0, 'EUR'],
        ]);

        $concentration = $this->calculator()->compute($portfolio);

        self::assertNotNull($concentration);
        self::assertSame(['BBB', 'CCC', 'AAA'], array_keys($concentration->getPositionWeights()));
        self::assertEqualsWithDelta(83.3333, $concentration->getTopPositionsWeight(2), 0.0001);
    }

    public function testCuatroPosicionesIgualesDanHhiDeUnCuartoYCuatroPosicionesEfectivas(): void
    {
        $portfolio = $this->portfolio([
            ['AAA', 1.0, 100.0, 'EUR'],
            ['BBB', 1.0, 100.0, 'EUR'],
            ['CCC', 1.0, 100.0, 'EUR'],
            ['DDD', 1.0, 100.0, 'EUR'],
        ]);

        $concentration = $this->calculator()->compute($portfolio);

        self::assertNotNull($concentration);
        self::assertEqualsWithDelta(0.25, $concentration->getHerfindahlIndex(), 0.0001);
        self::assertEqualsWithDelta(4.0, $concentration->getEffectivePositions(), 0.0001);
        self::assertSame(4, $concentration->getPositionCount());
    }

    /**
     * Sin convertir, la posicion en dolares (1.000 $) y la posicion en
     * euros (1.000 €) pesarian un 50% cada una. Con el cambio aplicado
     * (0,5) la de dolares vale 500 € y debe pesar un tercio.
     */
    public function testLaConversionAEurosSeAplicaAntesDePesar(): void
    {
        $portfolio = $this->portfolio([
            ['USDCO', 10.0, 100.0, 'USD'],
            ['EURCO', 10.0, 100.0, 'EUR'],
        ]);

        $concentration = $this->calculator()->compute($portfolio);

        self::assertNotNull($concentration);
        self::assertEqualsWithDelta(33.3333, $concentration->getPositionWeights()['USDCO'], 0.0001);
        self::assertEqualsWithDelta(66.6667, $concentration->getPositionWeights()['EURCO'], 0.0001);
        self::assertEqualsWithDelta(33.3333, $concentration->getCurrencyWeights()['USD'], 0.0001);
        self::assertEqualsWithDelta(66.6667, $concentration->getCurrencyWeights()['EUR'], 0.0001);
    }

    public function testSinTipoDeCambioConPosicionesEnDolaresDevuelveNull(): void
    {
        $portfolio = $this->portfolio([
            ['USDCO', 10.0, 100.0, 'USD'],
            ['EURCO', 10.0, 100.0, 'EUR'],
        ], null);

        self::assertNull($this->calculator()->compute($portfolio));
    }

    public function testCarteraVaciaDevuelveNull(): void
    {
        self::assertNull($this->calculator()->compute($this->portfolio([])));
    }

    public function testPosicionSinPrecioActualDevuelveNull(): void
    {
        $holding = new Holding('EURCO', 10.0, 100.0, null, 'Precio no disponible');
        $portfolio = new Portfolio([$holding], [], 0.0, ['EURCO' => null], ['EURCO' => 'EUR'], null);

        self::assertNull($this->calculator()->compute($portfolio));
    }

    public function testLosPesosPorSectorAgrupanLasPosicionesDelMismoSector(): void
    {
        $portfolio = $this->portfolio([
            ['AAA', 1.0, 300.0, 'EUR'],
            ['BBB', 1.0, 100.0, 'EUR'],
            ['CCC', 1.0, 100.0, 'EUR'],
        ]);

        $concentration = $this->calculator()->compute($portfolio, [
            'AAA' => 'Technology',
            'BBB' => 'Technology',
            'CCC' => 'Energy',
        ]);

        self::assertNotNull($concentration);
        self::assertEqualsWithDelta(80.0, $concentration->getSectorWeights()['Technology'], 0.0001);
        self::assertEqualsWithDelta(20.0, $concentration->getSectorWeights()['Energy'], 0.0001);
    }

    /**
     * Un ticker sin sector conocido no puede desaparecer del reparto: se
     * agrupa aparte para que los pesos por sector sigan sumando 100%.
     */
    public function testTickerSinSectorSeAgrupaEnSinSector(): void
    {
        $portfolio = $this->portfolio([
            ['AAA', 1.0, 100.0, 'EUR'],
            ['BBB', 1.0, 100.0, 'EUR'],
        ]);

        $concentration = $this->calculator()->compute($portfolio, ['AAA' => 'Energy']);

        self::assertNotNull($concentration);
        self::assertEqualsWithDelta(
            50.0,
            $concentration->getSectorWeights()[PortfolioConcentration::UNKNOWN_SECTOR],
            0.0001
        );
        self::assertEqualsWithDelta(100.0, array_sum($concentration->getSectorWeights()), 0.0001);
    }

    public function testAvisosDeConcentracionPorPosicionSectorYDivisa(): void
    {
        $portfolio = $this->portfolio([
            ['USDCO', 10.0, 160.0, 'USD'],
            ['EURCO', 1.0, 200.0, 'EUR'],
        ]);

        $concentration = $this->calculator()->compute($portfolio, [
            'USDCO' => 'Technology',
            'EURCO' => 'Energy',
        ]);

        self::assertNotNull($concentration);
        // 800 $ -> 400 EUR (80%) frente a 200 EUR nativos (20%).
        self::assertSame(['USDCO'], array_keys($concentration->getOverweightPositions()));
        self::assertSame(['Technology'], array_keys($concentration->getOverweightSectors()));
        self::assertSame(['USD'], array_keys($concentration->getOverweightForeignCurrencies()));
    }

    /**
     * "Sin sector" no es un sector, es la ausencia del dato: aunque supere el
     * umbral no debe generar aviso (v2.85). Antes, una cartera con la mayoria
     * de posiciones sin sector conocido avisaba de estar "concentrada" en un
     * grupo que solo significa que el proveedor no devolvio el dato, lo que
     * suena a un riesgo medido cuando es justo lo contrario.
     *
     * Sigue contando en getSectorWeights() y por tanto en el HHI: lo que se
     * suprime es el veredicto, no el dato.
     */
    public function testElGrupoSinSectorNoGeneraAvisoDeConcentracionSectorial(): void
    {
        $portfolio = $this->portfolio([
            ['SINSEC1', 1.0, 500.0, 'EUR'],
            ['SINSEC2', 1.0, 300.0, 'EUR'],
            ['CONSEC', 1.0, 200.0, 'EUR'],
        ]);

        $concentration = $this->calculator()->compute($portfolio, [
            'SINSEC1' => '',
            'SINSEC2' => '',
            'CONSEC' => 'Energy',
        ]);

        self::assertNotNull($concentration);
        // El 80% del valor esta sin sector conocido...
        self::assertEqualsWithDelta(
            80.0,
            $concentration->getSectorWeights()[PortfolioConcentration::UNKNOWN_SECTOR],
            0.0001
        );
        // ...pero eso no es un aviso de concentracion sectorial.
        self::assertSame([], $concentration->getOverweightSectors());
    }

    /**
     * El aviso de divisa solo aplica a la exposicion en divisa distinta del
     * euro: una cartera integramente en euros esta al 100% en EUR y eso no
     * es ningun riesgo de cambio.
     */
    public function testCarteraSoloEnEurosNoGeneraAvisoDeDivisa(): void
    {
        $portfolio = $this->portfolio([
            ['AAA', 1.0, 100.0, 'EUR'],
            ['BBB', 1.0, 100.0, 'EUR'],
        ]);

        $concentration = $this->calculator()->compute($portfolio);

        self::assertNotNull($concentration);
        self::assertEqualsWithDelta(100.0, $concentration->getCurrencyWeights()['EUR'], 0.0001);
        self::assertSame([], $concentration->getOverweightForeignCurrencies());
    }
}
