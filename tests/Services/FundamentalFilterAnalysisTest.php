<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\FundamentalFilterAnalysis;

/**
 * `FundamentalFilterAnalysis` (protocolo de utilidad del motor, predeclaracion
 * cerrada del 2026-09-22, `versions.md`): filas SINTETICAS de valores
 * conocidos, para verificar la aritmetica y la logica de veredicto, no el
 * mercado.
 */
final class FundamentalFilterAnalysisTest extends TestCase
{
    private function noise(int $i): float
    {
        return [0.0, 1.5, -1.5, 0.5, -0.5, 2.0, -2.0, 1.0, -1.0][$i % 9];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $count, int $stepDays, float $rejectionShare, callable $vAForRejected, bool $allInCohort = true, ?callable $vAForAccepted = null): array
    {
        $rows = [];
        $start = new DateTimeImmutable('2017-01-01');
        $rejectedCount = (int) round($count * $rejectionShare);

        for ($i = 0; $i < $count; $i++) {
            $entry = $start->modify('+' . ($i * $stepDays) . ' days');
            $rejected = $i < $rejectedCount;
            $vA = $rejected ? $vAForRejected($i) : ($vAForAccepted !== null ? $vAForAccepted($i) : 1.0 + $this->noise($i) * 0.1);
            $vC = $vA - 0.2; // D'_i = V_A - V_C = 0.2 constante, solo para comprobar que se reporta.

            $rows[] = [
                'ticker' => 'T' . ($i % 20),
                'entry_date' => $entry->format('Y-m-d'),
                'entry_index' => $i,
                'sector' => $i % 5 === 0 ? 'Financial Services' : 'Technology',
                'in_cohort' => $allInCohort,
                'signal_date' => $entry->format('Y-m-d'),
                'de_evaluable' => true,
                'de_value' => $rejected ? 2.5 : 1.0,
                'de_snapshot_date' => $entry->format('Y-m-d'),
                'de_not_evaluable_reason' => null,
                'de_rejected' => $rejected,
                'v_a' => $vA,
                'v_c' => $vC,
                'v_b' => $rejected ? 0.0 : $vA,
                'd' => $rejected ? -$vA : 0.0,
                'd_prime' => $vA - $vC,
                'mfe_a_close' => $vA + 5.0,
            ];
        }

        return $rows;
    }

    public function testLaIdentidadPEsTasaDeRechazoPorPCondSeCumple(): void
    {
        // 400 entradas, 20% rechazadas con V_A~10 (perdida grande al rechazar), 80% aceptadas.
        $rows = $this->rows(400, 8, 0.2, fn (int $i): float => 10.0 + $this->noise($i));

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertSame(400, $result['cohort_n']);
        self::assertSame(400, $result['evaluable_n']);
        self::assertSame(80, $result['rejected_n']);
        self::assertSame(320, $result['accepted_n']);
        self::assertEqualsWithDelta(20.0, $result['rejection_rate_pct'], 0.01);

        $expectedP = ($result['rejected_n'] / $result['evaluable_n']) * $result['primary']['p_cond'];
        self::assertEqualsWithDelta($expectedP, $result['secondary_diluted']['p'], 1e-9);
    }

    public function testUnRechazoClaramenteCostosoDaValidadoConCoberturaSuficiente(): void
    {
        // Rechazar evita perder 10pp de media: D_i = -V_A = -10 en rechazadas -> P_cond ~ -10 (mejora al rechazar).
        // Para VALIDADO hace falta P_cond >= +delta: V_A NEGATIVO en rechazadas (rechazar evita una perdida).
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => -6.0 + $this->noise($i));

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertTrue($result['coverage_gate_ok']);
        self::assertTrue($result['primary']['result_informative']);
        self::assertGreaterThanOrEqual(FundamentalFilterAnalysis::DELTA_PP, $result['primary']['p_cond']);
        self::assertStringStartsWith('VALIDADO', $result['verdict']);
    }

    public function testUnRechazoQueEmpeoraElResultadoDaDescartado(): void
    {
        // V_A muy positivo en rechazadas: rechazarlas cuesta D_i = -V_A muy negativo.
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => 8.0 + $this->noise($i));

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertLessThan(0.0, $result['primary']['ci95_high']);
        self::assertSame('DESCARTADO', $result['verdict']);
    }

    public function testUnEfectoPequenoYNoConcluyenteDaInsuficiente(): void
    {
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => 0.0 + $this->noise($i) * 0.3);

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertTrue($result['primary']['result_informative']);
        self::assertSame('INSUFICIENTE', $result['verdict']);
    }

    public function testUnaTasaDeRechazoPorDebajoDelGateDaNoInformativoAunqueElEfectoSeaGrande(): void
    {
        // Solo 1% rechazado: por debajo del gate del 3%.
        $rows = $this->rows(400, 8, 0.01, fn (int $i): float => -20.0 + $this->noise($i));

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertFalse($result['coverage_gate_ok']);
        self::assertSame('NO_INFORMATIVO', $result['verdict']);
    }

    public function testUnRangoTemporalCortoNoEsInformativoAunqueLaCoberturaSeaSuficiente(): void
    {
        // 60 entradas cada 3 dias: rango corto, pocos bloques posibles.
        $rows = $this->rows(60, 3, 0.3, fn (int $i): float => -6.0 + $this->noise($i));

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertTrue($result['coverage_gate_ok']);
        self::assertFalse($result['primary']['result_informative']);
        self::assertSame('NO_INFORMATIVO', $result['verdict']);
    }

    public function testLasEntradasFueraDeLaCohorteNoEntranEnElAnalisis(): void
    {
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => -6.0 + $this->noise($i));
        $rows[0]['in_cohort'] = false;

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertSame(399, $result['cohort_n']);
    }

    public function testLasEntradasNoEvaluablesSeCuentanAparteYPorMotivo(): void
    {
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => -6.0 + $this->noise($i));
        $rows[0]['de_evaluable'] = false;
        $rows[0]['de_not_evaluable_reason'] = 'sin_filing_previo_a_senal';
        $rows[0]['de_rejected'] = false;
        $rows[1]['de_evaluable'] = false;
        $rows[1]['de_not_evaluable_reason'] = 'sin_filing_previo_a_senal';
        $rows[1]['de_rejected'] = false;
        $rows[2]['de_evaluable'] = false;
        $rows[2]['de_not_evaluable_reason'] = 'snapshot_sin_debt_to_equity';
        $rows[2]['de_rejected'] = false;

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertSame(3, $result['not_evaluable_n']);
        self::assertSame(['sin_filing_previo_a_senal' => 2, 'snapshot_sin_debt_to_equity' => 1], $result['not_evaluable_by_reason']);
        self::assertSame(397, $result['evaluable_n']);
    }

    public function testElDesgloseSectorialSeCalculaSobreLaCohorteYLosRechazos(): void
    {
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => -6.0 + $this->noise($i));

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertArrayHasKey('Financial Services', $result['descriptive']['sector_breakdown']);
        self::assertArrayHasKey('Technology', $result['descriptive']['sector_breakdown']);
        self::assertSame(
            $result['cohort_n'],
            array_sum(array_column($result['descriptive']['sector_breakdown'], 'cohort'))
        );
    }

    public function testLosPercentilesDeVaSeReportanParaRechazadasYAceptadas(): void
    {
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => -6.0 + $this->noise($i));

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertSame(120, $result['descriptive']['v_a_rejected']['n']);
        self::assertSame(280, $result['descriptive']['v_a_accepted']['n']);
        self::assertLessThanOrEqual($result['descriptive']['v_a_rejected']['p50'], $result['descriptive']['v_a_rejected']['p5'] ?? PHP_FLOAT_MAX);
    }

    public function testDPrimaSeReportaComoSecundariaSinVeredicto(): void
    {
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => -6.0 + $this->noise($i));

        $result = (new FundamentalFilterAnalysis())->analyze($rows);

        self::assertArrayNotHasKey('verdict', $result['secondary_a_vs_c']);
        self::assertEqualsWithDelta(0.2, $result['secondary_a_vs_c']['mean'], 1e-6);
    }

    public function testLosCuatroVeredictosSonMutuamenteExcluyentesEnUnBarridoDeEscenarios(): void
    {
        $analysis = new FundamentalFilterAnalysis();
        $scenarios = [
            $this->rows(400, 8, 0.3, fn (int $i): float => -8.0 + $this->noise($i)),
            $this->rows(400, 8, 0.3, fn (int $i): float => 8.0 + $this->noise($i)),
            $this->rows(400, 8, 0.3, fn (int $i): float => 0.0 + $this->noise($i) * 0.3),
            $this->rows(400, 8, 0.01, fn (int $i): float => -8.0 + $this->noise($i)),
        ];

        $verdicts = array_map(static fn (array $rows): string => $analysis->analyze($rows)['verdict'], $scenarios);

        self::assertSame(['VALIDADO', 'DESCARTADO', 'INSUFICIENTE', 'NO_INFORMATIVO'], $verdicts);
        self::assertCount(4, array_unique($verdicts));
    }

    public function testUnaViolacionDeLaIdentidadAlgebraicaLanzaExcepcion(): void
    {
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => -6.0 + $this->noise($i));
        // Rompe la identidad d_i=-v_a en la primera rechazada sin tocar v_a.
        $rows[0]['d'] = $rows[0]['d'] - 5.0;

        $this->expectException(LogicException::class);

        (new FundamentalFilterAnalysis())->analyze($rows);
    }

    public function testElAnalisisEsDeterministaFrenteAlOrdenDeLasFilas(): void
    {
        $rows = $this->rows(400, 8, 0.3, fn (int $i): float => -6.0 + $this->noise($i));
        $analysis = new FundamentalFilterAnalysis();

        self::assertSame(
            json_encode($analysis->analyze($rows)),
            json_encode($analysis->analyze(array_reverse($rows)))
        );
    }
}
