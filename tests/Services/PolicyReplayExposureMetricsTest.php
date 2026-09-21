<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\PolicyReplayExposureMetrics;

final class PolicyReplayExposureMetricsTest extends TestCase
{
    /**
     * @return array{entry_date: string, exit_date: string, pending: bool, baseline_exit_date: ?string}
     */
    private function trade(string $entry, string $exit, ?string $baselineExit, bool $pending = false): array
    {
        return ['entry_date' => $entry, 'exit_date' => $exit, 'pending' => $pending, 'baseline_exit_date' => $baselineExit];
    }

    public function testCuentaLaExposicionComoElMaximoEntreSalidaGestionadaYComparador(): void
    {
        $replays = [[
            'ticker' => 'ACME',
            'trades' => [
                // Sale en 10 dias, el comparador a los 28: exposicion 28 (no supera 91).
                $this->trade('2024-01-01', '2024-01-11', '2024-01-29'),
                // Gestionada a 120 dias: supera 91.
                $this->trade('2024-01-01', '2024-04-30', '2024-01-29'),
                // Exactamente 91 dias, cerrada: NO supera (es "> 91").
                $this->trade('2024-01-01', '2024-04-01', '2024-01-29'),
                // 92 dias: supera.
                $this->trade('2024-01-01', '2024-04-02', '2024-01-29'),
            ],
        ]];

        $result = (new PolicyReplayExposureMetrics())->exposureShare($replays, new DateTimeImmutable('2025-01-01'));

        self::assertSame(4, $result['entries_total']);
        self::assertSame(4, $result['cohort']);
        self::assertSame(2, $result['over']);
        self::assertSame(50.0, $result['share_pct']);
        self::assertSame(0, $result['pending_at_boundary']);
    }

    public function testLaCohorteExcluyeEntradasSinNoventaYUnDiasHastaElCorte(): void
    {
        $replays = [[
            'ticker' => 'ACME',
            'trades' => [
                $this->trade('2024-01-01', '2024-01-11', '2024-01-29'),
                // A 60 dias del corte: censurada, fuera de la cohorte.
                $this->trade('2024-11-01', '2024-12-31', null, true),
                // A exactamente 91 dias del corte: dentro.
                $this->trade('2024-10-01', '2024-12-31', null, true),
            ],
        ]];

        $result = (new PolicyReplayExposureMetrics())->exposureShare($replays, new DateTimeImmutable('2024-12-31'));

        self::assertSame(3, $result['entries_total']);
        self::assertSame(2, $result['cohort']);
        self::assertSame(0, $result['over']);
        self::assertSame(1, $result['pending_at_boundary'], 'La pendiente con exposicion exactamente igual a 91 no cuenta como > 91, pero se anota.');
    }

    /**
     * Reproduccion de Astra (C6): entrada 03/01/2024, ultimo dato 19/02/2024
     * (pendiente, 47 dias de seguimiento), corte global 31/12/2024. Antes
     * entraba en la cohorte y daba S91 = 0%.
     */
    public function testUnaPendienteConHistoricoCortoAntesDeLosNoventaYUnDiasQuedaCensurada(): void
    {
        $replays = [[
            'ticker' => 'ACME',
            'trades' => [$this->trade('2024-01-03', '2024-02-19', '2024-01-31', pending: true)],
        ]];

        $result = (new PolicyReplayExposureMetrics())->exposureShare($replays, new DateTimeImmutable('2024-12-31'));

        self::assertSame(0, $result['cohort']);
        self::assertNull($result['share_pct'], 'Sin seguimiento no hay porcentaje, no 0%.');
        self::assertSame(1, $result['censored_short_followup']);
        self::assertSame(0, $result['over']);
    }

    public function testUnaCerradaTempranaTieneResultadoConocidoAunqueElHistoricoTermine(): void
    {
        $replays = [[
            'ticker' => 'ACME',
            'trades' => [
                // Cerrada por stop a los 10 dias, comparador a los 28: resultado conocido, no supera 91.
                $this->trade('2024-01-03', '2024-01-13', '2024-01-31'),
                // Pendiente con seguimiento completo (hasta 2024-06-01, 150 dias): supera 91.
                $this->trade('2024-01-03', '2024-06-01', '2024-01-31', pending: true),
                // Pendiente con seguimiento EXACTO de 91 dias: en cohorte, no cuenta como > 91.
                $this->trade('2024-01-03', '2024-04-03', '2024-01-31', pending: true),
            ],
        ]];

        $result = (new PolicyReplayExposureMetrics())->exposureShare($replays, new DateTimeImmutable('2024-12-31'));

        self::assertSame(3, $result['entries_total']);
        self::assertSame(3, $result['cohort']);
        self::assertSame(1, $result['over']);
        self::assertSame(1, $result['pending_at_boundary']);
        self::assertSame(0, $result['censored_short_followup']);
        self::assertSame(0, $result['not_yet_in_window']);
    }

    public function testLasEntradasSinNoventaYUnDiasHastaElCorteSeCuentanAparte(): void
    {
        $replays = [[
            'ticker' => 'ACME',
            'trades' => [$this->trade('2024-12-01', '2024-12-31', '2024-12-29', pending: true)],
        ]];

        $result = (new PolicyReplayExposureMetrics())->exposureShare($replays, new DateTimeImmutable('2024-12-31'));

        self::assertSame(1, $result['not_yet_in_window']);
        self::assertSame(0, $result['cohort']);
        self::assertSame(0, $result['censored_short_followup']);
    }

    public function testSinCohorteNoHayPorcentaje(): void
    {
        $result = (new PolicyReplayExposureMetrics())->exposureShare([], new DateTimeImmutable('2024-12-31'));

        self::assertSame(0, $result['cohort']);
        self::assertNull($result['share_pct']);
    }
}
