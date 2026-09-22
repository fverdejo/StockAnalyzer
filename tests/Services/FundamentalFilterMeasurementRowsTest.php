<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\FundamentalFilterMeasurementRows;

/**
 * `FundamentalFilterMeasurementRows` (protocolo de utilidad del motor,
 * predeclaracion cerrada del 2026-09-22, `versions.md`).
 */
final class FundamentalFilterMeasurementRowsTest extends TestCase
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
    private function trade(array $history, int $entryIndex, int $exitIndex, float $managedReturn, bool $pending = false): array
    {
        return [
            'entry_date' => $history[$entryIndex]->getDate()->format('Y-m-d'),
            'entry_index' => $entryIndex,
            'entry_price' => $history[$entryIndex]->getOpen(),
            'exit_date' => $history[$exitIndex]->getDate()->format('Y-m-d'),
            'exit_index' => $exitIndex,
            'exit_price' => $history[$exitIndex]->getClose(),
            'exit_reason' => $pending ? 'pending_at_cutoff' : 'stop_loss',
            'pending' => $pending,
            'managed_return' => $managedReturn,
        ];
    }

    /**
     * @return array{value: ?float, evaluable: bool, snapshot_date: ?string, signal_date: string, not_evaluable_reason: ?string}
     */
    private function de(?float $value, bool $evaluable = true, ?string $reason = null): array
    {
        return ['value' => $value, 'evaluable' => $evaluable, 'snapshot_date' => '2024-01-01', 'signal_date' => '2024-01-10', 'not_evaluable_reason' => $reason];
    }

    public function testUnaEntradaPendienteQueNuncaCruzaElStopTieneVAIgualAVC(): void
    {
        $history = $this->history();
        $trade = $this->trade($history, 10, 99, 5.0, pending: true);

        $rows = (new FundamentalFilterMeasurementRows())->rows('ACME', [$trade], $history, [10 => $this->de(1.0)], 'Technology');

        self::assertTrue($rows[0]['in_cohort']);
        self::assertSame($rows[0]['v_a'], $rows[0]['v_c'], 'Sin cruzar el stop antes de horizonte, V_A y V_C usan el mismo cierre.');
        self::assertSame(0.0, $rows[0]['d_prime']);
    }

    public function testUnaEntradaCerradaPorStopAntesDeHorizonteUsaElRetornoRealizadoEnVAYElCierreDeHorizonteEnVC(): void
    {
        $history = $this->history([
            20 => [90.0, 90.5, 84.0, 88.0],
            55 => [120.0, 121.0, 119.0, 120.0],
        ]);
        $trade = $this->trade($history, 10, 20, -12.0);

        $row = (new FundamentalFilterMeasurementRows(backtestingConfig: new BacktestingConfig(0.0)))->rows('ACME', [$trade], $history, [10 => $this->de(1.0)], 'Technology')[0];

        self::assertSame(-12.0, $row['v_a']);
        // V_C: open de entrada (100) -> cierre en indice 55 (120), sin costes (BacktestingConfig(0.0)).
        self::assertEqualsWithDelta(20.0, $row['v_c'], 1e-9);
        self::assertEqualsWithDelta(-32.0, $row['d_prime'], 1e-9);
    }

    public function testUnaEntradaFueraDeLaCohorteNoCalculaNingunValor(): void
    {
        $history = $this->history([], 40);
        $trade = $this->trade($history, 10, 39, 5.0, pending: true);

        $row = (new FundamentalFilterMeasurementRows())->rows('ACME', [$trade], $history, [10 => $this->de(1.0)], 'Technology')[0];

        self::assertFalse($row['in_cohort']);
        self::assertNull($row['v_a']);
        self::assertNull($row['v_c']);
        self::assertNull($row['v_b']);
        self::assertNull($row['d']);
        self::assertNull($row['d_prime']);
    }

    public function testConDeudaPatrimonioMenorQueDosVBEsIgualAVAYDEsCero(): void
    {
        $history = $this->history();
        $trade = $this->trade($history, 10, 99, 3.0, pending: true);

        $row = (new FundamentalFilterMeasurementRows())->rows('ACME', [$trade], $history, [10 => $this->de(1.99)], 'Technology')[0];

        self::assertFalse($row['de_rejected']);
        self::assertSame($row['v_a'], $row['v_b']);
        self::assertSame(0.0, $row['d']);
    }

    public function testConDeudaPatrimonioMayorOIgualQueDosVBEsCeroExacto(): void
    {
        $history = $this->history();
        $trade = $this->trade($history, 10, 99, 3.0, pending: true);

        $row = (new FundamentalFilterMeasurementRows())->rows('ACME', [$trade], $history, [10 => $this->de(2.0)], 'Technology')[0];

        self::assertTrue($row['de_rejected'], 'El umbral es >= 2,0, no solo > 2,0.');
        self::assertSame(0.0, $row['v_b']);
        self::assertSame(-$row['v_a'], $row['d']);
    }

    public function testUnDeNoEvaluableDejaVBYDNulosPeroConservaElMotivo(): void
    {
        $history = $this->history();
        $trade = $this->trade($history, 10, 99, 3.0, pending: true);

        $row = (new FundamentalFilterMeasurementRows())->rows('ACME', [$trade], $history, [10 => $this->de(null, evaluable: false, reason: 'sin_filing_previo_a_senal')], 'Technology')[0];

        self::assertFalse($row['de_evaluable']);
        self::assertNull($row['v_b']);
        self::assertNull($row['d']);
        self::assertSame('sin_filing_previo_a_senal', $row['de_not_evaluable_reason']);
        self::assertNotNull($row['v_a'], 'V_A/V_C no dependen de la evaluabilidad de D/E.');
    }

    public function testFaltaLaResolucionDeDeParaUnaEntradaLanzaExcepcion(): void
    {
        $history = $this->history();
        $trade = $this->trade($history, 10, 99, 3.0, pending: true);

        $this->expectException(LogicException::class);

        (new FundamentalFilterMeasurementRows())->rows('ACME', [$trade], $history, [], 'Technology');
    }

    public function testElMfeEsElMaximoCierreEntreLaEntradaYElHorizonteSobreElOpenDeEntrada(): void
    {
        $history = $this->history([
            30 => [130.0, 131.0, 129.0, 130.0],
        ]);
        $trade = $this->trade($history, 10, 99, 5.0, pending: true);

        $row = (new FundamentalFilterMeasurementRows())->rows('ACME', [$trade], $history, [10 => $this->de(1.0)], 'Technology')[0];

        self::assertEqualsWithDelta(30.0, $row['mfe_a_close'], 1e-9);
    }

    public function testElHorizonteEsConfigurable(): void
    {
        $history = $this->history([21 => [110.0, 111.0, 109.0, 110.0]]);
        $trade = $this->trade($history, 10, 99, 5.0, pending: true);

        $row = (new FundamentalFilterMeasurementRows(horizon: 11, backtestingConfig: new BacktestingConfig(0.0)))->rows('ACME', [$trade], $history, [10 => $this->de(1.0)], 'Technology')[0];

        self::assertEqualsWithDelta(10.0, $row['v_c'], 1e-9, 'Horizonte 11 desde la entrada de indice 10 (open=100) hasta el indice 21 (cierre=110).');
    }
}
