<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Services\SuggestedPositionCalculator;

/**
 * El punto delicado de la cantidad sugerida es la divisa (ver versions.md
 * v2.66): el presupuesto de riesgo y el peso maximo por posicion son
 * propiedades de la CARTERA y se miden en euros, mientras que el precio y
 * el stop-loss son propiedades del INSTRUMENTO y estan en su divisa
 * nativa. Mezclar ambas cosas hacia que el mismo "1,5% por operacion"
 * fuese un 16% mas grande para un valor en euros que para uno en dolares.
 *
 * Estos casos fijan que dos posiciones equivalentes en euros reciben
 * sugerencias equivalentes en euros, independientemente de en que divisa
 * coticen.
 */
final class SuggestedPositionCalculatorTest extends TestCase
{
    private const USD_TO_EUR = 0.5;
    private const RISK_PERCENT = 1.5;
    private const MAX_POSITION_PERCENT = 20.0;

    /**
     * Cada especificacion es [ticker, cantidad, precio actual, divisa].
     * El valor en euros de las posiciones extranjeras se prepara igual que
     * lo hace PortfolioService::getPortfolio().
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

    /**
     * RiskLevels solo se construye desde compute(), asi que el stop-loss
     * se fija via ATR con multiplicador 1: stop = precio - ATR.
     */
    private function levels(float $price, float $stopLoss): RiskLevels
    {
        return RiskLevels::compute($price, $price - $stopLoss, 1.0, 2.0);
    }

    private function calculator(): SuggestedPositionCalculator
    {
        return new SuggestedPositionCalculator(
            new RiskLevelsConfig(2.5, 2.0, self::RISK_PERCENT, self::MAX_POSITION_PERCENT)
        );
    }

    /**
     * Cartera de 2.000 € (1.000 € nativos + 2.000 $ que son otros 1.000 €)
     * con dos posiciones cuyo precio y stop son equivalentes en euros:
     * 100 €/90 € y 200 $/180 $ con el cambio a 0,5. Ambas quedan acotadas
     * por el riesgo por operacion (15% de la cartera, por debajo del 20%).
     *
     * Antes de v2.66 el presupuesto era la suma mixta 1.000 + 2.000 =
     * 3.000, aplicada ademas contra precios nativos: la posicion en euros
     * recibia un 50% mas de presupuesto que la equivalente en dolares.
     */
    public function testDosPosicionesEquivalentesEnEurosRecibenLaMismaSugerenciaEnEuros(): void
    {
        $portfolio = $this->portfolio([
            ['EURCO', 10.0, 100.0, 'EUR'],
            ['USDCO', 10.0, 200.0, 'USD'],
        ]);

        $positions = $this->calculator()->compute($portfolio, [
            'EURCO' => $this->levels(100.0, 90.0),
            'USDCO' => $this->levels(200.0, 180.0),
        ]);

        $eur = $positions['EURCO'];
        $usd = $positions['USDCO'];

        self::assertNotNull($eur);
        self::assertNotNull($usd);
        self::assertFalse($eur->isLimitedByMaxWeight());
        self::assertFalse($usd->isLimitedByMaxWeight());

        // Mismo valor en euros: 3 x 100 € = 300 € y 3 x 200 $ = 600 $ = 300 €.
        self::assertEqualsWithDelta(3.0, $eur->getQuantity(), 0.000001);
        self::assertEqualsWithDelta(3.0, $usd->getQuantity(), 0.000001);
        self::assertEqualsWithDelta(300.0, $eur->getQuantity() * 100.0, 0.000001);
        self::assertEqualsWithDelta(300.0, $usd->getQuantity() * 200.0 * self::USD_TO_EUR, 0.000001);

        // Y el mismo riesgo real: 1,5% de los 2.000 € de la cartera.
        self::assertEqualsWithDelta(30.0, $eur->getQuantity() * (100.0 - 90.0), 0.000001);
        self::assertEqualsWithDelta(30.0, $usd->getQuantity() * (200.0 - 180.0) * self::USD_TO_EUR, 0.000001);
    }

    /**
     * Misma cartera de 2.000 €, pero con stops muy ajustados: manda el
     * peso maximo por posicion, y en las dos divisas debe dar exactamente
     * el 20% del valor en euros de la cartera (400 €), no un 21,55% para
     * la posicion en euros y un 18,64% para la de dolares como ocurria
     * antes de v2.66.
     */
    public function testLaSugerenciaAcotadaPorPesoEsElMismoPorcentajeEnLasDosDivisas(): void
    {
        $portfolio = $this->portfolio([
            ['EURCO', 10.0, 100.0, 'EUR'],
            ['USDCO', 10.0, 200.0, 'USD'],
        ]);

        $positions = $this->calculator()->compute($portfolio, [
            'EURCO' => $this->levels(100.0, 99.0),
            'USDCO' => $this->levels(200.0, 198.0),
        ]);

        $eur = $positions['EURCO'];
        $usd = $positions['USDCO'];

        self::assertNotNull($eur);
        self::assertNotNull($usd);
        self::assertTrue($eur->isLimitedByMaxWeight());
        self::assertTrue($usd->isLimitedByMaxWeight());
        self::assertEqualsWithDelta(400.0, $eur->getQuantity() * 100.0, 0.000001);
        self::assertEqualsWithDelta(400.0, $usd->getQuantity() * 200.0 * self::USD_TO_EUR, 0.000001);
        self::assertSame(self::MAX_POSITION_PERCENT, $eur->getMaxPositionPercent());
    }

    /**
     * Fixture con los datos reales medidos el 2026-08-08 sobre la cartera
     * del usuario (2.025,44 €, USD->EUR 0,8649): ADBE queda acotado por el
     * riesgo por operacion (exactamente 30,38 € = 1,50%) y ELE.MC por el
     * peso maximo (exactamente 405,09 € = 20,00%).
     */
    public function testFixtureRealMezclandoUnValorEnDolaresYOtroEnEuros(): void
    {
        $portfolioValueEur = 2025.44;
        $usdToEur = 0.8649;
        $eleQuantity = 10.0;
        // El resto del valor de la cartera se pone en ADBE para que el
        // total en euros sea exactamente el medido.
        $adbeQuantity = ($portfolioValueEur - ($eleQuantity * 42.24)) / (265.21 * $usdToEur);

        $portfolio = $this->portfolio([
            ['ADBE', $adbeQuantity, 265.21, 'USD'],
            ['ELE.MC', $eleQuantity, 42.24, 'EUR'],
        ], ['USD' => $usdToEur]);

        self::assertEqualsWithDelta($portfolioValueEur, $portfolio->getMarketValueEur(), 0.000001);

        $positions = $this->calculator()->compute($portfolio, [
            'ADBE' => $this->levels(265.21, 236.65),
            'ELE.MC' => $this->levels(42.24, 40.25),
        ]);

        $adbe = $positions['ADBE'];
        $ele = $positions['ELE.MC'];

        self::assertNotNull($adbe);
        self::assertNotNull($ele);

        self::assertFalse($adbe->isLimitedByMaxWeight());
        self::assertEqualsWithDelta(1.230, $adbe->getQuantity(), 0.001);
        self::assertEqualsWithDelta(30.38, $adbe->getQuantity() * (265.21 - 236.65) * $usdToEur, 0.01);

        self::assertTrue($ele->isLimitedByMaxWeight());
        self::assertEqualsWithDelta(9.590, $ele->getQuantity(), 0.001);
        self::assertEqualsWithDelta(405.09, $ele->getQuantity() * 42.24, 0.01);
    }

    /**
     * Denominador, "todo o nada": sin valor de cartera en euros no hay
     * presupuesto que repartir entre NINGUNA posicion, porque ese total es
     * el de todas. Mismo criterio que ya se aplicaba cuando faltaba el
     * precio de una sola posicion.
     */
    public function testSinValorDeCarteraEnEurosNoSeSugiereNada(): void
    {
        $portfolio = $this->portfolio([
            ['EURCO', 10.0, 100.0, 'EUR'],
            ['USDCO', 10.0, 200.0, 'USD'],
        ], ['USD' => null]);

        self::assertSame([], $this->calculator()->compute($portfolio, [
            'EURCO' => $this->levels(100.0, 90.0),
            'USDCO' => $this->levels(200.0, 180.0),
        ]));
    }

    /**
     * Numerador, por ticker: modo de fallo independiente del anterior. Si
     * el total en euros existe pero no se puede llevar a la divisa de un
     * ticker concreto, solo esa fila se queda sin sugerencia; las demas
     * conservan la suya.
     */
    public function testSinTipoDeCambioDeUnTickerConcretoSoloEsaPosicionSeQuedaSinSugerencia(): void
    {
        $holdings = [
            new Holding('EURCO', 10.0, 100.0, 100.0),
            new Holding('GBPCO', 10.0, 100.0, 100.0, null, null, 1000.0),
        ];
        $portfolio = new Portfolio(
            $holdings,
            [],
            0.0,
            ['EURCO' => 100.0, 'GBPCO' => 100.0],
            ['EURCO' => 'EUR', 'GBPCO' => 'GBP'],
            null,
            ['GBP' => null]
        );

        $positions = $this->calculator()->compute($portfolio, [
            'EURCO' => $this->levels(100.0, 90.0),
            'GBPCO' => $this->levels(100.0, 90.0),
        ]);

        self::assertEqualsWithDelta(2000.0, $portfolio->getMarketValueEur(), 0.000001);
        self::assertNull($positions['GBPCO']);
        self::assertNotNull($positions['EURCO']);
        self::assertEqualsWithDelta(3.0, $positions['EURCO']->getQuantity(), 0.000001);
    }

    /**
     * Una posicion sin stop-loss calculado (fallo puntual del analisis de
     * ese ticker) no impide sugerir cantidad para el resto.
     */
    public function testUnaPosicionSinNivelesDeRiesgoSeQuedaSinSugerencia(): void
    {
        $portfolio = $this->portfolio([
            ['EURCO', 10.0, 100.0, 'EUR'],
            ['USDCO', 10.0, 200.0, 'USD'],
        ]);

        $positions = $this->calculator()->compute($portfolio, [
            'EURCO' => $this->levels(100.0, 90.0),
            'USDCO' => null,
        ]);

        self::assertNull($positions['USDCO']);
        self::assertNotNull($positions['EURCO']);
    }
}
