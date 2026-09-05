<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Services\FundamentalHealthAssessor;

/**
 * D1 del diagnostico fundamental ("Salud fundamental", ver
 * `FundamentalHealthAssessor`): alertas de distress absolutas, nunca un
 * percentil sectorial (no hay fuente barata de pares para una ficha
 * individual en vivo, ver el docblock de `DTO\FundamentalHealthAssessment`).
 */
final class FundamentalHealthAssessorTest extends TestCase
{
    private function company(string $sector = 'Technology'): Company
    {
        return new Company('ACME', 'Acme Corp', $sector, 'software', 'NASDAQ', 'USD');
    }

    private function fundamentals(
        ?float $roic = null,
        ?float $operatingMargin = null,
        ?float $debtToEquity = null,
        ?float $cashConversion = null,
        ?float $freeCashFlow = null
    ): Fundamentals {
        return new Fundamentals(
            per: null,
            peg: null,
            roe: null,
            roic: $roic,
            eps: null,
            marketCap: 1_000_000_000.0,
            debtToEquity: $debtToEquity,
            freeCashFlow: $freeCashFlow,
            evToEbitda: null,
            priceToBook: null,
            dividendYield: null,
            payoutRatio: null,
            grossMargin: null,
            operatingMargin: $operatingMargin,
            netMargin: null,
            revenueGrowth: null,
            currentRatio: null,
            dividendGrowth5y: null,
            earningsYield: null,
            cashConversion: $cashConversion
        );
    }

    public function testSectorFinancieroQuedaExcluido(): void
    {
        $result = (new FundamentalHealthAssessor())->assess(
            $this->fundamentals(roic: 20.0, operatingMargin: 25.0, debtToEquity: 0.5, cashConversion: 1.0),
            $this->company('Financial Services')
        );

        self::assertTrue($result->sectorExcluded);
        self::assertFalse($result->datosInsuficientes);
        self::assertFalse($result->endeudamientoNoEvaluable);
        self::assertFalse($result->fcfNegativo);
        self::assertFalse($result->margenOperativoNegativo);
        self::assertNull($result->roic);
    }

    public function testSectorInmobiliarioQuedaExcluido(): void
    {
        $result = (new FundamentalHealthAssessor())->assess(
            $this->fundamentals(roic: 20.0),
            $this->company('Real Estate')
        );

        self::assertTrue($result->sectorExcluded);
    }

    public function testEndeudamientoNoEvaluableCuandoDebtToEquityEsNulo(): void
    {
        $result = (new FundamentalHealthAssessor())->assess(
            $this->fundamentals(roic: 15.0, operatingMargin: 20.0, debtToEquity: null, cashConversion: 1.0),
            $this->company()
        );

        self::assertFalse($result->sectorExcluded);
        self::assertFalse($result->datosInsuficientes);
        self::assertTrue($result->endeudamientoNoEvaluable);
    }

    public function testFcfNegativoSeSeñala(): void
    {
        $result = (new FundamentalHealthAssessor())->assess(
            $this->fundamentals(roic: 10.0, freeCashFlow: -5_000_000.0),
            $this->company()
        );

        self::assertTrue($result->fcfNegativo);
    }

    public function testFcfPositivoNoSeSeñala(): void
    {
        $result = (new FundamentalHealthAssessor())->assess(
            $this->fundamentals(roic: 10.0, freeCashFlow: 5_000_000.0),
            $this->company()
        );

        self::assertFalse($result->fcfNegativo);
    }

    public function testMargenOperativoNegativoSeSeñala(): void
    {
        $result = (new FundamentalHealthAssessor())->assess(
            $this->fundamentals(roic: 10.0, operatingMargin: -3.5),
            $this->company()
        );

        self::assertTrue($result->margenOperativoNegativo);
    }

    /**
     * La leccion de P3.3 (versions.md, 2026-09-03/2026-09-04): ausencia de
     * dato nunca debe leerse como ausencia de alerta. Los cuatro factores
     * nulos deben marcar `datosInsuficientes`, no una salud aparentemente
     * limpia.
     */
    public function testCuatroFactoresNulosMarcaDatosInsuficientes(): void
    {
        $result = (new FundamentalHealthAssessor())->assess(
            $this->fundamentals(),
            $this->company()
        );

        self::assertFalse($result->sectorExcluded);
        self::assertTrue($result->datosInsuficientes);
    }

    public function testConAlMenosUnFactorNoMarcaDatosInsuficientes(): void
    {
        $result = (new FundamentalHealthAssessor())->assess(
            $this->fundamentals(roic: 12.0),
            $this->company()
        );

        self::assertFalse($result->datosInsuficientes);
    }

    public function testValoresBrutosSeExponenTalCual(): void
    {
        $result = (new FundamentalHealthAssessor())->assess(
            $this->fundamentals(roic: 18.5, operatingMargin: 22.0, debtToEquity: 0.8, cashConversion: 1.1),
            $this->company()
        );

        self::assertSame(18.5, $result->roic);
        self::assertSame(22.0, $result->operatingMargin);
        self::assertSame(0.8, $result->debtToEquity);
        self::assertSame(1.1, $result->cashConversion);
    }
}
