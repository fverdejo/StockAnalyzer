<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\PolicyReplaySimulator;
use StockAnalyzer\Services\PolicyReplayTrailingSimulator;

/**
 * `PolicyReplayTrailingSimulator` (piloto de trailing predeclarado por
 * `gestor-riesgo`, `2026-09-20`). Se prueba contra `PolicyReplaySimulator`
 * real (no un doble) porque el trailing PARTE de sus entradas: lo que se
 * verifica es justo que solo cambie la regla de salida.
 */
final class PolicyReplayTrailingSimulatorTest extends TestCase
{
    /**
     * @param array<int, array{0: float, 1: float, 2: float, 3: float}> $overrides indice => [open, high, low, close]
     * @return list<HistoricalQuote>
     */
    private function history(array $overrides = [], int $length = 100): array
    {
        $history = [];
        $date = new DateTimeImmutable('2024-01-01');

        for ($i = 0; $i < $length; $i++) {
            [$open, $high, $low, $close] = $overrides[$i] ?? [100.0, 100.5, 99.5, 100.0];
            $history[] = new HistoricalQuote($date, $open, $high, $low, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $history;
    }

    /**
     * @param list<HistoricalQuote> $history
     * @return array<string, mixed>
     */
    private function point(array $history, int $index, string $recommendation, ?float $stopLoss, ?float $candidateStop): array
    {
        return [
            'date' => $history[$index]->getDate()->format('Y-m-d'),
            'index' => $index,
            'recommendation' => $recommendation,
            'stop_loss' => $stopLoss,
            'candidate_stop' => $candidateStop,
            'fundamental_change' => null,
            'entry_price' => $index + 1 < count($history) ? $history[$index + 1]->getOpen() : null,
            'eligible' => true,
        ];
    }

    private function simulator(float $costBps = 0.0): PolicyReplayTrailingSimulator
    {
        $config = new BacktestingConfig($costBps);

        return new PolicyReplayTrailingSimulator(new PolicyReplaySimulator(backtestingConfig: $config), $config);
    }

    /**
     * Sube de 100 a 130 (indices 12-20), se mantiene en 130 hasta el 25 y
     * el 26 cae con hueco (apertura 120, minimo 105). El stop fijo (90) no
     * se toca nunca; el trailing (115 en el 20, 118 en el 25) si.
     *
     * @return array{0: list<HistoricalQuote>, 1: list<array<string, mixed>>}
     */
    private function rallyThenDrop(): array
    {
        $overrides = [
            16 => [110.0, 111.0, 109.0, 110.0],
            20 => [128.0, 131.0, 127.0, 130.0],
            26 => [120.0, 121.0, 105.0, 106.0],
        ];

        for ($i = 21; $i <= 25; $i++) {
            $overrides[$i] = [130.0, 130.5, 129.5, 130.0];
        }

        $history = $this->history($overrides);
        $timeline = [
            $this->point($history, 10, 'BUY', 90.0, 90.0),
            $this->point($history, 15, 'HOLD', null, 95.0),
            $this->point($history, 20, 'HOLD', null, 115.0),
            $this->point($history, 25, 'HOLD', null, 118.0),
        ];

        return [$history, $timeline];
    }

    public function testElStopSubeConLosCandidatosYSaleAntesQueElFijo(): void
    {
        [$history, $timeline] = $this->rallyThenDrop();

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame(1, $result['entries_total']);
        $trade = $result['trades'][0];
        self::assertSame(11, $trade['entry_index']);
        self::assertSame('stop_loss', $trade['exit_reason']);
        self::assertFalse($trade['pending']);
        self::assertSame(26, $trade['exit_index']);
        self::assertSame(118.0, $trade['exit_price'], 'El hueco abre en 120 (por encima del stop 118): se ejecuta en el stop, no en la apertura.');
        self::assertSame(18.0, $trade['managed_return']);
        self::assertSame(90.0, $trade['initial_stop']);
        self::assertSame(118.0, $trade['final_stop']);
        self::assertSame(3, $trade['stop_raises'], 'Sube en los puntos 15 (95), 20 (115) y 25 (118).');
        // El fijo nunca cruza el 90: sigue abierto hasta el ultimo cierre.
        self::assertSame(99, $trade['fixed_exit_index']);
        self::assertSame('pending_at_cutoff', $trade['fixed_exit_reason']);
        self::assertSame([], $this->simulator()->accountingViolations($result));
    }

    /**
     * Sin look-ahead: el candidato calculado con el cierre del dia 20 rige
     * desde el dia 21. El minimo del propio dia 20 (105) esta POR DEBAJO del
     * candidato (115) pero el stop vigente ese dia sigue siendo el viejo.
     */
    public function testElCandidatoDeUnPuntoNoAplicaAlPropioDiaDelPunto(): void
    {
        $history = $this->history([
            20 => [128.0, 131.0, 105.0, 130.0],
        ]);
        $timeline = [
            $this->point($history, 10, 'BUY', 90.0, 90.0),
            $this->point($history, 20, 'HOLD', null, 115.0),
        ];

        $result = $this->simulator()->replay('ACME', $timeline, $history);
        $trade = $result['trades'][0];

        // El dia 21 (base plana, minimo 99,5) ya SI cruza el nuevo stop 115.
        self::assertSame(21, $trade['exit_index']);
        self::assertSame(100.0, $trade['exit_price'], 'Apertura 100 <= 115: hueco bajista, se ejecuta a la apertura.');
    }

    /**
     * Cadencia 10 (`reviewStride: 2`): solo se actualiza en las revisiones
     * 1ª, 3ª, 5ª... posteriores a la entrada. En `rallyThenDrop` las
     * revisiones son 15 (95), 20 (115), 25 (118): con cadencia 10 se aplican
     * la 1ª y la 3ª (dos subidas), no la del 20.
     */
    public function testConCadenciaDiezSoloSeActualizaEnLasRevisionesImpares(): void
    {
        [$history, $timeline] = $this->rallyThenDrop();

        $every = $this->simulator()->replay('ACME', $timeline, $history)['trades'][0];
        $odd = $this->simulator()->replay('ACME', $timeline, $history, reviewStride: 2)['trades'][0];

        self::assertSame(3, $every['stop_raises']);
        self::assertSame(2, $odd['stop_raises']);
        self::assertSame(118.0, $odd['final_stop']);
        self::assertSame(26, $odd['exit_index']);
        self::assertSame(118.0, $odd['exit_price_raw']);
        self::assertSame([], $this->simulator()->accountingViolations(['ticker' => 'ACME', 'trades' => [$odd]]));
    }

    public function testUnaCadenciaInferiorAUnoSeRechaza(): void
    {
        [$history, $timeline] = $this->rallyThenDrop();

        $this->expectException(\InvalidArgumentException::class);

        $this->simulator()->replay('ACME', $timeline, $history, reviewStride: 0);
    }

    public function testUnCandidatoMasBajoNuncaBajaElStop(): void
    {
        $history = $this->history();
        $timeline = [
            $this->point($history, 10, 'BUY', 90.0, 90.0),
            $this->point($history, 15, 'HOLD', null, 80.0),
            $this->point($history, 20, 'HOLD', null, 85.0),
        ];

        $trade = $this->simulator()->replay('ACME', $timeline, $history)['trades'][0];

        self::assertSame(90.0, $trade['final_stop']);
        self::assertSame(0, $trade['stop_raises']);
    }

    public function testSinCandidatoElStopSeMantieneYSeCuentaLaRevisionSinCandidato(): void
    {
        $history = $this->history();
        $timeline = [
            $this->point($history, 10, 'BUY', 90.0, 90.0),
            $this->point($history, 15, 'HOLD', null, null),
            $this->point($history, 20, 'HOLD', null, 0.0),
            $this->point($history, 25, 'HOLD', null, 95.0),
        ];
        // Un punto sin el campo (timeline de una version anterior): tambien
        // cuenta como "sin candidato".
        $legacy = $this->point($history, 30, 'HOLD', null, null);
        unset($legacy['candidate_stop']);
        $timeline[] = $legacy;

        $result = $this->simulator()->replay('ACME', $timeline, $history);
        $trade = $result['trades'][0];

        self::assertSame(95.0, $trade['final_stop']);
        self::assertSame(1, $trade['stop_raises']);
        self::assertSame(4, $result['reviews_total']);
        self::assertSame(3, $result['reviews_without_candidate']);
    }

    public function testConElTrailingDesactivadoReproduceExactamenteElBrazoFijo(): void
    {
        // La primera operacion cruza el stop fijo (dia 30); la segunda
        // queda pendiente: cubre salida por stop y valoracion a mercado.
        $history = $this->history([
            30 => [100.0, 100.5, 84.0, 88.0],
        ]);
        $timeline = [
            $this->point($history, 10, 'BUY', 90.0, 90.0),
            $this->point($history, 35, 'BUY', 95.0, 95.0),
            $this->point($history, 40, 'HOLD', null, 99.0),
        ];

        foreach ([0.0, 10.0] as $costBps) {
            $config = new BacktestingConfig($costBps);
            $fixed = (new PolicyReplaySimulator(backtestingConfig: $config))->replay('ACME', $timeline, $history);
            $off = (new PolicyReplayTrailingSimulator(new PolicyReplaySimulator(backtestingConfig: $config), $config))
                ->replay('ACME', $timeline, $history, trailingEnabled: false);

            self::assertSame($fixed['entries_total'], $off['entries_total']);
            self::assertSame($fixed['entries_closed'], $off['entries_closed']);
            self::assertSame($fixed['entries_pending'], $off['entries_pending']);
            self::assertGreaterThan(1, $fixed['entries_total']);

            foreach ($fixed['trades'] as $i => $fixedTrade) {
                foreach (['entry_date', 'entry_index', 'entry_price', 'exit_date', 'exit_index', 'exit_price', 'exit_reason', 'pending', 'holding_days', 'managed_return', 'baseline_return', 'baseline_exit_date', 'baseline_pending'] as $field) {
                    self::assertSame($fixedTrade[$field], $off['trades'][$i][$field], "campo {$field}, coste {$costBps}bps, operacion {$i}");
                }
            }
        }
    }

    public function testMantieneElMismoNumeroDeEntradasQueElFijoYSusCamposDelComparador(): void
    {
        [$history, $timeline] = $this->rallyThenDrop();
        $config = new BacktestingConfig(0.0);
        $fixed = (new PolicyReplaySimulator(backtestingConfig: $config))->replay('ACME', $timeline, $history);
        $trailing = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame($fixed['entries_total'], $trailing['entries_total']);
        self::assertSame($fixed['candidates_excluded_by_membership'], $trailing['candidates_excluded_by_membership']);
        self::assertSame($fixed['trades'][0]['baseline_return'], $trailing['trades'][0]['baseline_return']);
        self::assertSame($fixed['trades'][0]['baseline_exit_date'], $trailing['trades'][0]['baseline_exit_date']);
        self::assertSame($fixed['trades'][0]['entry_price'], $trailing['trades'][0]['entry_price']);
    }

    public function testUnaOperacionQueNuncaCruzaElStopQuedaPendienteValoradaAMercado(): void
    {
        $history = $this->history([
            99 => [100.0, 106.0, 99.5, 105.0],
        ]);
        $timeline = [$this->point($history, 10, 'BUY', 90.0, 90.0)];

        $trade = $this->simulator()->replay('ACME', $timeline, $history)['trades'][0];

        self::assertTrue($trade['pending']);
        self::assertSame('pending_at_cutoff', $trade['exit_reason']);
        self::assertSame(99, $trade['exit_index']);
        self::assertSame(105.0, $trade['exit_price']);
        self::assertSame(5.0, $trade['managed_return']);
        self::assertSame(5.0, $trade['mfe_close_pct'], 'El ultimo cierre tambien cuenta para el MFE de una operacion pendiente.');
    }

    public function testMfeSoloCuentaCierresAnterioresALaSalidaPorStop(): void
    {
        [$history, $timeline] = $this->rallyThenDrop();

        $trade = $this->simulator()->replay('ACME', $timeline, $history)['trades'][0];

        // Maximo cierre entre la entrada (11) y el dia anterior a la salida (25): 130.
        self::assertSame(30.0, $trade['mfe_close_pct']);
    }

    public function testLasViolacionesDeContabilidadSeDetectan(): void
    {
        $replay = [
            'ticker' => 'ACME',
            'trades' => [
                ['entry_date' => '2024-01-12', 'exit_index' => 50, 'fixed_exit_index' => 40, 'initial_stop' => 90.0, 'final_stop' => 95.0],
                ['entry_date' => '2024-02-01', 'exit_index' => 30, 'fixed_exit_index' => 40, 'initial_stop' => 90.0, 'final_stop' => 85.0],
                ['entry_date' => '2024-03-01', 'exit_index' => 30, 'fixed_exit_index' => 30, 'initial_stop' => 90.0, 'final_stop' => 90.0],
            ],
        ];

        $violations = $this->simulator()->accountingViolations($replay);

        self::assertCount(2, $violations);
        self::assertStringContainsString('POSTERIOR', $violations[0]);
        self::assertStringContainsString('inferior', $violations[1]);
    }
}
