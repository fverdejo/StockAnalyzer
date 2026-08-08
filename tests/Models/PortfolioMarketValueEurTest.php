<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Models;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;

/**
 * Portfolio::getMarketValue() suma divisas nativas sin convertir
 * (deliberado desde v2.25/v2.48: las metricas de rentabilidad nativas no
 * se tocan), asi que no sirve como valor "de la cartera" para nada que se
 * mida en euros. Estos casos fijan la semantica del valor en euros
 * anadido en v2.66: por posicion, el total, y el tipo de cambio por
 * ticker.
 */
final class PortfolioMarketValueEurTest extends TestCase
{
    private const USD_TO_EUR = 0.8;

    /**
     * Cada especificacion es [ticker, cantidad, precio actual, divisa].
     * El valor en euros de las posiciones en divisa extranjera se prepara
     * igual que lo hace PortfolioService::getPortfolio() con el tipo de
     * cambio de hoy, y a las que ya cotizan en euros se les deja a null
     * (v2.48, para no duplicar el mismo importe en la UI).
     *
     * @param list<array{0: string, 1: float, 2: float, 3: string}> $specs
     * @param array<string,?float> $ratesToEur divisa => tipo de cambio de hoy
     */
    private function portfolio(array $specs, array $ratesToEur = ['USD' => self::USD_TO_EUR]): Portfolio
    {
        $holdings = [];
        $prices = [];
        $currencies = [];

        foreach ($specs as [$ticker, $quantity, $price, $currency]) {
            $rate = $ratesToEur[$currency] ?? null;
            $marketValueEur = ($currency === 'EUR' || $rate === null)
                ? null
                : $quantity * $price * $rate;

            $holdings[] = new Holding($ticker, $quantity, $price, $price, null, null, $marketValueEur);
            $prices[$ticker] = $price;
            $currencies[$ticker] = $currency;
        }

        return new Portfolio($holdings, [], 0.0, $prices, $currencies, $ratesToEur['USD'] ?? null, $ratesToEur);
    }

    public function testUnaCarteraSoloEnEurosValeLaSumaDeSusValoresNativos(): void
    {
        $portfolio = $this->portfolio([
            ['ELE.MC', 10.0, 42.24, 'EUR'],
            ['REP.MC', 20.0, 15.0, 'EUR'],
        ]);

        self::assertEqualsWithDelta(722.4, $portfolio->getMarketValueEur(), 0.0001);
        self::assertEqualsWithDelta(722.4, $portfolio->getMarketValue(), 0.0001);
    }

    public function testUnaCarteraMixtaConvierteLasPosicionesExtranjerasAntesDeSumar(): void
    {
        $portfolio = $this->portfolio([
            ['EURCO', 10.0, 100.0, 'EUR'],
            ['USDCO', 10.0, 100.0, 'USD'],
        ]);

        // 1.000 € nativos + 1.000 $ -> 800 €. La suma nativa (2.000) es
        // la que devuelve getMarketValue(), y por eso no vale como valor
        // de la cartera en euros.
        self::assertEqualsWithDelta(1800.0, $portfolio->getMarketValueEur(), 0.0001);
        self::assertEqualsWithDelta(2000.0, $portfolio->getMarketValue(), 0.0001);
        self::assertEqualsWithDelta(1000.0, $portfolio->getMarketValueEurFor('EURCO'), 0.0001);
        self::assertEqualsWithDelta(800.0, $portfolio->getMarketValueEurFor('USDCO'), 0.0001);
    }

    public function testSinTipoDeCambioUnaPosicionExtranjeraDejaElTotalEnNull(): void
    {
        $portfolio = $this->portfolio([
            ['EURCO', 10.0, 100.0, 'EUR'],
            ['USDCO', 10.0, 100.0, 'USD'],
        ], ['USD' => null]);

        self::assertNull($portfolio->getMarketValueEurFor('USDCO'));
        self::assertNull($portfolio->getMarketValueEur());
    }

    public function testUnaPosicionSinPrecioActualDejaElTotalEnNull(): void
    {
        $holding = new Holding('EURCO', 10.0, 100.0, null, 'Precio no disponible');
        $portfolio = new Portfolio([$holding], [], 0.0, ['EURCO' => null], ['EURCO' => 'EUR']);

        self::assertNull($portfolio->getMarketValueEurFor('EURCO'));
        self::assertNull($portfolio->getMarketValueEur());
    }

    public function testUnaDivisaDesconocidaDejaElTotalEnNull(): void
    {
        $holding = new Holding('RARO', 10.0, 100.0, 100.0);
        $portfolio = new Portfolio([$holding], [], 0.0, ['RARO' => 100.0], []);

        self::assertSame('', $portfolio->getCurrencyFor('RARO'));
        self::assertNull($portfolio->getMarketValueEurFor('RARO'));
        self::assertNull($portfolio->getMarketValueEur());
    }

    public function testElTipoDeCambioDeUnTickerEnEurosEsUno(): void
    {
        $portfolio = $this->portfolio([
            ['EURCO', 10.0, 100.0, 'EUR'],
            ['USDCO', 10.0, 100.0, 'USD'],
        ]);

        self::assertSame(1.0, $portfolio->getRateToEurFor('EURCO'));
        self::assertEqualsWithDelta(self::USD_TO_EUR, $portfolio->getRateToEurFor('USDCO'), 0.0001);
    }

    public function testSinTipoDeCambioODivisaConocidaNoHayCambioQueDevolver(): void
    {
        $sinCambio = $this->portfolio([['USDCO', 10.0, 100.0, 'USD']], ['USD' => null]);
        $sinDivisa = new Portfolio([new Holding('RARO', 10.0, 100.0, 100.0)], [], 0.0, ['RARO' => 100.0], []);

        self::assertNull($sinCambio->getRateToEurFor('USDCO'));
        self::assertNull($sinDivisa->getRateToEurFor('RARO'));
    }

    public function testUnTickerQueNoEsPosicionAbiertaNoTieneValorEnEuros(): void
    {
        $portfolio = $this->portfolio([['EURCO', 10.0, 100.0, 'EUR']]);

        self::assertNull($portfolio->getMarketValueEurFor('NOEXISTE'));
    }

    public function testUnaCarteraVaciaValeCero(): void
    {
        self::assertSame(0.0, $this->portfolio([])->getMarketValueEur());
    }
}
