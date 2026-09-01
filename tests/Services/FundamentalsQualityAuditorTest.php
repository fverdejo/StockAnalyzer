<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\FiscalPeriod;
use StockAnalyzer\DTO\FiscalPeriodType;
use StockAnalyzer\Services\FundamentalsQualityAuditor;

/**
 * FundamentalsQualityAuditor (roadmap.md, "Prioridad cero" punto 3B).
 * Cada caso replica un tipo de dato sucio real ya documentado (`KB`,
 * `SAN.MC`) o una imposibilidad contable citada explicitamente en el plan.
 */
final class FundamentalsQualityAuditorTest extends TestCase
{
    private function auditor(): FundamentalsQualityAuditor
    {
        return new FundamentalsQualityAuditor();
    }

    // ---------------------------------------------------------------
    // auditRawPayload()
    // ---------------------------------------------------------------

    private function income(string $date, string $filing, float $revenue = 100.0): array
    {
        return [
            'date' => $date,
            'filing_date' => $filing,
            'totalRevenue' => $revenue,
            'grossProfit' => 40.0,
            'operatingIncome' => 20.0,
            'netIncome' => 10.0,
            'ebitda' => 22.0,
            'ebit' => 21.0,
            'incomeBeforeTax' => 21.0,
            'incomeTaxExpense' => 3.0,
        ];
    }

    private function balance(string $date, string $filing, ?float $shortLongTermDebtTotal = 50.0): array
    {
        return [
            'date' => $date,
            'filing_date' => $filing,
            'totalStockholderEquity' => 200.0,
            'netDebt' => 40.0,
            'totalCurrentAssets' => 80.0,
            'totalCurrentLiabilities' => 60.0,
            'shortLongTermDebtTotal' => $shortLongTermDebtTotal,
        ];
    }

    private function cashFlow(string $date, string $filing): array
    {
        return [
            'date' => $date,
            'filing_date' => $filing,
            'freeCashFlow' => 15.0,
            'dividendsPaid' => 4.0,
        ];
    }

    /**
     * @param list<array<string,mixed>> $income
     * @param list<array<string,mixed>> $balance
     * @param list<array<string,mixed>> $cashFlow
     * @param list<array<string,mixed>> $outstandingShares
     */
    private function payload(array $income, array $balance, array $cashFlow, array $outstandingShares = []): array
    {
        return [
            'Financials' => [
                'Income_Statement' => ['quarterly' => $income],
                'Balance_Sheet' => ['quarterly' => $balance],
                'Cash_Flow' => ['quarterly' => $cashFlow],
            ],
            'Earnings' => ['History' => []],
            'outstandingShares' => ['quarterly' => $outstandingShares],
        ];
    }

    public function testDetectaFilingAntesDeCerrarElPeriodo(): void
    {
        $payload = $this->payload(
            [$this->income('2025-03-31', '2025-02-01')], // publicado ANTES de cerrar
            [$this->balance('2025-03-31', '2025-02-01')],
            [$this->cashFlow('2025-03-31', '2025-02-01')]
        );

        $issues = $this->auditor()->auditRawPayload($payload, 'ACME');

        $types = array_map(static fn ($i) => $i->type, $issues);
        self::assertContains('filing_before_period_end', $types);

        $issue = array_values(array_filter($issues, static fn ($i) => $i->type === 'filing_before_period_end'))[0];
        self::assertSame('error', $issue->severity);
    }

    public function testNoMarcaFilingPosteriorComoError(): void
    {
        $payload = $this->payload(
            [$this->income('2025-03-31', '2025-05-02')],
            [$this->balance('2025-03-31', '2025-05-02')],
            [$this->cashFlow('2025-03-31', '2025-05-02')]
        );

        $issues = $this->auditor()->auditRawPayload($payload, 'ACME');

        self::assertSame([], array_values(array_filter($issues, static fn ($i) => $i->type === 'filing_before_period_end')));
    }

    public function testDetectaPeriodoDuplicadoConValoresDistintos(): void
    {
        $payload = $this->payload(
            [
                $this->income('2025-03-31', '2025-05-02', revenue: 100.0),
                $this->income('2025-03-31', '2025-05-02', revenue: 999.0),
            ],
            [$this->balance('2025-03-31', '2025-05-02')],
            [$this->cashFlow('2025-03-31', '2025-05-02')]
        );

        $issues = $this->auditor()->auditRawPayload($payload, 'ACME');

        $types = array_map(static fn ($i) => $i->type, $issues);
        self::assertContains('duplicate_period', $types);
    }

    public function testNoMarcaDuplicadoBenignoConLosMismosValores(): void
    {
        $row = $this->income('2025-03-31', '2025-05-02');
        $payload = $this->payload(
            [$row, $row],
            [$this->balance('2025-03-31', '2025-05-02')],
            [$this->cashFlow('2025-03-31', '2025-05-02')]
        );

        $issues = $this->auditor()->auditRawPayload($payload, 'ACME');

        self::assertSame([], array_values(array_filter($issues, static fn ($i) => $i->type === 'duplicate_period')));
    }

    public function testDetectaAccionesEnCirculacionNegativas(): void
    {
        $payload = $this->payload(
            [$this->income('2025-03-31', '2025-05-02')],
            [$this->balance('2025-03-31', '2025-05-02')],
            [$this->cashFlow('2025-03-31', '2025-05-02')],
            [['date' => '2025-Q1', 'dateFormatted' => '2025-03-31', 'shares' => -1_000_000.0]]
        );

        $issues = $this->auditor()->auditRawPayload($payload, 'ACME');

        $types = array_map(static fn ($i) => $i->type, $issues);
        self::assertContains('negative_shares', $types);
    }

    public function testDetectaDeudaTotalNegativa(): void
    {
        $payload = $this->payload(
            [$this->income('2025-03-31', '2025-05-02')],
            [$this->balance('2025-03-31', '2025-05-02', shortLongTermDebtTotal: -50.0)],
            [$this->cashFlow('2025-03-31', '2025-05-02')]
        );

        $issues = $this->auditor()->auditRawPayload($payload, 'ACME');

        $types = array_map(static fn ($i) => $i->type, $issues);
        self::assertContains('negative_debt', $types);
    }

    // ---------------------------------------------------------------
    // auditParsedPeriods()
    // ---------------------------------------------------------------

    private function periodo(
        string $endDate,
        string $filingDate,
        ?float $epsDiluted = 1.0,
        ?float $netIncome = 10.0,
        FiscalPeriodType $periodType = FiscalPeriodType::Quarterly
    ): FiscalPeriod {
        return new FiscalPeriod(
            ticker: 'ACME',
            endDate: new DateTimeImmutable($endDate),
            filingDate: new DateTimeImmutable($filingDate),
            periodType: $periodType,
            revenue: 100.0,
            grossProfit: 40.0,
            operatingIncome: 20.0,
            netIncome: $netIncome,
            ebitda: 22.0,
            ebit: 21.0,
            incomeBeforeTax: 21.0,
            incomeTaxExpense: 3.0,
            epsDiluted: $epsDiluted,
            sharesDiluted: 10.0,
            totalStockholdersEquity: 200.0,
            totalDebt: 50.0,
            netDebt: 40.0,
            totalCurrentAssets: 80.0,
            totalCurrentLiabilities: 60.0,
            freeCashFlow: 15.0,
            commonDividendsPaid: -4.0
        );
    }

    /**
     * Reproduce el caso `KB` documentado en `versions.md` v2.111: el EPS
     * salta de escala (KRW crudos vs. otra unidad) entre dos trimestres
     * consecutivos sin ningun cambio de signo ni motivo de negocio.
     */
    public function testDetectaSaltoDeUnidadEnEpsEntreTrimestresConsecutivos(): void
    {
        $periods = [
            $this->periodo('2024-12-31', '2025-02-01', epsDiluted: 4453.0),
            $this->periodo('2025-03-31', '2025-05-01', epsDiluted: 3.49),
        ];

        $issues = $this->auditor()->auditParsedPeriods('KB', $periods);

        $types = array_map(static fn ($i) => $i->type, $issues);
        self::assertContains('currency_unit_jump', $types);

        $issue = array_values(array_filter($issues, static fn ($i) => $i->type === 'currency_unit_jump'))[0];
        self::assertSame('warning', $issue->severity);
    }

    public function testNoMarcaUnaVariacionNormalDeEpsComoSaltoDeUnidad(): void
    {
        $periods = [
            $this->periodo('2024-12-31', '2025-02-01', epsDiluted: 1.0),
            $this->periodo('2025-03-31', '2025-05-01', epsDiluted: 1.3),
        ];

        $issues = $this->auditor()->auditParsedPeriods('ACME', $periods);

        self::assertSame([], array_values(array_filter($issues, static fn ($i) => $i->type === 'currency_unit_jump')));
    }

    public function testNoMarcaUnaPerdidaPuntualComoSaltoDeUnidad(): void
    {
        // Cambio de signo: perdida real de negocio, no un problema de unidad.
        $periods = [
            $this->periodo('2024-12-31', '2025-02-01', netIncome: 500.0),
            $this->periodo('2025-03-31', '2025-05-01', netIncome: -20.0),
        ];

        $issues = $this->auditor()->auditParsedPeriods('ACME', $periods);

        self::assertSame([], array_values(array_filter($issues, static fn ($i) => $i->type === 'currency_unit_jump')));
    }

    public function testDetectaHuecoDeMasDeDosTrimestresEnLaSerie(): void
    {
        $periods = [
            $this->periodo('2024-03-31', '2024-05-01'),
            // faltan 2024-06-30, 2024-09-30 y 2024-12-31: tres trimestres sin publicar.
            $this->periodo('2025-03-31', '2025-05-01'),
        ];

        $issues = $this->auditor()->auditParsedPeriods('ACME', $periods);

        $types = array_map(static fn ($i) => $i->type, $issues);
        self::assertContains('series_gap', $types);
    }

    public function testNoMarcaUnHuecoDeUnSoloTrimestreComoProblema(): void
    {
        $periods = [
            $this->periodo('2024-03-31', '2024-05-01'),
            // Falta un unico trimestre (2024-06-30): dentro del limite tolerado.
            $this->periodo('2024-09-30', '2024-11-01'),
        ];

        $issues = $this->auditor()->auditParsedPeriods('ACME', $periods);

        self::assertSame([], array_values(array_filter($issues, static fn ($i) => $i->type === 'series_gap')));
    }

    /**
     * Ejercicio fiscal no natural (caso real: minoristas con año fiscal
     * terminado a finales de enero, como Walmart -- sus cierres
     * trimestrales caen en enero/abril/julio/octubre, no en los meses de
     * fin de trimestre natural). Debe salir como nota, no como error ni
     * warning.
     */
    public function testMarcaCalendarioFiscalNoNaturalComoNotaNoComoError(): void
    {
        $periods = [
            $this->periodo('2024-04-30', '2024-06-01'),
            $this->periodo('2024-07-31', '2024-09-01'),
        ];

        $issues = $this->auditor()->auditParsedPeriods('WMT', $periods);

        $calendarIssues = array_values(array_filter($issues, static fn ($i) => $i->type === 'fiscal_calendar_note'));
        self::assertCount(1, $calendarIssues);
        self::assertSame('note', $calendarIssues[0]->severity);
    }

    /**
     * `MSFT` es el contraejemplo: su año fiscal empieza en julio, pero sus
     * cuatro cierres trimestrales SI caen en meses naturales
     * (sep/dic/mar/jun) -- lo inusual es el orden fiscal, no el mes de
     * cierre, asi que este chequeo (basado en el mes) no la marca.
     */
    public function testNoMarcaCalendarioNaturalComoNota(): void
    {
        $periods = [
            $this->periodo('2024-03-31', '2024-05-01'),
            $this->periodo('2024-06-30', '2024-08-01'),
            $this->periodo('2024-09-30', '2024-11-01'),
            $this->periodo('2024-12-31', '2025-02-01'),
        ];

        $issues = $this->auditor()->auditParsedPeriods('ACME', $periods);

        self::assertSame([], array_values(array_filter($issues, static fn ($i) => $i->type === 'fiscal_calendar_note')));
    }

    /**
     * Cuatro trimestres consecutivos con margen neto TTM disparatado
     * (netIncome muy por encima del revenue del propio periodo, algo que
     * no deberia pasar en una serie limpia) debe salir como `extreme_ratio`.
     */
    public function testDetectaMargenNetoExtremo(): void
    {
        $periods = [
            $this->periodo('2024-03-31', '2024-05-01', netIncome: 500.0),
            $this->periodo('2024-06-30', '2024-08-01', netIncome: 500.0),
            $this->periodo('2024-09-30', '2024-11-01', netIncome: 500.0),
            $this->periodo('2024-12-31', '2025-02-01', netIncome: 500.0),
        ];

        $issues = $this->auditor()->auditParsedPeriods('ACME', $periods);

        $types = array_map(static fn ($i) => $i->type, $issues);
        self::assertContains('extreme_ratio', $types);
    }

    public function testNoMarcaMargenesNormalesComoExtremos(): void
    {
        $periods = [
            $this->periodo('2024-03-31', '2024-05-01'),
            $this->periodo('2024-06-30', '2024-08-01'),
            $this->periodo('2024-09-30', '2024-11-01'),
            $this->periodo('2024-12-31', '2025-02-01'),
        ];

        $issues = $this->auditor()->auditParsedPeriods('ACME', $periods);

        self::assertSame([], array_values(array_filter($issues, static fn ($i) => $i->type === 'extreme_ratio')));
    }

    public function testAuditParsedPeriodsConListaVaciaNoProduceHallazgos(): void
    {
        self::assertSame([], $this->auditor()->auditParsedPeriods('ACME', []));
    }

    // ---------------------------------------------------------------
    // ttmCoverage()
    // ---------------------------------------------------------------

    public function testCoberturaTtmCeroAntesDeCuatroTrimestresPublicados(): void
    {
        $periods = [
            $this->periodo('2024-03-31', '2024-05-01'),
            $this->periodo('2024-06-30', '2024-08-01'),
            $this->periodo('2024-09-30', '2024-11-01'),
        ];

        $priceDates = [new DateTimeImmutable('2024-09-01'), new DateTimeImmutable('2024-10-01')];

        $coverage = $this->auditor()->ttmCoverage(FiscalPeriodType::Quarterly, $periods, $priceDates);

        self::assertSame(2, $coverage['total']);
        self::assertSame(0, $coverage['covered']);
        self::assertSame(0.0, $coverage['pct']);
    }

    public function testCoberturaTtmCienPorCienConCuatroTrimestresYaPublicados(): void
    {
        $periods = [
            $this->periodo('2024-03-31', '2024-05-01'),
            $this->periodo('2024-06-30', '2024-08-01'),
            $this->periodo('2024-09-30', '2024-11-01'),
            $this->periodo('2024-12-31', '2025-02-01'),
        ];

        $priceDates = [new DateTimeImmutable('2025-03-01'), new DateTimeImmutable('2025-04-01')];

        $coverage = $this->auditor()->ttmCoverage(FiscalPeriodType::Quarterly, $periods, $priceDates);

        self::assertSame(2, $coverage['total']);
        self::assertSame(2, $coverage['covered']);
        self::assertSame(100.0, $coverage['pct']);
    }

    public function testCoberturaTtmMixtaAntesYDespuesDelCuartoTrimestre(): void
    {
        $periods = [
            $this->periodo('2024-03-31', '2024-05-01'),
            $this->periodo('2024-06-30', '2024-08-01'),
            $this->periodo('2024-09-30', '2024-11-01'),
            $this->periodo('2024-12-31', '2025-02-01'),
        ];

        // Antes de que se publique el 4o trimestre (2025-02-01): sin TTM.
        // Despues: con TTM.
        $priceDates = [new DateTimeImmutable('2025-01-15'), new DateTimeImmutable('2025-02-15')];

        $coverage = $this->auditor()->ttmCoverage(FiscalPeriodType::Quarterly, $periods, $priceDates);

        self::assertSame(2, $coverage['total']);
        self::assertSame(1, $coverage['covered']);
        self::assertSame(50.0, $coverage['pct']);
    }

    public function testCoberturaTtmAnualUsaVentanaDeUnSoloPeriodo(): void
    {
        $periods = [
            $this->periodo('2023-12-31', '2024-02-01', periodType: FiscalPeriodType::Annual),
        ];

        $priceDates = [new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-03-01')];

        $coverage = $this->auditor()->ttmCoverage(FiscalPeriodType::Annual, $periods, $priceDates);

        self::assertSame(2, $coverage['total']);
        self::assertSame(1, $coverage['covered']);
        self::assertSame(50.0, $coverage['pct']);
    }

    public function testCoberturaTtmSinFechasDePrecioDevuelveTotalCero(): void
    {
        $periods = [$this->periodo('2024-03-31', '2024-05-01')];

        $coverage = $this->auditor()->ttmCoverage(FiscalPeriodType::Quarterly, $periods, []);

        self::assertSame(0, $coverage['total']);
        self::assertSame(0.0, $coverage['pct']);
    }
}
