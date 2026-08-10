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
     * por el riesgo por operacion (15% de peso, por debajo del 20%).
     *
     * Desde v2.83 la base de cada una es la cartera SIN ella, o sea los otros
     * 1.000 €, y la cantidad es la que deja el riesgo en 1,5% de la cartera
     * resultante: 1000*1,5 / (100*10 - 1,5*100) = 1,7647 acciones.
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

        // Mismo valor en euros: 1,7647 x 100 € = 176,47 € y 1,7647 x 200 $ =
        // 352,94 $ = 176,47 €.
        $expected = (1000.0 * 1.5) / ((100 * 10.0) - (1.5 * 100.0));

        self::assertEqualsWithDelta(1.764706, $expected, 0.000001);
        self::assertEqualsWithDelta($expected, $eur->getQuantity(), 0.000001);
        self::assertEqualsWithDelta($expected, $usd->getQuantity(), 0.000001);
        self::assertEqualsWithDelta(176.470588, $eur->getQuantity() * 100.0, 0.000001);
        self::assertEqualsWithDelta(176.470588, $usd->getQuantity() * 200.0 * self::USD_TO_EUR, 0.000001);

        // Y el mismo riesgo real, que es exactamente el 1,5% de la cartera
        // RESULTANTE de comprar la cantidad sugerida (1.000 € de la otra
        // posicion + 176,47 € de esta): la propiedad que hace estable la
        // sugerencia (v2.83).
        $riskEur = $eur->getQuantity() * (100.0 - 90.0);

        self::assertEqualsWithDelta(17.647059, $riskEur, 0.000001);
        self::assertEqualsWithDelta(
            1.5,
            ($riskEur / (1000.0 + ($eur->getQuantity() * 100.0))) * 100,
            0.000001
        );
        self::assertEqualsWithDelta(
            $riskEur,
            $usd->getQuantity() * (200.0 - 180.0) * self::USD_TO_EUR,
            0.000001
        );
    }

    /**
     * Misma cartera de 2.000 €, pero con stops muy ajustados: manda el peso
     * maximo por posicion, y en las dos divisas debe dar exactamente el mismo
     * valor en euros (250 €), no un 21,55% para la posicion en euros y un
     * 18,64% para la de dolares como ocurria antes de v2.66.
     *
     * 250 € es el 20% de la cartera RESULTANTE (1.000 € de la otra posicion +
     * 250 € de esta = 1.250 €), no el 20% de los 2.000 € actuales: es el punto
     * fijo de v2.83, y por eso comprarlo no mueve la sugerencia.
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
        self::assertEqualsWithDelta(250.0, $eur->getQuantity() * 100.0, 0.000001);
        self::assertEqualsWithDelta(250.0, $usd->getQuantity() * 200.0 * self::USD_TO_EUR, 0.000001);
        self::assertEqualsWithDelta(20.0, (250.0 / (1000.0 + 250.0)) * 100, 0.000001);
        self::assertSame(self::MAX_POSITION_PERCENT, $eur->getMaxPositionPercent());
    }

    /**
     * Fixture con los datos reales medidos el 2026-08-08 sobre la cartera
     * del usuario (2.025,44 €, USD->EUR 0,8649): ADBE queda acotado por el
     * riesgo por operacion y ELE.MC por el peso maximo.
     *
     * Los importes son los de v2.83, medidos contra la cartera SIN la propia
     * posicion. Se ve bien la consecuencia honesta del cambio: ADBE es el 79%
     * de esta cartera, asi que su tamaño "correcto" respecto al 21% restante
     * es pequeño (0,298 acciones). Antes se le sugerian 1,23 acciones, que es
     * lo que salia de medir el 1,5% de un total que ADBE ya dominaba.
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

        $otherThanAdbeEur = $eleQuantity * 42.24;
        $otherThanEleEur = $portfolioValueEur - $otherThanAdbeEur;

        self::assertFalse($adbe->isLimitedByMaxWeight());
        self::assertEqualsWithDelta(0.298, $adbe->getQuantity(), 0.001);
        // El riesgo asumido es el 1,5% de la cartera resultante.
        $adbeRiskEur = $adbe->getQuantity() * (265.21 - 236.65) * $usdToEur;
        $adbeValueEur = $adbe->getQuantity() * 265.21 * $usdToEur;

        self::assertEqualsWithDelta(1.5, ($adbeRiskEur / ($otherThanAdbeEur + $adbeValueEur)) * 100, 0.0001);

        self::assertTrue($ele->isLimitedByMaxWeight());
        self::assertEqualsWithDelta(9.488, $ele->getQuantity(), 0.001);
        // Y ELE.MC pesa el 20% exacto de la cartera resultante.
        $eleValueEur = $ele->getQuantity() * 42.24;

        self::assertEqualsWithDelta(400.76, $eleValueEur, 0.01);
        self::assertEqualsWithDelta(20.0, ($eleValueEur / ($otherThanEleEur + $eleValueEur)) * 100, 0.0001);
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
        // Base de EURCO: los 1.000 € de GBPCO, o sea la cartera sin EURCO
        // (v2.83), aunque GBPCO no tenga cambio propio para su sugerencia.
        $expected = (1000.0 * 1.5) / ((100 * 10.0) - (1.5 * 100.0));

        self::assertEqualsWithDelta(1.764706, $expected, 0.000001);
        self::assertEqualsWithDelta($expected, $positions['EURCO']->getQuantity(), 0.000001);
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

    /**
     * Regresion de v2.84 sobre el arreglo de v2.83, al nivel del calculador y
     * no de la formula pura: el usuario puso en la base de datos exactamente
     * la cantidad sugerida y al recargar la pagina se le sugeria otra.
     *
     * Con los MISMOS precios, comprar lo sugerido no cambia la sugerencia de
     * esa posicion, porque su base es el valor de las otras. Se comprueba
     * ticker a ticker sobre una cartera de cuatro posiciones en dos divisas,
     * que es el caso real donde se reporto.
     *
     * Lo que si se mueve —y no es un fallo— son las sugerencias del RESTO de
     * posiciones: al cambiar el tamaño de una, cambia el valor del que las
     * demas son un porcentaje. Eso es inherente a dimensionar en relativo, y
     * este test lo deja explicito en vez de fingir que no pasa.
     */
    public function testComprarLaCantidadSugeridaNoCambiaEsaSugerencia(): void
    {
        $specs = [
            ['ADBE', 1.2953, 272.23, 'USD'],
            ['AMS.MC', 3.7722, 57.10, 'EUR'],
            ['BBVA.MC', 8.2338, 24.70, 'EUR'],
            ['EDU', 4.0209, 57.45, 'USD'],
        ];
        $levels = [];

        foreach ($specs as [$ticker, , $price]) {
            $levels[$ticker] = $this->levels($price, $price * 0.93);
        }

        $calculator = $this->calculator();
        $first = $calculator->compute($this->portfolio($specs), $levels);

        foreach ($specs as $index => [$ticker]) {
            $suggested = $first[$ticker];

            self::assertNotNull($suggested, "sin sugerencia inicial para $ticker");

            // Se "compra" exactamente lo sugerido para este ticker, con los
            // precios intactos, y se vuelve a preguntar.
            $afterBuying = $specs;
            $afterBuying[$index][1] = $suggested->getQuantity();
            $recomputed = $calculator->compute($this->portfolio($afterBuying), $levels)[$ticker];

            self::assertNotNull($recomputed, "sin sugerencia tras comprar $ticker");
            self::assertEqualsWithDelta(
                $suggested->getQuantity(),
                $recomputed->getQuantity(),
                0.000000001,
                "la sugerencia de $ticker se movio al comprarla"
            );
        }
    }
}
