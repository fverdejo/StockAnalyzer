<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\FiscalPeriod;
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
     * como ausentes en vez de como un numero enorme y negativo.
     */
    public function testConPatrimonioNegativoNoSeInventanRatios(): void
    {
        $periodo = new FiscalPeriod(
            ticker: 'MCD',
            endDate: new DateTimeImmutable('2025-12-31'),
            filingDate: new DateTimeImmutable('2026-02-20'),
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
}
