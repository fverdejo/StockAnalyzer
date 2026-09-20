<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\PolicyReplayHorizonAnalysis;

/**
 * `PolicyReplayHorizonAnalysis` (medicion completa de trailing,
 * `2026-09-20`): se prueba con filas SINTETICAS de valores conocidos, para
 * verificar la aritmetica (descomposicion, penalizacion) y la logica de
 * veredicto, no el mercado.
 */
final class PolicyReplayHorizonAnalysisTest extends TestCase
{
    private const CUTOFF = '2027-01-01';

    /**
     * @param callable(int): float $dFor valor de D para la entrada `$i`
     * @return list<array<string, mixed>>
     */
    private function rows(int $count, int $stepDays, callable $dFor, float $g = 0.05, int $deltaX = 10, ?callable $reentry = null): array
    {
        $rows = [];
        $start = new DateTimeImmutable('2017-01-01');

        for ($i = 0; $i < $count; $i++) {
            $entry = $start->modify('+' . ($i * $stepDays) . ' days');
            $end = $entry->modify('+63 days');
            $d = $dFor($i);
            $arm = static fn (float $v, float $mfe, bool $pending): array => [
                'exit_index' => 20,
                'exit_date' => $entry->modify('+28 days')->format('Y-m-d'),
                'exit_price_raw' => 100.0,
                'managed_return' => $v,
                'pending' => $pending,
                'exit_reason' => 'stop_loss',
                'x' => 20,
                'v' => [21 => null, 45 => $v, 90 => null, 250 => null],
                'mfe' => $mfe,
            ];

            $rows[] = [
                'ticker' => 'T' . ($i % 25),
                'entry_date' => $entry->format('Y-m-d'),
                'entry_index' => $i,
                'entry_open' => 100.0,
                'last_index' => 10_000,
                'closes' => [21 => null, 45 => ['date' => $end->format('Y-m-d'), 'close' => 100.0], 90 => null, 250 => null],
                'in_cohort' => true,
                'g' => $g,
                'delta_x' => $deltaX,
                'd' => $d,
                'd10' => $d,
                'reentry' => $reentry !== null ? $reentry($i) : $i % 2 === 0,
                'bear' => $i % 4 === 0,
                'baseline_return' => 1.0,
                'baseline_exit_date' => $entry->modify('+28 days')->format('Y-m-d'),
                'stop_raises' => 1,
                'initial_stop' => 90.0,
                'arms' => [
                    'fixed' => $arm(0.0, 10.0, true),
                    'trail' => $arm($d, 10.0, false),
                    'trail10' => $arm($d, 10.0, false),
                ],
            ];
        }

        return $rows;
    }

    /**
     * Ruido determinista de media cero (patron +/-) para que el bootstrap
     * tenga varianza sin depender del azar.
     */
    private function noise(int $i): float
    {
        return [0.0, 1.5, -1.5, 0.5, -0.5, 2.0, -2.0, 1.0, -1.0][$i % 9];
    }

    public function testLaDescomposicionYLaPenalizacionSalenDeLosPromedios(): void
    {
        // 400 entradas cada 8 dias (~3.200 dias), D = -0,5 + ruido; g=0,05%/sesion; Δx=10.
        $rows = $this->rows(400, 8, fn (int $i): float => -0.5 + $this->noise($i));

        $result = (new PolicyReplayHorizonAnalysis())->analyze($rows, new DateTimeImmutable(self::CUTOFF));
        $e = $result['primary']['estimates'];

        self::assertSame(400, $e['n']);
        self::assertEqualsWithDelta(-0.5 + array_sum(array_map(fn (int $i): float => $this->noise($i), range(0, 399))) / 400, $e['P'], 1e-9);
        // P_drift = -r̄ x media(Δx) = -0,05 x 10 = -0,5; P_T = P - P_drift.
        self::assertEqualsWithDelta(-0.5, $e['P_drift'], 1e-12);
        self::assertEqualsWithDelta($e['P'] + 0.5, $e['P_T'], 1e-12);
        // Cuota = 50% (entradas pares); T = P_T - 0,40 x 0,5.
        self::assertEqualsWithDelta(0.5, $e['share_reentry'], 1e-12);
        self::assertEqualsWithDelta($e['P_T'] - 0.20, $e['T'], 1e-12);
        self::assertEqualsWithDelta($e['P'] - 0.20, $e['P_penalized'], 1e-12);
        self::assertTrue($result['primary']['ci_consistent_with_summarize']);
        // Span de exposicion 63 dias en todas las filas sinteticas -> bloque 2 x 63.
        self::assertSame(126, $result['primary']['block_width_days']);
        self::assertGreaterThanOrEqual(20.0, $result['primary']['blocks_in_range']);
        self::assertTrue($result['primary']['result_informative']);
    }

    public function testUnEfectoPositivoClaroDaGoConPenalizacionIncluida(): void
    {
        $rows = $this->rows(400, 8, fn (int $i): float => 4.0 + $this->noise($i));

        $result = (new PolicyReplayHorizonAnalysis())->analyze($rows, new DateTimeImmutable(self::CUTOFF));

        self::assertStringStartsWith('GO', $result['verdict']);
        self::assertGreaterThanOrEqual(0.0, $result['primary']['intervals']['T']['ci95_low']);
    }

    public function testUnCosteClaroYSostenidoDaNoGoSoloSiTambienElTimingEsNegativo(): void
    {
        $rows = $this->rows(400, 8, fn (int $i): float => -6.0 + $this->noise($i));

        $result = (new PolicyReplayHorizonAnalysis())->analyze($rows, new DateTimeImmutable(self::CUTOFF));

        // P ~ -6, P_T ~ -5,5: ambos IC superiores por debajo de -δ y de 0.
        self::assertStringStartsWith('NO-GO', $result['verdict']);
    }

    /**
     * Un P muy negativo pero DOMINADO por la deriva (r̄ x Δx grande): el
     * timing (P_T) es ~0, asi que NO-GO NO se dispara (era el fallo del
     * primer diseño: cerrar por deriva/ruido).
     */
    public function testUnPMuyNegativoExplicadoPorLaDerivaNoCierraElTrailing(): void
    {
        // g=0,25%/sesion x Δx=20 = 5pp de deriva; D ~ -5 -> P_T ~ 0.
        $rows = $this->rows(400, 8, fn (int $i): float => -5.0 + $this->noise($i), g: 0.25, deltaX: 20);

        $result = (new PolicyReplayHorizonAnalysis())->analyze($rows, new DateTimeImmutable(self::CUTOFF));

        self::assertLessThan(-1.0, $result['primary']['intervals']['P']['ci95_high']);
        self::assertGreaterThanOrEqual(0.0, $result['primary']['intervals']['P_T']['ci95_high']);
        self::assertStringStartsWith('INDET', $result['verdict']);
    }

    public function testUnRangoCortoNoEsInformativoYNoPuedeDarGoNiNoGo(): void
    {
        // 60 entradas cada 3 dias: ~180 dias de rango frente a bloques de ~126: no llega a 20 bloques.
        $rows = $this->rows(60, 3, fn (int $i): float => 9.0 + $this->noise($i));

        $result = (new PolicyReplayHorizonAnalysis())->analyze($rows, new DateTimeImmutable(self::CUTOFF));

        self::assertFalse($result['primary']['result_informative']);
        self::assertStringStartsWith('NO_INFORMATIVO', $result['verdict']);
    }

    public function testLasEntradasFueraDeLaCohorteNoEntranEnLaMedicion(): void
    {
        $rows = $this->rows(400, 8, fn (int $i): float => 1.0 + $this->noise($i));
        $rows[0]['closes'][45] = null;
        $rows[0]['in_cohort'] = false;
        $rows[0]['d'] = null;
        $rows[0]['g'] = null;

        $result = (new PolicyReplayHorizonAnalysis())->analyze($rows, new DateTimeImmutable(self::CUTOFF));

        self::assertSame(400, $result['entries_total']);
        self::assertSame(399, $result['cohort_n']);
    }

    public function testLasPuertasSeCalculanYG4NoEsEvaluableConPocosEpisodios(): void
    {
        $rows = $this->rows(400, 8, fn (int $i): float => 4.0 + $this->noise($i));

        $gates = (new PolicyReplayHorizonAnalysis())->analyze($rows, new DateTimeImmutable(self::CUTOFF))['gates_descriptive_of_priority'];

        // Solo 100 entradas bajistas (< 300): G4 no evaluable -> INDET.
        self::assertSame(100, $gates['G4_bear_regime']['n_bear']);
        self::assertFalse($gates['G4_bear_regime']['evaluable']);
        self::assertFalse($gates['G4_bear_regime']['pass']);
        // El give-back del trailing (MFE 10 - V ~ 4 = 6) es menor que el del fijo (10 - 0): G1 con >=15% menos.
        self::assertLessThan(0.0, $gates['G1_giveback']['relative_change']);
        self::assertTrue($gates['G1_giveback']['pass']);
        // Tres terciles de ~133.
        self::assertCount(3, $gates['G3_terciles']['terciles']);
        self::assertTrue($gates['G3_terciles']['pass']);
        // Cadencia 10 identica a la 5 en estas filas.
        self::assertTrue($gates['G5_robustness']['cadence10_ok']);
        self::assertCount(10, $gates['G5_robustness']['top10_tickers_by_abs_contribution']);
    }

    public function testElAnalisisEsDeterminista(): void
    {
        $rows = $this->rows(400, 8, fn (int $i): float => 0.3 + $this->noise($i));
        $analysis = new PolicyReplayHorizonAnalysis();
        $cutoff = new DateTimeImmutable(self::CUTOFF);

        self::assertSame(
            json_encode($analysis->analyze($rows, $cutoff)),
            json_encode($analysis->analyze(array_reverse($rows), $cutoff)),
            'Mismo resultado con las filas en otro orden (desempate explicito por fecha, ticker, indice).'
        );
    }
}
