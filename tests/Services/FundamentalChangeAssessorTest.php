<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Enums\FundamentalChangeVerdict;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Services\FundamentalChangeAssessor;

/**
 * D2 del diagnostico fundamental ("Cambio interanual", ver
 * `FundamentalChangeAssessor`): clasificacion por mayoria de signo entre
 * los factores disponibles (margen operativo, ROIC, deuda/patrimonio,
 * conversion de caja), nunca por magnitud con un umbral inventado
 * (`auditor-estadistico`, 2026-09-05).
 */
final class FundamentalChangeAssessorTest extends TestCase
{
    private function company(string $sector = 'Technology'): Company
    {
        return new Company('ACME', 'Acme Corp', $sector, 'software', 'NASDAQ', 'USD');
    }

    private function fundamentals(
        ?float $roic = null,
        ?float $operatingMargin = null,
        ?float $debtToEquity = null,
        ?float $cashConversion = null
    ): Fundamentals {
        return new Fundamentals(
            per: null,
            peg: null,
            roe: null,
            roic: $roic,
            eps: null,
            marketCap: 1_000_000_000.0,
            debtToEquity: $debtToEquity,
            freeCashFlow: null,
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
        $repository = new InMemoryFundamentalsHistoryRepository();
        $repository->withFundamentalsSnapshot('ACME', ['roic' => 10.0]);

        $result = (new FundamentalChangeAssessor($repository))->assess(
            'ACME',
            $this->fundamentals(roic: 20.0),
            $this->company('Financial Services')
        );

        self::assertTrue($result->sectorExcluded);
        self::assertSame(FundamentalChangeVerdict::NO_EVALUABLE, $result->verdict);
        self::assertSame([], $result->factors);
    }

    public function testSinSnapshotDeHaceUnAñoEsNoEvaluable(): void
    {
        $repository = new InMemoryFundamentalsHistoryRepository();
        // Ningun snapshot registrado para ACME: findAsOf() devuelve null.

        $result = (new FundamentalChangeAssessor($repository))->assess(
            'ACME',
            $this->fundamentals(roic: 20.0),
            $this->company()
        );

        self::assertFalse($result->sectorExcluded);
        self::assertSame(FundamentalChangeVerdict::NO_EVALUABLE, $result->verdict);
        self::assertSame([], $result->factors);
    }

    public function testConUnUnicoFactorDisponibleEsNoEvaluablePeroExponeElFactor(): void
    {
        $repository = new InMemoryFundamentalsHistoryRepository();
        $repository->withFundamentalsSnapshot('ACME', ['roic' => 10.0]);

        $result = (new FundamentalChangeAssessor($repository))->assess(
            'ACME',
            $this->fundamentals(roic: 15.0),
            $this->company()
        );

        self::assertSame(FundamentalChangeVerdict::NO_EVALUABLE, $result->verdict);
        self::assertCount(1, $result->factors);
        self::assertSame('ROIC', $result->factors[0]->label);
    }

    public function testMayoriaMejorandoDaVeredictoMejorando(): void
    {
        $repository = new InMemoryFundamentalsHistoryRepository();
        $repository->withFundamentalsSnapshot('ACME', [
            'roic' => 10.0,
            'operatingMargin' => 15.0,
            'debtToEquity' => 1.0,
        ]);

        // ROIC sube (mejora), margen sube (mejora), deuda/patrimonio sube
        // (empeora, mejorar ahi es bajar): 2 mejoras contra 1 empeoramiento.
        $result = (new FundamentalChangeAssessor($repository))->assess(
            'ACME',
            $this->fundamentals(roic: 14.0, operatingMargin: 18.0, debtToEquity: 1.5),
            $this->company()
        );

        self::assertSame(FundamentalChangeVerdict::MEJORANDO, $result->verdict);
        self::assertCount(3, $result->factors);
    }

    public function testMayoriaEmpeorandoDaVeredictoDeteriorando(): void
    {
        $repository = new InMemoryFundamentalsHistoryRepository();
        $repository->withFundamentalsSnapshot('ACME', [
            'roic' => 20.0,
            'operatingMargin' => 25.0,
            'cashConversion' => 1.2,
        ]);

        $result = (new FundamentalChangeAssessor($repository))->assess(
            'ACME',
            $this->fundamentals(roic: 12.0, operatingMargin: 15.0, cashConversion: 1.1),
            $this->company()
        );

        self::assertSame(FundamentalChangeVerdict::DETERIORANDO, $result->verdict);
    }

    public function testEmpateDaVeredictoEstable(): void
    {
        $repository = new InMemoryFundamentalsHistoryRepository();
        $repository->withFundamentalsSnapshot('ACME', [
            'roic' => 10.0,
            'operatingMargin' => 20.0,
        ]);

        // ROIC sube (mejora), margen baja (empeora): 1 contra 1, empate.
        $result = (new FundamentalChangeAssessor($repository))->assess(
            'ACME',
            $this->fundamentals(roic: 14.0, operatingMargin: 16.0),
            $this->company()
        );

        self::assertSame(FundamentalChangeVerdict::ESTABLE, $result->verdict);
    }

    /**
     * Deuda/patrimonio BAJANDO cuenta como mejora (al reves que el resto de
     * factores, donde mejorar es subir) -- confirma que
     * `FundamentalChangeFactor::higherIsBetter` se respeta para este factor
     * en concreto.
     */
    public function testDeudaPatrimonioMejoraCuandoBaja(): void
    {
        $repository = new InMemoryFundamentalsHistoryRepository();
        $repository->withFundamentalsSnapshot('ACME', [
            'debtToEquity' => 2.0,
            'roic' => 10.0,
        ]);

        $result = (new FundamentalChangeAssessor($repository))->assess(
            'ACME',
            $this->fundamentals(debtToEquity: 1.0, roic: 10.001),
            $this->company()
        );

        self::assertSame(FundamentalChangeVerdict::MEJORANDO, $result->verdict);
    }

    public function testUnFactorAusenteEnCualquieraDeLasDosFechasSeExcluyeDeLaComparacion(): void
    {
        $repository = new InMemoryFundamentalsHistoryRepository();
        $repository->withFundamentalsSnapshot('ACME', [
            'roic' => 10.0,
            'operatingMargin' => null,
            'debtToEquity' => 1.0,
        ]);

        // operatingMargin actual SI tiene dato, pero el de hace un año no:
        // el factor se excluye, no se rellena con nada.
        $result = (new FundamentalChangeAssessor($repository))->assess(
            'ACME',
            $this->fundamentals(roic: 12.0, operatingMargin: 20.0, debtToEquity: 0.9),
            $this->company()
        );

        self::assertCount(2, $result->factors);

        foreach ($result->factors as $factor) {
            self::assertNotSame('Margen operativo', $factor->label);
        }
    }

    public function testAsOfPersonalizadoSePasaAlRepositorio(): void
    {
        $repository = new InMemoryFundamentalsHistoryRepository();
        $repository->withFundamentalsSnapshot('ACME', ['roic' => 10.0, 'operatingMargin' => 15.0]);

        $result = (new FundamentalChangeAssessor($repository))->assess(
            'ACME',
            $this->fundamentals(roic: 11.0, operatingMargin: 16.0),
            $this->company(),
            new DateTimeImmutable('2026-01-01')
        );

        self::assertSame(FundamentalChangeVerdict::MEJORANDO, $result->verdict);
    }
}
