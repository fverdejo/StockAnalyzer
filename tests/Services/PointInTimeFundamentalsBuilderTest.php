<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\FiscalPeriod;
use StockAnalyzer\DTO\FiscalPeriodType;
use StockAnalyzer\Services\PointInTimeFundamentalsBuilder;

/**
 * El nucleo del relleno historico (`v2.93`).
 *
 * El fallo que estos casos vigilan **no produce ningun error**: si el
 * constructor usa un ejercicio antes de que se publicara, el backtest sigue
 * funcionando y sale mejor de lo que fue. Por eso el primer bloque de
 * pruebas es sobre fechas y no sobre formulas.
 *
 * Las cifras de AAPL son las reales de su FY2025 (cierre 2025-09-27,
 * publicado 2025-10-31), tomadas de la respuesta de FMP, para que los
 * ratios esperados se puedan comprobar a mano contra los informes.
 */
final class PointInTimeFundamentalsBuilderTest extends TestCase
{
    private function periodo(
        string $endDate,
        string $filingDate,
        float $revenue = 416_161_000_000.0,
        float $netIncome = 112_010_000_000.0,
        ?float $dividendsPaid = -15_421_000_000.0
    ): FiscalPeriod {
        return new FiscalPeriod(
            ticker: 'AAPL',
            endDate: new DateTimeImmutable($endDate),
            filingDate: new DateTimeImmutable($filingDate),
            periodType: FiscalPeriodType::Annual,
            revenue: $revenue,
            grossProfit: 195_201_000_000.0,
            operatingIncome: 133_050_000_000.0,
            netIncome: $netIncome,
            ebitda: 144_427_000_000.0,
            ebit: 132_729_000_000.0,
            incomeBeforeTax: 132_729_000_000.0,
            incomeTaxExpense: 20_719_000_000.0,
            epsDiluted: 7.46,
            sharesDiluted: 15_004_697_000.0,
            totalStockholdersEquity: 73_733_000_000.0,
            totalDebt: 112_377_000_000.0,
            netDebt: 76_443_000_000.0,
            totalCurrentAssets: 147_957_000_000.0,
            totalCurrentLiabilities: 165_631_000_000.0,
            freeCashFlow: 98_767_000_000.0,
            commonDividendsPaid: $dividendsPaid
        );
    }

    // ---------------------------------------------------------------
    // 1. Fechas: la regla que da sentido a todo el trabajo
    // ---------------------------------------------------------------

    /**
     * Apple cerro su FY2025 el 27 de septiembre y lo publico el 31 de
     * octubre. Entre esas dos fechas, el mercado NO conocia esas cifras.
     * Usarlas ahi es el sesgo de anticipacion en su forma mas pura.
     */
    public function testUnEjercicioNoSeUsaAntesDeSuFechaDePublicacion(): void
    {
        $builder = new PointInTimeFundamentalsBuilder([
            $this->periodo('2025-09-27', '2025-10-31'),
        ]);

        self::assertNull(
            $builder->buildFor(new DateTimeImmutable('2025-10-15'), 250.0),
            'El ejercicio ya habia cerrado, pero todavia no se habia publicado.'
        );
        self::assertNotNull($builder->buildFor(new DateTimeImmutable('2025-10-31'), 250.0));
        self::assertNotNull($builder->buildFor(new DateTimeImmutable('2025-11-01'), 250.0));
    }

    /**
     * Con varios ejercicios publicados, manda el mas reciente **por fecha
     * de publicacion**.
     */
    public function testSeUsaElEjercicioPublicadoMasRecienteEnEsaFecha(): void
    {
        $builder = new PointInTimeFundamentalsBuilder([
            $this->periodo('2024-09-28', '2024-11-01', revenue: 391_035_000_000.0, netIncome: 93_736_000_000.0),
            $this->periodo('2025-09-27', '2025-10-31'),
        ]);

        // Antes de publicarse el FY2025, sigue vigente el FY2024.
        $antes = $builder->buildFor(new DateTimeImmutable('2025-10-30'), 250.0);
        $despues = $builder->buildFor(new DateTimeImmutable('2025-11-05'), 250.0);

        self::assertNotNull($antes);
        self::assertNotNull($despues);
        // El margen neto delata cual se uso: 93.736/391.035 vs 112.010/416.161.
        self::assertEqualsWithDelta(23.97, $antes->getNetMargin(), 0.01);
        self::assertEqualsWithDelta(26.91, $despues->getNetMargin(), 0.01);
    }

    public function testAntesDelPrimerEjercicioPublicadoNoHayDatos(): void
    {
        $builder = new PointInTimeFundamentalsBuilder([$this->periodo('2025-09-27', '2025-10-31')]);

        self::assertNull($builder->buildFor(new DateTimeImmutable('2020-01-01'), 250.0));
    }

    public function testSinEjerciciosNoHayNadaQueReconstruir(): void
    {
        $builder = new PointInTimeFundamentalsBuilder([]);

        self::assertNull($builder->buildFor(new DateTimeImmutable('2025-11-05'), 250.0));
        self::assertNull($builder->earliestFilingDate());
    }

    // ---------------------------------------------------------------
    // 2. Unidades: tienen que coincidir con las de YahooParser
    // ---------------------------------------------------------------

    /**
     * Margenes, ROE y rentabilidades en porcentaje 0-100 (`YahooParser`
     * multiplica por 100 lo que Yahoo da como fraccion). Si aqui salieran
     * como 0,26 en vez de 26,91, `FundamentalAnalyzer` los puntuaria como
     * si la empresa no ganara dinero.
     */
    public function testLosPorcentajesVanEnEscalaCeroACien(): void
    {
        $f = (new PointInTimeFundamentalsBuilder([$this->periodo('2025-09-27', '2025-10-31')]))
            ->buildFor(new DateTimeImmutable('2025-11-05'), 250.0);

        self::assertNotNull($f);
        self::assertEqualsWithDelta(46.90, $f->getGrossMargin(), 0.01);   // 195.201/416.161
        self::assertEqualsWithDelta(31.97, $f->getOperatingMargin(), 0.01);
        self::assertEqualsWithDelta(26.91, $f->getNetMargin(), 0.01);
        self::assertEqualsWithDelta(151.91, $f->getRoe(), 0.01);          // 112.010/73.733
    }

    /**
     * Deuda/patrimonio es RATIO PURO, no porcentaje: `YahooParser`
     * normaliza dividiendo entre 100 cualquier valor mayor que 10. Si aqui
     * saliera 152 en vez de 1,52, esa heuristica lo "corregiria" a 1,52 por
     * casualidad en unos casos y lo dejaria disparatado en otros.
     */
    public function testLaDeudaSobrePatrimonioEsRatioPuro(): void
    {
        $f = (new PointInTimeFundamentalsBuilder([$this->periodo('2025-09-27', '2025-10-31')]))
            ->buildFor(new DateTimeImmutable('2025-11-05'), 250.0);

        self::assertNotNull($f);
        self::assertEqualsWithDelta(1.524, $f->getDebtToEquity(), 0.001); // 112.377/73.733
        self::assertLessThan(10.0, $f->getDebtToEquity());
    }

    /**
     * Los ratios que dependen del precio se recalculan con la cotizacion
     * del dia: son los que hacen que el historico reconstruido varie a
     * diario aunque las cuentas sean anuales.
     */
    public function testLosRatiosDePrecioSeMuevenConLaCotizacion(): void
    {
        $builder = new PointInTimeFundamentalsBuilder([$this->periodo('2025-09-27', '2025-10-31')]);

        $barato = $builder->buildFor(new DateTimeImmutable('2025-11-05'), 150.0);
        $caro = $builder->buildFor(new DateTimeImmutable('2025-11-05'), 300.0);

        self::assertNotNull($barato);
        self::assertNotNull($caro);
        self::assertEqualsWithDelta(20.11, $barato->getPer(), 0.01);   // 150/7,46
        self::assertEqualsWithDelta(40.21, $caro->getPer(), 0.01);
        self::assertGreaterThan($barato->getMarketCap(), $caro->getMarketCap());
        self::assertGreaterThan($barato->getPriceToBook(), $caro->getPriceToBook());
        // La rentabilidad por dividendo va al reves que el precio.
        self::assertGreaterThan($caro->getDividendYield(), $barato->getDividendYield());
        // Y lo que sale solo del balance no se mueve.
        self::assertSame($barato->getNetMargin(), $caro->getNetMargin());
    }

    // ---------------------------------------------------------------
    // 3. Ausencia de dato frente a dato inventado
    // ---------------------------------------------------------------

    /**
     * Con beneficios cayendo, el PEG saldria negativo y se leeria como
     * "baratisima" en vez de como "en problemas".
     */
    public function testElPegNoSeCalculaConBeneficiosACaida(): void
    {
        $builder = new PointInTimeFundamentalsBuilder([
            $this->periodo('2024-09-28', '2024-11-01', netIncome: 150_000_000_000.0),
            $this->periodo('2025-09-27', '2025-10-31', netIncome: 112_010_000_000.0),
        ]);

        $f = $builder->buildFor(new DateTimeImmutable('2025-11-05'), 250.0);

        self::assertNotNull($f);
        self::assertNull($f->getPeg());
        // El crecimiento de ingresos si se reporta aunque sea negativo: es
        // una medida, no un ratio que se vuelva ilegible.
        self::assertNotNull($f->getRevenueGrowth());
    }

    public function testSinEjercicioAnteriorNoHayCrecimiento(): void
    {
        $f = (new PointInTimeFundamentalsBuilder([$this->periodo('2025-09-27', '2025-10-31')]))
            ->buildFor(new DateTimeImmutable('2025-11-05'), 250.0);

        self::assertNotNull($f);
        self::assertNull($f->getRevenueGrowth());
        self::assertNull($f->getPeg());
    }

    /**
     * Una empresa que no reparte dividendo tiene `null`, no cero: cero
     * seria "reparte nada" y `FundamentalAnalyzer` distingue los dos casos.
     */
    public function testUnaEmpresaSinDividendoNoInventaUnCero(): void
    {
        $f = (new PointInTimeFundamentalsBuilder([$this->periodo('2025-09-27', '2025-10-31', dividendsPaid: null)]))
            ->buildFor(new DateTimeImmutable('2025-11-05'), 250.0);

        self::assertNotNull($f);
        self::assertNull($f->getDividendYield());
        self::assertNull($f->getPayoutRatio());
        self::assertNull($f->getDividendGrowth5y());
    }

    /**
     * Patrimonio negativo (recompras agresivas, p.ej. McDonald's o Boeing):
     * ROE y precio/valor contable dejan de significar nada y se devuelven
     * como ausentes en vez de como un numero enorme y negativo. Cubre
     * tambien `debtToEquity` (roadmap.md, "Prioridad cero-ter" punto 3,
     * `2026-09-04`): con patrimonio negativo el ratio invertiria el signo
     * (empresa insolvente puntuando como "poco endeudada"); ya se excluia
     * de forma indirecta via `positive($totalStockholdersEquity)` antes de
     * este cambio, pero ahora la propia expresion de `debtToEquity` lo
     * comprueba explicitamente, sin depender solo de esa reutilizacion.
     */
    public function testConPatrimonioNegativoNoSeInventanRatios(): void
    {
        $periodo = new FiscalPeriod(
            ticker: 'MCD',
            endDate: new DateTimeImmutable('2025-12-31'),
            filingDate: new DateTimeImmutable('2026-02-20'),
            periodType: FiscalPeriodType::Annual,
            revenue: 25_000_000_000.0,
            grossProfit: 14_000_000_000.0,
            operatingIncome: 11_000_000_000.0,
            netIncome: 8_000_000_000.0,
            ebitda: 13_000_000_000.0,
            ebit: 11_000_000_000.0,
            incomeBeforeTax: 10_000_000_000.0,
            incomeTaxExpense: 2_000_000_000.0,
            epsDiluted: 11.0,
            sharesDiluted: 720_000_000.0,
            totalStockholdersEquity: -5_000_000_000.0,
            totalDebt: 38_000_000_000.0,
            netDebt: 36_000_000_000.0,
            totalCurrentAssets: 4_000_000_000.0,
            totalCurrentLiabilities: 6_000_000_000.0,
            freeCashFlow: 7_000_000_000.0,
            commonDividendsPaid: -4_800_000_000.0
        );

        $f = (new PointInTimeFundamentalsBuilder([$periodo]))->buildFor(new DateTimeImmutable('2026-03-01'), 300.0);

        self::assertNotNull($f);
        self::assertNull($f->getRoe());
        self::assertNull($f->getPriceToBook());
        self::assertNull($f->getDebtToEquity());
        // Lo que no depende del patrimonio si se calcula con normalidad.
        self::assertEqualsWithDelta(32.0, $f->getNetMargin(), 0.01);
        self::assertEqualsWithDelta(27.27, $f->getPer(), 0.01);
    }

    public function testLaFechaDePublicacionMasAntiguaMarcaDondeEmpezarElRelleno(): void
    {
        $builder = new PointInTimeFundamentalsBuilder([
            $this->periodo('2025-09-27', '2025-10-31'),
            $this->periodo('2023-09-30', '2023-11-03'),
            $this->periodo('2024-09-28', '2024-11-01'),
        ]);

        self::assertSame('2023-11-03', $builder->earliestFilingDate()?->format('Y-m-d'));
    }

    // ---------------------------------------------------------------
    // 4. TTM trimestral (bug real corregido el 2026-09-01, ver
    //    PointInTimeFundamentalsBuilder y roadmap.md "Prioridad cero",
    //    punto 1). EodhdFiscalPeriodProvider entrega trimestres AISLADOS,
    //    no acumulados year-to-date -confirmado el mismo dia contra la API
    //    real de EODHD con AAPL.US, MSFT.US, JPM.US, SAN.MC y RDDT.US-, asi
    //    que sumar cuatro trimestres consecutivos es correcto.
    // ---------------------------------------------------------------

    private function trimestre(
        string $endDate,
        string $filingDate,
        float $revenue,
        float $netIncome,
        float $grossProfit,
        float $operatingIncome,
        float $ebitda,
        float $ebit,
        float $incomeBeforeTax,
        float $incomeTaxExpense,
        float $epsDiluted,
        float $sharesDiluted,
        float $totalStockholdersEquity,
        float $totalDebt,
        float $netDebt,
        float $totalCurrentAssets,
        float $totalCurrentLiabilities,
        float $freeCashFlow,
        ?float $commonDividendsPaid
    ): FiscalPeriod {
        return new FiscalPeriod(
            ticker: 'AAPL',
            endDate: new DateTimeImmutable($endDate),
            filingDate: new DateTimeImmutable($filingDate),
            periodType: FiscalPeriodType::Quarterly,
            revenue: $revenue,
            grossProfit: $grossProfit,
            operatingIncome: $operatingIncome,
            netIncome: $netIncome,
            ebitda: $ebitda,
            ebit: $ebit,
            incomeBeforeTax: $incomeBeforeTax,
            incomeTaxExpense: $incomeTaxExpense,
            epsDiluted: $epsDiluted,
            sharesDiluted: $sharesDiluted,
            totalStockholdersEquity: $totalStockholdersEquity,
            totalDebt: $totalDebt,
            netDebt: $netDebt,
            totalCurrentAssets: $totalCurrentAssets,
            totalCurrentLiabilities: $totalCurrentLiabilities,
            freeCashFlow: $freeCashFlow,
            commonDividendsPaid: $commonDividendsPaid
        );
    }

    /**
     * Los cuatro trimestres fiscales REALES de Apple para su FY2025
     * (confirmados contra la API de EODHD el 2026-09-01, ver
     * `EodhdFiscalPeriodProvider`), no datos inventados: sumados dan,
     * campo a campo, el mismo ejercicio anual que ya usaban los tests de
     * regresion con datos de FMP (`periodo('2025-09-27', '2025-10-31')`
     * mas arriba) -es la "comparacion de magnitud contra una fuente
     * independiente" que pide el criterio de salida del plan-. La unica
     * cifra sin encaje exacto es el EPS diluido TTM (suma de los cuatro
     * trimestrales, 7.47) frente al diluido ponderado real de FMP (7.46):
     * la diferencia de 0.01 es la aproximacion ya documentada en
     * `EodhdFiscalPeriodProvider` (sumar EPS trimestrales no es
     * identico a recalcular el diluido ponderado del año).
     *
     * @return list<FiscalPeriod>
     */
    private function losCuatroTrimestresRealesDeAppleFy2025(): array
    {
        return [
            $this->trimestre(
                '2024-12-31',
                '2025-01-31',
                revenue: 124_300_000_000.0,
                netIncome: 36_330_000_000.0,
                grossProfit: 58_275_000_000.0,
                operatingIncome: 42_832_000_000.0,
                ebitda: 45_664_000_000.0,
                ebit: 42_584_000_000.0,
                incomeBeforeTax: 42_584_000_000.0,
                incomeTaxExpense: 6_254_000_000.0,
                epsDiluted: 2.40,
                sharesDiluted: 15_004_697_000.0,
                totalStockholdersEquity: 66_758_000_000.0,
                totalDebt: 96_799_000_000.0,
                netDebt: 66_500_000_000.0,
                totalCurrentAssets: 133_240_000_000.0,
                totalCurrentLiabilities: 144_365_000_000.0,
                freeCashFlow: 26_995_000_000.0,
                commonDividendsPaid: 3_856_000_000.0
            ),
            $this->trimestre(
                '2025-03-31',
                '2025-05-02',
                revenue: 95_359_000_000.0,
                netIncome: 24_780_000_000.0,
                grossProfit: 44_867_000_000.0,
                operatingIncome: 29_589_000_000.0,
                ebitda: 31_971_000_000.0,
                ebit: 29_310_000_000.0,
                incomeBeforeTax: 29_310_000_000.0,
                incomeTaxExpense: 4_530_000_000.0,
                epsDiluted: 1.65,
                sharesDiluted: 15_004_697_000.0,
                totalStockholdersEquity: 66_796_000_000.0,
                totalDebt: 98_186_000_000.0,
                netDebt: 70_024_000_000.0,
                totalCurrentAssets: 118_674_000_000.0,
                totalCurrentLiabilities: 144_571_000_000.0,
                freeCashFlow: 20_881_000_000.0,
                commonDividendsPaid: 3_758_000_000.0
            ),
            $this->trimestre(
                '2025-06-30',
                '2025-08-01',
                revenue: 94_036_000_000.0,
                netIncome: 23_434_000_000.0,
                grossProfit: 43_718_000_000.0,
                operatingIncome: 28_202_000_000.0,
                ebitda: 30_861_000_000.0,
                ebit: 28_031_000_000.0,
                incomeBeforeTax: 28_031_000_000.0,
                incomeTaxExpense: 4_597_000_000.0,
                epsDiluted: 1.57,
                sharesDiluted: 15_004_697_000.0,
                totalStockholdersEquity: 65_830_000_000.0,
                totalDebt: 101_698_000_000.0,
                netDebt: 71_233_000_000.0,
                totalCurrentAssets: 122_491_000_000.0,
                totalCurrentLiabilities: 141_120_000_000.0,
                freeCashFlow: 24_405_000_000.0,
                commonDividendsPaid: 3_945_000_000.0
            ),
            $this->trimestre(
                '2025-09-30',
                '2025-10-31',
                revenue: 102_466_000_000.0,
                netIncome: 27_466_000_000.0,
                grossProfit: 48_341_000_000.0,
                operatingIncome: 32_427_000_000.0,
                ebitda: 35_554_000_000.0,
                ebit: 32_427_000_000.0,
                incomeBeforeTax: 32_804_000_000.0,
                incomeTaxExpense: 5_338_000_000.0,
                epsDiluted: 1.85,
                sharesDiluted: 15_004_697_000.0,
                totalStockholdersEquity: 73_733_000_000.0,
                totalDebt: 112_377_000_000.0,
                netDebt: 76_443_000_000.0,
                totalCurrentAssets: 147_957_000_000.0,
                totalCurrentLiabilities: 165_631_000_000.0,
                freeCashFlow: 26_486_000_000.0,
                commonDividendsPaid: 3_862_000_000.0
            ),
        ];
    }

    /**
     * El caso que arregla el bug: cuatro trimestres reales consecutivos
     * dan el TTM correcto, verificado contra el ejercicio anual real de la
     * misma empresa y el mismo periodo (fuente independiente: FMP).
     */
    public function testCuatroTrimestresConsecutivosDanElTtmCorrecto(): void
    {
        $builder = new PointInTimeFundamentalsBuilder($this->losCuatroTrimestresRealesDeAppleFy2025());
        $f = $builder->buildFor(new DateTimeImmutable('2025-11-05'), 150.0);

        self::assertNotNull($f);
        // Antes del arreglo: PER = precio / EPS DE UN TRIMESTRE (1.85) =
        // 81.08. Con el TTM correcto sale en la misma zona que el PER
        // anual real de Apple (20.11 con el mismo precio, ver el test de
        // regresion con datos de FMP mas arriba): la distorsion era ~4x.
        self::assertEqualsWithDelta(20.08, $f->getPer(), 0.05);
        // Antes: ROE = beneficio DE UN TRIMESTRE / patrimonio = 27.466/
        // 73.733*100 = 37.25%. Con el TTM: 151.91%, identico al anual real
        // (mismo balance de cierre, mismo beneficio anual real).
        self::assertEqualsWithDelta(151.91, $f->getRoe(), 0.05);
        self::assertEqualsWithDelta(46.90, $f->getGrossMargin(), 0.01);
        self::assertEqualsWithDelta(31.97, $f->getOperatingMargin(), 0.01);
        self::assertEqualsWithDelta(26.91, $f->getNetMargin(), 0.01);
        // El balance no se suma: es el del ultimo trimestre (2025-09-30),
        // identico al del ejercicio anual real.
        self::assertEqualsWithDelta(1.524, $f->getDebtToEquity(), 0.001);
        // Con solo estos cuatro trimestres no hay ventana de "hace un año":
        // los crecimientos y el PEG tienen que ser null, no inventados.
        self::assertNull($f->getRevenueGrowth());
        self::assertNull($f->getPeg());
    }

    /**
     * Antes del arreglo, esta era la distorsion real: el ultimo trimestre
     * publicado se trataba como si fuera el ejercicio completo.
     */
    public function testAntesDelArregloElUltimoTrimestreSoloSeTratariaComoUnAnhoCompleto(): void
    {
        $ultimoTrimestre = $this->losCuatroTrimestresRealesDeAppleFy2025()[3];

        self::assertEqualsWithDelta(
            150.0 / 1.85,
            $ultimoTrimestre->epsDiluted !== null ? 150.0 / $ultimoTrimestre->epsDiluted : null,
            0.01,
            'El PER "trimestre como año" (81.08) es ~4x el PER TTM real (20.08).'
        );
    }

    /**
     * Con un hueco en la serie (falta un trimestre de cada dos), la
     * ventana de cuatro periodos mas recientes cubre año y medio, no doce
     * meses: el TTM se devuelve null en vez de sumar cifras que no
     * representan un año real. El balance (foto fija del ultimo periodo)
     * si se sigue sirviendo con normalidad.
     */
    public function testConUnHuecoEnLaSerieElTtmEsNuloPeroElBalanceNo(): void
    {
        $builder = new PointInTimeFundamentalsBuilder([
            $this->trimestre(
                '2024-03-31',
                '2024-04-30',
                revenue: 90_000_000_000.0,
                netIncome: 20_000_000_000.0,
                grossProfit: 40_000_000_000.0,
                operatingIncome: 25_000_000_000.0,
                ebitda: 28_000_000_000.0,
                ebit: 26_000_000_000.0,
                incomeBeforeTax: 26_000_000_000.0,
                incomeTaxExpense: 4_000_000_000.0,
                epsDiluted: 1.30,
                sharesDiluted: 15_000_000_000.0,
                totalStockholdersEquity: 60_000_000_000.0,
                totalDebt: 90_000_000_000.0,
                netDebt: 65_000_000_000.0,
                totalCurrentAssets: 110_000_000_000.0,
                totalCurrentLiabilities: 140_000_000_000.0,
                freeCashFlow: 22_000_000_000.0,
                commonDividendsPaid: 3_500_000_000.0
            ),
            // Falta el trimestre de 2024-06-30: siguiente dato disponible
            // es de 2024-09-30 (6 meses de salto, no 3).
            $this->trimestre(
                '2024-09-30',
                '2024-10-30',
                revenue: 92_000_000_000.0,
                netIncome: 21_000_000_000.0,
                grossProfit: 41_000_000_000.0,
                operatingIncome: 26_000_000_000.0,
                ebitda: 29_000_000_000.0,
                ebit: 27_000_000_000.0,
                incomeBeforeTax: 27_000_000_000.0,
                incomeTaxExpense: 4_200_000_000.0,
                epsDiluted: 1.35,
                sharesDiluted: 15_000_000_000.0,
                totalStockholdersEquity: 62_000_000_000.0,
                totalDebt: 91_000_000_000.0,
                netDebt: 66_000_000_000.0,
                totalCurrentAssets: 112_000_000_000.0,
                totalCurrentLiabilities: 141_000_000_000.0,
                freeCashFlow: 23_000_000_000.0,
                commonDividendsPaid: 3_600_000_000.0
            ),
            // Falta el trimestre de 2025-01-31: siguiente disponible en
            // 2025-03-31 (otro salto de 6 meses).
            $this->trimestre(
                '2025-03-31',
                '2025-05-02',
                revenue: 93_000_000_000.0,
                netIncome: 22_000_000_000.0,
                grossProfit: 42_000_000_000.0,
                operatingIncome: 27_000_000_000.0,
                ebitda: 30_000_000_000.0,
                ebit: 28_000_000_000.0,
                incomeBeforeTax: 28_000_000_000.0,
                incomeTaxExpense: 4_400_000_000.0,
                epsDiluted: 1.40,
                sharesDiluted: 15_000_000_000.0,
                totalStockholdersEquity: 64_000_000_000.0,
                totalDebt: 92_000_000_000.0,
                netDebt: 67_000_000_000.0,
                totalCurrentAssets: 114_000_000_000.0,
                totalCurrentLiabilities: 142_000_000_000.0,
                freeCashFlow: 24_000_000_000.0,
                commonDividendsPaid: 3_700_000_000.0
            ),
            $this->trimestre(
                '2025-09-30',
                '2025-10-31',
                revenue: 94_000_000_000.0,
                netIncome: 23_000_000_000.0,
                grossProfit: 43_000_000_000.0,
                operatingIncome: 28_000_000_000.0,
                ebitda: 31_000_000_000.0,
                ebit: 29_000_000_000.0,
                incomeBeforeTax: 29_000_000_000.0,
                incomeTaxExpense: 4_600_000_000.0,
                epsDiluted: 1.45,
                sharesDiluted: 15_000_000_000.0,
                totalStockholdersEquity: 66_000_000_000.0,
                totalDebt: 93_000_000_000.0,
                netDebt: 68_000_000_000.0,
                totalCurrentAssets: 116_000_000_000.0,
                totalCurrentLiabilities: 143_000_000_000.0,
                freeCashFlow: 25_000_000_000.0,
                commonDividendsPaid: 3_800_000_000.0
            ),
        ]);

        $f = $builder->buildFor(new DateTimeImmutable('2025-11-05'), 150.0);

        self::assertNotNull($f);
        self::assertNull($f->getPer(), 'El hueco hace que el EPS TTM no sea fiable: mejor null que inventado.');
        self::assertNull($f->getRoe());
        self::assertNull($f->getNetMargin());
        self::assertNull($f->getEvToEbitda());
        self::assertNull($f->getDividendYield());
        // El balance del ultimo trimestre publicado no depende del TTM.
        self::assertNotNull($f->getMarketCap());
        self::assertNotNull($f->getDebtToEquity());
        self::assertNotNull($f->getPriceToBook());
        self::assertNotNull($f->getCurrentRatio());
    }

    /**
     * Un cambio de ejercicio fiscal con un trimestre "puente" mas corto de
     * lo normal comprime la ventana de cuatro periodos por debajo de un
     * año plausible: el TTM tambien se devuelve null aqui, no solo cuando
     * el hueco alarga la ventana.
     */
    public function testUnCambioDeEjercicioFiscalConTrimestrePuenteInvalidaElTtm(): void
    {
        $builder = new PointInTimeFundamentalsBuilder([
            $this->trimestre(
                '2025-01-31',
                '2025-03-01',
                revenue: 90_000_000_000.0,
                netIncome: 20_000_000_000.0,
                grossProfit: 40_000_000_000.0,
                operatingIncome: 25_000_000_000.0,
                ebitda: 28_000_000_000.0,
                ebit: 26_000_000_000.0,
                incomeBeforeTax: 26_000_000_000.0,
                incomeTaxExpense: 4_000_000_000.0,
                epsDiluted: 1.30,
                sharesDiluted: 15_000_000_000.0,
                totalStockholdersEquity: 60_000_000_000.0,
                totalDebt: 90_000_000_000.0,
                netDebt: 65_000_000_000.0,
                totalCurrentAssets: 110_000_000_000.0,
                totalCurrentLiabilities: 140_000_000_000.0,
                freeCashFlow: 22_000_000_000.0,
                commonDividendsPaid: 3_500_000_000.0
            ),
            $this->trimestre(
                '2025-04-30',
                '2025-05-30',
                revenue: 91_000_000_000.0,
                netIncome: 20_500_000_000.0,
                grossProfit: 40_500_000_000.0,
                operatingIncome: 25_500_000_000.0,
                ebitda: 28_500_000_000.0,
                ebit: 26_500_000_000.0,
                incomeBeforeTax: 26_500_000_000.0,
                incomeTaxExpense: 4_100_000_000.0,
                epsDiluted: 1.32,
                sharesDiluted: 15_000_000_000.0,
                totalStockholdersEquity: 61_000_000_000.0,
                totalDebt: 90_500_000_000.0,
                netDebt: 65_500_000_000.0,
                totalCurrentAssets: 111_000_000_000.0,
                totalCurrentLiabilities: 140_500_000_000.0,
                freeCashFlow: 22_500_000_000.0,
                commonDividendsPaid: 3_550_000_000.0
            ),
            // Trimestre puente corto (1.5 meses en vez de 3): la empresa
            // mueve su cierre de ejercicio de abril a mediados de junio.
            $this->trimestre(
                '2025-06-15',
                '2025-07-15',
                revenue: 45_000_000_000.0,
                netIncome: 10_000_000_000.0,
                grossProfit: 20_000_000_000.0,
                operatingIncome: 12_500_000_000.0,
                ebitda: 14_000_000_000.0,
                ebit: 13_000_000_000.0,
                incomeBeforeTax: 13_000_000_000.0,
                incomeTaxExpense: 2_000_000_000.0,
                epsDiluted: 0.65,
                sharesDiluted: 15_000_000_000.0,
                totalStockholdersEquity: 61_500_000_000.0,
                totalDebt: 90_700_000_000.0,
                netDebt: 65_700_000_000.0,
                totalCurrentAssets: 111_500_000_000.0,
                totalCurrentLiabilities: 140_700_000_000.0,
                freeCashFlow: 11_000_000_000.0,
                commonDividendsPaid: 1_800_000_000.0
            ),
            $this->trimestre(
                '2025-09-15',
                '2025-10-15',
                revenue: 92_000_000_000.0,
                netIncome: 21_000_000_000.0,
                grossProfit: 41_000_000_000.0,
                operatingIncome: 26_000_000_000.0,
                ebitda: 29_000_000_000.0,
                ebit: 27_000_000_000.0,
                incomeBeforeTax: 27_000_000_000.0,
                incomeTaxExpense: 4_200_000_000.0,
                epsDiluted: 1.35,
                sharesDiluted: 15_000_000_000.0,
                totalStockholdersEquity: 62_000_000_000.0,
                totalDebt: 91_000_000_000.0,
                netDebt: 66_000_000_000.0,
                totalCurrentAssets: 112_000_000_000.0,
                totalCurrentLiabilities: 141_000_000_000.0,
                freeCashFlow: 23_000_000_000.0,
                commonDividendsPaid: 3_600_000_000.0
            ),
        ]);

        $f = $builder->buildFor(new DateTimeImmutable('2025-11-05'), 150.0);

        self::assertNotNull($f);
        // Del 2025-01-31 al 2025-09-15 hay 227 dias: muy por debajo del
        // año plausible, asi que el TTM se rechaza en vez de sumar cuatro
        // "trimestres" que en realidad cubren siete meses y medio.
        self::assertNull($f->getPer());
        self::assertNull($f->getNetMargin());
    }

    /**
     * Denominadores negativos o cero con una ventana TTM por lo demas
     * valida: patrimonio negativo en el ultimo balance (recompras
     * agresivas) no debe inventar ROE ni precio/valor contable, pero el
     * PER y los margenes -que no dependen del patrimonio- se calculan con
     * normalidad a partir del TTM.
     */
    public function testConPatrimonioNegativoEnElUltimoTrimestreNoSeInventanRatiosDeBalance(): void
    {
        $trimestres = $this->losCuatroTrimestresRealesDeAppleFy2025();
        // Se sustituye el ultimo trimestre real por uno identico salvo en
        // el patrimonio, que se fuerza a negativo.
        $trimestres[3] = $this->trimestre(
            '2025-09-30',
            '2025-10-31',
            revenue: 102_466_000_000.0,
            netIncome: 27_466_000_000.0,
            grossProfit: 48_341_000_000.0,
            operatingIncome: 32_427_000_000.0,
            ebitda: 35_554_000_000.0,
            ebit: 32_427_000_000.0,
            incomeBeforeTax: 32_804_000_000.0,
            incomeTaxExpense: 5_338_000_000.0,
            epsDiluted: 1.85,
            sharesDiluted: 15_004_697_000.0,
            totalStockholdersEquity: -5_000_000_000.0,
            totalDebt: 112_377_000_000.0,
            netDebt: 76_443_000_000.0,
            totalCurrentAssets: 147_957_000_000.0,
            totalCurrentLiabilities: 165_631_000_000.0,
            freeCashFlow: 26_486_000_000.0,
            commonDividendsPaid: 3_862_000_000.0
        );

        $f = (new PointInTimeFundamentalsBuilder($trimestres))->buildFor(new DateTimeImmutable('2025-11-05'), 150.0);

        self::assertNotNull($f);
        self::assertNull($f->getRoe());
        self::assertNull($f->getPriceToBook());
        self::assertNull($f->getDebtToEquity());
        self::assertEqualsWithDelta(26.91, $f->getNetMargin(), 0.01);
        self::assertEqualsWithDelta(20.08, $f->getPer(), 0.05);
    }

    /**
     * Fechas de publicacion desordenadas: un trimestre con `endDate`
     * anterior al de otros ya publicados, pero cuya `filingDate` llega
     * MUY tarde (una reformulacion o un simple retraso administrativo).
     * En la fecha D justo despues del filing "normal" del resto, ese
     * trimestre todavia no cuenta -asi que el TTM no completa cuatro
     * trimestres-; en una fecha D posterior a su filing real, si cuenta.
     * Es la demostracion directa del criterio de salida del plan: "en una
     * fecha D solo entran trimestres con filingDate <= D".
     */
    public function testUnTrimestreConFilingMuyTardioNoCuentaHastaQueSePublicaDeVerdad(): void
    {
        $trimestres = $this->losCuatroTrimestresRealesDeAppleFy2025();
        // El trimestre de 2025-06-30 (indice 2) se publica mucho mas tarde
        // de lo normal: una reformulacion, no el filing original.
        $trimestres[2] = $this->trimestre(
            '2025-06-30',
            '2026-03-01',
            revenue: 94_036_000_000.0,
            netIncome: 23_434_000_000.0,
            grossProfit: 43_718_000_000.0,
            operatingIncome: 28_202_000_000.0,
            ebitda: 30_861_000_000.0,
            ebit: 28_031_000_000.0,
            incomeBeforeTax: 28_031_000_000.0,
            incomeTaxExpense: 4_597_000_000.0,
            epsDiluted: 1.57,
            sharesDiluted: 15_004_697_000.0,
            totalStockholdersEquity: 65_830_000_000.0,
            totalDebt: 101_698_000_000.0,
            netDebt: 71_233_000_000.0,
            totalCurrentAssets: 122_491_000_000.0,
            totalCurrentLiabilities: 141_120_000_000.0,
            freeCashFlow: 24_405_000_000.0,
            commonDividendsPaid: 3_945_000_000.0
        );
        $builder = new PointInTimeFundamentalsBuilder($trimestres);

        // Antes del filing tardio: solo hay 3 trimestres publicados
        // (2024-12-31, 2025-03-31, 2025-09-30 -que ademas no son
        // consecutivos por endDate, asi que "current" es 2025-09-30 con
        // solo dos predecesores disponibles-), no hay TTM.
        $antes = $builder->buildFor(new DateTimeImmutable('2025-11-05'), 150.0);
        self::assertNotNull($antes);
        self::assertNull($antes->getPer(), 'El trimestre de 2025-06-30 no se habia publicado todavia en esta fecha.');

        // Despues del filing tardio, el TTM completa los cuatro trimestres
        // y vuelve a coincidir con el ejercicio anual real.
        $despues = $builder->buildFor(new DateTimeImmutable('2026-03-15'), 150.0);
        self::assertNotNull($despues);
        self::assertEqualsWithDelta(20.08, $despues->getPer(), 0.05);
    }

    // ---------------------------------------------------------------
    // 5. earningsYield y cashConversion (P3.3,
    //    `REVISION_MOTOR_CODEX_2026-09-02.md`, seccion P3.3)
    // ---------------------------------------------------------------

    public function testEarningsYieldEsElInversoDelPer(): void
    {
        $f = (new PointInTimeFundamentalsBuilder([$this->periodo('2025-09-27', '2025-10-31')]))
            ->buildFor(new DateTimeImmutable('2025-11-05'), 150.0);

        self::assertNotNull($f);
        self::assertNotNull($f->getPer());
        // PER = 150/7.46 = 20.11 (ver testLosRatiosDePrecioSeMuevenConLaCotizacion).
        self::assertEqualsWithDelta(7.46 / 150.0, $f->getEarningsYield(), 0.0001);
        self::assertEqualsWithDelta(1 / $f->getPer(), $f->getEarningsYield(), 0.0001);
    }

    /**
     * A diferencia de `per` (que exige beneficio TTM positivo, ver la
     * guarda de `buildFor()`), `earningsYield` SI admite EPS negativo: es
     * el punto de tenerlo por separado (P3.3), para no perder de vista las
     * empresas con perdidas en un ranking por percentil.
     */
    public function testEarningsYieldAdmiteBeneficioNegativoADiferenciaDelPer(): void
    {
        $periodo = new FiscalPeriod(
            ticker: 'LOSS',
            endDate: new DateTimeImmutable('2025-12-31'),
            filingDate: new DateTimeImmutable('2026-02-15'),
            periodType: FiscalPeriodType::Annual,
            revenue: 10_000_000_000.0,
            grossProfit: 3_000_000_000.0,
            operatingIncome: -1_000_000_000.0,
            netIncome: -2_000_000_000.0,
            ebitda: -500_000_000.0,
            ebit: -1_000_000_000.0,
            incomeBeforeTax: -1_000_000_000.0,
            incomeTaxExpense: 0.0,
            epsDiluted: -1.50,
            sharesDiluted: 1_333_333_333.0,
            totalStockholdersEquity: 5_000_000_000.0,
            totalDebt: 3_000_000_000.0,
            netDebt: 2_500_000_000.0,
            totalCurrentAssets: 4_000_000_000.0,
            totalCurrentLiabilities: 3_000_000_000.0,
            freeCashFlow: -300_000_000.0,
            commonDividendsPaid: null
        );

        $f = (new PointInTimeFundamentalsBuilder([$periodo]))->buildFor(new DateTimeImmutable('2026-03-01'), 20.0);

        self::assertNotNull($f);
        self::assertNull($f->getPer(), 'Con EPS negativo el PER no se calcula, ver la guarda de buildFor().');
        self::assertEqualsWithDelta(-1.50 / 20.0, $f->getEarningsYield(), 0.0001);
        self::assertLessThan(0.0, $f->getEarningsYield());
    }

    public function testCashConversionEsElFlujoDeCajaLibreEntreElBeneficioNeto(): void
    {
        $f = (new PointInTimeFundamentalsBuilder([$this->periodo('2025-09-27', '2025-10-31')]))
            ->buildFor(new DateTimeImmutable('2025-11-05'), 150.0);

        self::assertNotNull($f);
        // FCF TTM 98.767M / netIncome TTM 112.010M (ver periodo()).
        self::assertEqualsWithDelta(98_767_000_000.0 / 112_010_000_000.0, $f->getCashConversion(), 0.0001);
    }

    public function testCashConversionEsNuloConBeneficioNetoCero(): void
    {
        $periodo = new FiscalPeriod(
            ticker: 'BREAKEVEN',
            endDate: new DateTimeImmutable('2025-12-31'),
            filingDate: new DateTimeImmutable('2026-02-15'),
            periodType: FiscalPeriodType::Annual,
            revenue: 10_000_000_000.0,
            grossProfit: 3_000_000_000.0,
            operatingIncome: 500_000_000.0,
            netIncome: 0.0,
            ebitda: 800_000_000.0,
            ebit: 500_000_000.0,
            incomeBeforeTax: 500_000_000.0,
            incomeTaxExpense: 500_000_000.0,
            epsDiluted: 0.0,
            sharesDiluted: 1_000_000_000.0,
            totalStockholdersEquity: 5_000_000_000.0,
            totalDebt: 3_000_000_000.0,
            netDebt: 2_500_000_000.0,
            totalCurrentAssets: 4_000_000_000.0,
            totalCurrentLiabilities: 3_000_000_000.0,
            freeCashFlow: 300_000_000.0,
            commonDividendsPaid: null
        );

        $f = (new PointInTimeFundamentalsBuilder([$periodo]))->buildFor(new DateTimeImmutable('2026-03-01'), 20.0);

        self::assertNotNull($f);
        self::assertNull($f->getCashConversion(), 'Beneficio neto cero: la division no significa nada.');
    }

    /**
     * roadmap.md, "Prioridad cero-ter" punto 3 (`2026-09-04`): con FCF y
     * beneficio neto ambos NEGATIVOS, la guarda anterior (`!= 0.0`) dejaba
     * pasar la division y el signo negativo entre dos negativos daba un
     * ratio POSITIVO que aparentaba buena conversion de caja -- el caso
     * degenerado exacto que la guarda tiene que evitar, no solo la division
     * por cero.
     */
    public function testCashConversionEsNuloConFcfYBeneficioNetoAmbosNegativos(): void
    {
        $periodo = new FiscalPeriod(
            ticker: 'BURNING',
            endDate: new DateTimeImmutable('2025-12-31'),
            filingDate: new DateTimeImmutable('2026-02-15'),
            periodType: FiscalPeriodType::Annual,
            revenue: 5_000_000_000.0,
            grossProfit: 1_000_000_000.0,
            operatingIncome: -800_000_000.0,
            netIncome: -1_000_000_000.0,
            ebitda: -400_000_000.0,
            ebit: -800_000_000.0,
            incomeBeforeTax: -800_000_000.0,
            incomeTaxExpense: 0.0,
            epsDiluted: -1.0,
            sharesDiluted: 1_000_000_000.0,
            totalStockholdersEquity: 2_000_000_000.0,
            totalDebt: 1_000_000_000.0,
            netDebt: 800_000_000.0,
            totalCurrentAssets: 1_500_000_000.0,
            totalCurrentLiabilities: 1_000_000_000.0,
            freeCashFlow: -600_000_000.0,
            commonDividendsPaid: null
        );

        $f = (new PointInTimeFundamentalsBuilder([$periodo]))->buildFor(new DateTimeImmutable('2026-03-01'), 10.0);

        self::assertNotNull($f);
        // Sin la guarda corregida, -600M/-1.000M = 0,6 (aparenta buena
        // conversion de caja); con la guarda, null.
        self::assertNull(
            $f->getCashConversion(),
            'FCF y beneficio neto ambos negativos: el ratio positivo resultante no significa "buena conversion de caja".'
        );
    }

    public function testMezclarPeriodosAnualesYTrimestralesLanzaExcepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PointInTimeFundamentalsBuilder([
            $this->periodo('2025-09-27', '2025-10-31'),
            $this->trimestre(
                '2025-09-30',
                '2025-10-31',
                revenue: 100_000_000_000.0,
                netIncome: 25_000_000_000.0,
                grossProfit: 45_000_000_000.0,
                operatingIncome: 30_000_000_000.0,
                ebitda: 33_000_000_000.0,
                ebit: 30_000_000_000.0,
                incomeBeforeTax: 30_000_000_000.0,
                incomeTaxExpense: 5_000_000_000.0,
                epsDiluted: 1.80,
                sharesDiluted: 15_000_000_000.0,
                totalStockholdersEquity: 70_000_000_000.0,
                totalDebt: 100_000_000_000.0,
                netDebt: 70_000_000_000.0,
                totalCurrentAssets: 140_000_000_000.0,
                totalCurrentLiabilities: 150_000_000_000.0,
                freeCashFlow: 25_000_000_000.0,
                commonDividendsPaid: 3_800_000_000.0
            ),
        ]);
    }
}
