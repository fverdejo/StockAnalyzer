<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\FiscalPeriod;
use StockAnalyzer\DTO\FiscalPeriodType;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Services\PointInTimeFundamentalsBuilder;

/**
 * Contrato de moneda por componente y periodo (C2, encargo de Astra del
 * 2026-09-21, `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`):
 *
 * - cuatro trimestres EUR/EUR/EUR/USD producian un ROE del 25% donde los
 *   mismos importes economicos en una base comun dan 40%;
 * - resultados y balance en GBP con el flujo de caja en USD daban una
 *   conversion de caja de 2 en vez de 1.
 *
 * Sin una conversion fechada acreditada el ratio afectado queda NO
 * evaluable (con motivo interno) y los ratios comparables se conservan. Los
 * controles GBP/GBX y precio incompatible siguen en
 * `PointInTimeFundamentalsBuilderTest`.
 */
final class PointInTimeFundamentalsBuilderCurrencyTest extends TestCase
{
    /**
     * Un trimestre con economia fija: ROE TTM = 4 x 10 / 100 = 40%,
     * FCF/beneficio = 4 x 8 / 40 = 0,8, deuda/patrimonio 0,5, current ratio 2.
     */
    private function quarter(string $endDate, ?string $income = null, ?string $balance = null, ?string $cashFlow = null, float $netIncome = 10.0): FiscalPeriod
    {
        $filing = (new DateTimeImmutable($endDate))->modify('+30 days')->format('Y-m-d');

        return new FiscalPeriod(
            ticker: 'ACME',
            endDate: new DateTimeImmutable($endDate),
            filingDate: new DateTimeImmutable($filing),
            periodType: FiscalPeriodType::Quarterly,
            revenue: 100.0,
            grossProfit: 40.0,
            operatingIncome: 20.0,
            netIncome: $netIncome,
            ebitda: 25.0,
            ebit: 20.0,
            incomeBeforeTax: 15.0,
            incomeTaxExpense: 3.0,
            epsDiluted: 1.0,
            sharesDiluted: 10.0,
            totalStockholdersEquity: 100.0,
            totalDebt: 50.0,
            netDebt: 40.0,
            totalCurrentAssets: 200.0,
            totalCurrentLiabilities: 100.0,
            freeCashFlow: 8.0,
            commonDividendsPaid: 2.0,
            statementCurrency: $balance,
            incomeCurrency: $income,
            balanceCurrency: $balance,
            cashFlowCurrency: $cashFlow
        );
    }

    /**
     * @param list<?string> $incomeCurrencies uno por trimestre, del mas antiguo al mas reciente
     * @return list<FiscalPeriod>
     */
    private function year(array $incomeCurrencies, ?string $balance = 'EUR', ?string $cashFlow = 'EUR', float $lastNetIncome = 10.0): array
    {
        $ends = ['2025-03-31', '2025-06-30', '2025-09-30', '2025-12-31'];
        $periods = [];

        foreach ($ends as $i => $end) {
            $periods[] = $this->quarter($end, $incomeCurrencies[$i], $balance, $cashFlow, $i === 3 ? $lastNetIncome : 10.0);
        }

        return $periods;
    }

    /**
     * @param list<FiscalPeriod> $periods
     * @return array{fundamentals: Fundamentals, not_evaluable: array<string, string>}
     */
    private function build(array $periods, ?string $priceCurrency = null, float $price = 100.0): array
    {
        $result = (new PointInTimeFundamentalsBuilder($periods, $priceCurrency))->buildWithReasons(new DateTimeImmutable('2026-06-01'), $price);
        self::assertNotNull($result['fundamentals']);

        return ['fundamentals' => $result['fundamentals'], 'not_evaluable' => $result['not_evaluable']];
    }

    public function testLaMismaEconomiaEnUnaSolaMonedaDaElRoeDeSiempre(): void
    {
        $result = $this->build($this->year(['EUR', 'EUR', 'EUR', 'EUR']));

        self::assertEqualsWithDelta(40.0, $result['fundamentals']->getRoe(), 1e-9);
        self::assertEqualsWithDelta(0.8, $result['fundamentals']->getCashConversion(), 1e-9);
        self::assertSame([], $result['not_evaluable']);
    }

    /** Caso de Astra: EUR/EUR/EUR/USD -> antes ROE 25% (importes de dos monedas sumados); ahora no evaluable. */
    public function testUnaVentanaTtmConCuatroTrimestresEurEurEurUsdNoCalculaElRoe(): void
    {
        // El cuarto trimestre, en USD, con SU importe en USD (aqui 4): sumado tal cual dice 34/100.
        $mixed = $this->build($this->year(['EUR', 'EUR', 'EUR', 'USD'], lastNetIncome: 4.0));

        self::assertNull($mixed['fundamentals']->getRoe(), 'No se suman importes de monedas distintas.');
        self::assertNull($mixed['fundamentals']->getRoic());
        self::assertNull($mixed['fundamentals']->getNetMargin());
        self::assertNull($mixed['fundamentals']->getEps());
        self::assertArrayHasKey('roe', $mixed['not_evaluable']);
        self::assertStringContainsString('mezcla monedas conocidas distintas', $mixed['not_evaluable']['roe']);

        // Ratios COMPARABLES conservados: solo dependen del balance del periodo vigente.
        self::assertEqualsWithDelta(0.5, $mixed['fundamentals']->getDebtToEquity(), 1e-9);
        self::assertEqualsWithDelta(2.0, $mixed['fundamentals']->getCurrentRatio(), 1e-9);
        // El flujo de caja (EUR en los cuatro) no se ve afectado por la moneda de resultados... salvo
        // la conversion de caja, que compara flujo con resultados.
        self::assertEqualsWithDelta(32.0, $mixed['fundamentals']->getFreeCashFlow(), 1e-9);
        self::assertNull($mixed['fundamentals']->getCashConversion());
    }

    /** Misma economia "en base comun" (todo en EUR) -> mismo ratio que la referencia; mezclada -> null, no un numero distinto. */
    public function testLaMismaEconomiaEnBaseComunDaElMismoRatioYLaMezclaNoInventaOtro(): void
    {
        $common = $this->build($this->year(['EUR', 'EUR', 'EUR', 'EUR'], lastNetIncome: 10.0));
        $mixed = $this->build($this->year(['EUR', 'EUR', 'EUR', 'USD'], lastNetIncome: 4.0));

        self::assertEqualsWithDelta(40.0, $common['fundamentals']->getRoe(), 1e-9);
        self::assertNull($mixed['fundamentals']->getRoe());
    }

    /** Caso de Astra: resultados y balance en GBP, flujo de caja en USD -> conversion de caja no evaluable. */
    public function testFlujoDeCajaEnOtraMonedaQueLosResultadosDejaLaConversionDeCajaNoEvaluable(): void
    {
        $same = $this->build($this->year(['GBP', 'GBP', 'GBP', 'GBP'], 'GBP', 'GBP'));
        $mixed = $this->build($this->year(['GBP', 'GBP', 'GBP', 'GBP'], 'GBP', 'USD'));

        self::assertEqualsWithDelta(0.8, $same['fundamentals']->getCashConversion(), 1e-9);
        self::assertNull($mixed['fundamentals']->getCashConversion());
        self::assertStringContainsString('flujo de caja (USD) y resultados (GBP)', $mixed['not_evaluable']['cashConversion']);
        // El ROE (resultados vs balance, ambos GBP) sigue siendo valido.
        self::assertEqualsWithDelta(40.0, $mixed['fundamentals']->getRoe(), 1e-9);
        // El payout tambien mezcla flujo de caja (dividendos) con resultados (EPS).
        self::assertNull($mixed['fundamentals']->getPayoutRatio());
    }

    public function testResultadosYBalanceEnMonedasDistintasNoCalculanRoeRoicNiEvEbitda(): void
    {
        $result = $this->build($this->year(['EUR', 'EUR', 'EUR', 'EUR'], 'USD', 'USD'), priceCurrency: 'USD');

        self::assertNull($result['fundamentals']->getRoe());
        self::assertNull($result['fundamentals']->getRoic());
        self::assertNull($result['fundamentals']->getEvToEbitda());
        self::assertStringContainsString('resultados (EUR) y balance (USD)', $result['not_evaluable']['roe']);
        // Comparables: margenes (todo resultados) y deuda/patrimonio (todo balance).
        self::assertEqualsWithDelta(10.0, $result['fundamentals']->getNetMargin(), 1e-9);
        self::assertEqualsWithDelta(0.5, $result['fundamentals']->getDebtToEquity(), 1e-9);
    }

    public function testUnaMonedaDesconocidaSeAsumeCompatibleIgualQueAntes(): void
    {
        $result = $this->build($this->year([null, 'EUR', null, 'EUR']));

        self::assertEqualsWithDelta(40.0, $result['fundamentals']->getRoe(), 1e-9);
        self::assertSame([], $result['not_evaluable']);
    }

    public function testLaSubunidadGbxYGbpSonLaMismaMonedaBase(): void
    {
        $result = $this->build($this->year(['GBP', 'GBX', 'GBP', 'GBP'], 'GBP', 'GBP'));

        self::assertEqualsWithDelta(40.0, $result['fundamentals']->getRoe(), 1e-9);
    }

    /** Crecimiento YoY: si la moneda de resultados cambio entre el TTM actual y el de hace un año, no se calcula. */
    public function testElCrecimientoNoMezclaMonedasEntreElTtmActualYElAnterior(): void
    {
        $older = [];

        foreach (['2024-03-31', '2024-06-30', '2024-09-30', '2024-12-31'] as $end) {
            $older[] = $this->quarter($end, 'USD', 'EUR', 'EUR', 5.0);
        }

        $periods = array_merge($older, $this->year(['EUR', 'EUR', 'EUR', 'EUR']));
        $result = $this->build($periods);

        self::assertNull($result['fundamentals']->getRevenueGrowth());
        self::assertNull($result['fundamentals']->getPeg());
        self::assertStringContainsString('cambio entre el TTM actual y el de hace un año', $result['not_evaluable']['revenueGrowth']);
        // Sin el cambio de moneda, el crecimiento SI se calcula (mismos ingresos: 0%).
        $sameCurrency = [];

        foreach (['2024-03-31', '2024-06-30', '2024-09-30', '2024-12-31'] as $end) {
            $sameCurrency[] = $this->quarter($end, 'EUR', 'EUR', 'EUR', 5.0);
        }

        $ok = $this->build(array_merge($sameCurrency, $this->year(['EUR', 'EUR', 'EUR', 'EUR'])));
        self::assertEqualsWithDelta(0.0, $ok['fundamentals']->getRevenueGrowth(), 1e-9);
    }

    public function testElPrecioSeComparaConLaMonedaDelEstadoDelQueSaleCadaCifra(): void
    {
        // Cotiza en USD; resultados EUR (PER no comparable), balance y flujo USD (capitalizacion y
        // rentabilidad por dividendo comparables).
        $periods = $this->year(['EUR', 'EUR', 'EUR', 'EUR'], 'USD', 'USD');
        $result = $this->build($periods, priceCurrency: 'USD');

        self::assertNull($result['fundamentals']->getPer(), 'EPS en EUR frente a un precio en USD.');
        self::assertNull($result['fundamentals']->getEarningsYield());
        self::assertStringContainsString('cotizacion (USD) y resultados (EUR)', $result['not_evaluable']['per']);
        self::assertNotNull($result['fundamentals']->getMarketCap(), 'La capitalizacion sale de acciones x precio en la moneda del balance (USD): comparable.');
        self::assertNotNull($result['fundamentals']->getPriceToBook());
        self::assertNotNull($result['fundamentals']->getDividendYield());
    }

    public function testElCambioDeMonedaDelFlujoDeCajaInvalidaElCrecimientoDelDividendo(): void
    {
        $periods = [];

        foreach (['2023-03-31', '2023-06-30', '2023-09-30', '2023-12-31'] as $end) {
            $periods[] = $this->quarter($end, 'EUR', 'EUR', 'USD');
        }

        foreach (['2024-03-31', '2024-06-30', '2024-09-30', '2024-12-31'] as $end) {
            $periods[] = $this->quarter($end, 'EUR', 'EUR', 'EUR');
        }

        $mixed = $this->build($periods);

        self::assertNull($mixed['fundamentals']->getDividendGrowth5y());

        $same = [];

        foreach (['2023-03-31', '2023-06-30', '2023-09-30', '2023-12-31', '2024-03-31', '2024-06-30', '2024-09-30', '2024-12-31'] as $end) {
            $same[] = $this->quarter($end, 'EUR', 'EUR', 'EUR');
        }

        self::assertNotNull($this->build($same)['fundamentals']->getDividendGrowth5y());
    }

    public function testBuildForSigueDevolviendoLosMismosFundamentalesQueBuildWithReasons(): void
    {
        $periods = $this->year(['EUR', 'EUR', 'EUR', 'USD'], lastNetIncome: 4.0);
        $builder = new PointInTimeFundamentalsBuilder($periods);
        $date = new DateTimeImmutable('2026-06-01');

        self::assertEquals($builder->buildWithReasons($date, 100.0)['fundamentals'], $builder->buildFor($date, 100.0));
    }
}
