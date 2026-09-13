<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\PolicyReplayStatistics;

/**
 * `PolicyReplayStatistics` (Entrega 3/4 de
 * `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`): agrega los resultados de
 * `PolicyReplaySimulator::replay()` de muchos tickers en la medicion de
 * utilidad economica.
 */
final class PolicyReplayStatisticsTest extends TestCase
{
    /**
     * @return array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: float, baseline_return: ?float, baseline_pending: bool, revisar_tesis_events: int}
     */
    private function trade(
        string $entryDate,
        string $exitDate,
        float $managedReturn,
        ?float $baselineReturn,
        bool $pending = false,
        int $revisarTesisEvents = 0
    ): array {
        return [
            'entry_date' => $entryDate,
            'entry_index' => 0,
            'entry_price' => 100.0,
            'exit_date' => $exitDate,
            'exit_index' => 1,
            'exit_price' => 100.0,
            'exit_reason' => $pending ? 'pending_at_cutoff' : 'stop_loss',
            'pending' => $pending,
            'holding_days' => 1,
            'managed_return' => $managedReturn,
            'baseline_return' => $baselineReturn,
            'baseline_pending' => $baselineReturn === null,
            'revisar_tesis_events' => $revisarTesisEvents,
        ];
    }

    /**
     * @param list<array<string,mixed>> $trades
     * @return array{ticker: string, trades: list<array<string,mixed>>, entries_total: int, entries_closed: int, entries_pending: int}
     */
    private function replay(string $ticker, array $trades): array
    {
        return [
            'ticker' => $ticker,
            'trades' => $trades,
            'entries_total' => count($trades),
            'entries_closed' => count(array_filter($trades, static fn (array $t): bool => !$t['pending'])),
            'entries_pending' => count(array_filter($trades, static fn (array $t): bool => $t['pending'])),
        ];
    }

    public function testSinOperacionesTodoSaleNulo(): void
    {
        $summary = (new PolicyReplayStatistics())->summarize([]);

        self::assertSame(0, $summary['entries_total']);
        self::assertNull($summary['avg_diff_naive']);
        self::assertNull($summary['t_stat_naive']);
        self::assertNull($summary['avg_diff_blocked']);
    }

    /**
     * Dos operaciones cerradas de tickers distintos, en fechas
     * suficientemente separadas como para no compartir ningun bloque: la
     * media pareada es la media simple de las diferencias, y ambos
     * disenos (naive/blocked) deben coincidir porque no hay solape que
     * corregir.
     */
    public function testDiferenciaPareadaSimpleSinSolapeCoincideEnAmbosDisenos(): void
    {
        $replays = [
            $this->replay('AAA', [$this->trade('2024-01-10', '2024-02-10', 8.0, 5.0)]),
            $this->replay('BBB', [$this->trade('2024-06-10', '2024-07-10', 2.0, 5.0)]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        // Diferencias: +3,00 y -3,00 -> media 0, pero se comprueba cada
        // pieza para no perder precision de redondeo en la propia media.
        self::assertSame(2, $summary['cohorts_naive']);
        self::assertSame(0.0, $summary['avg_diff_naive']);
        self::assertSame(2, $summary['cohorts_blocked'], 'Fechas de enero y junio no se solapan: cada una es su propio bloque.');
        self::assertSame($summary['avg_diff_naive'], $summary['avg_diff_blocked']);
        self::assertSame($summary['t_stat_naive'], $summary['t_stat_blocked']);
    }

    /**
     * Tres operaciones cuyas ventanas de tenencia se solapan en el tiempo
     * (entrada de la siguiente antes de que termine la anterior) deben
     * agruparse en UN SOLO bloque: el t-stat "blocked" pierde grados de
     * libertad frente al "naive", que las trata como si fueran
     * independientes.
     */
    public function testOperacionesConVentanasSolapadasSeAgrupanEnUnSoloBloque(): void
    {
        $replays = [
            $this->replay('AAA', [
                $this->trade('2024-01-01', '2024-03-01', 10.0, 0.0),
                $this->trade('2024-02-01', '2024-04-01', 10.0, 0.0), // entra ANTES de que la anterior salga
                $this->trade('2024-03-15', '2024-05-01', 10.0, 0.0), // entra ANTES de que la 2a salga
            ]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(3, $summary['cohorts_naive']);
        self::assertSame(1, $summary['cohorts_blocked'], 'Las tres ventanas se solapan en cadena: un unico bloque.');
        self::assertSame(10.0, $summary['avg_diff_blocked']);
        self::assertNull($summary['t_stat_blocked'], 'Un solo bloque no tiene varianza que calcular (n=1).');
    }

    /**
     * Una operacion que entra DESPUES de que la anterior ya haya salido
     * empieza un bloque nuevo: no toda secuencia de operaciones colapsa en
     * un unico bloque.
     */
    public function testUnaEntradaPosteriorALaSalidaDeLaAnteriorEmpiezaBloqueNuevo(): void
    {
        $replays = [
            $this->replay('AAA', [
                $this->trade('2024-01-01', '2024-02-01', 4.0, 0.0),
                $this->trade('2024-03-01', '2024-04-01', 6.0, 0.0), // entra DESPUES de que la anterior ya salio
            ]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(2, $summary['cohorts_blocked']);
    }

    public function testLasOperacionesPendientesQuedanExcluidasDeLaMetricaPrimaria(): void
    {
        $replays = [
            $this->replay('AAA', [
                $this->trade('2024-01-01', '2024-02-01', 5.0, 2.0),
                $this->trade('2024-06-01', '2024-09-01', 999.0, null, pending: true),
            ]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(2, $summary['entries_total']);
        self::assertSame(1, $summary['entries_closed']);
        self::assertSame(1, $summary['entries_pending']);
        self::assertSame(50.0, $summary['pct_pending']);
        self::assertSame(1, $summary['cohorts_naive'], 'La pendiente no entra en el contraste pareado.');
        self::assertSame(3.0, $summary['avg_diff_naive']);
        self::assertSame(999.0, $summary['pending_avg_managed_return']);
    }

    /**
     * Una operacion YA CERRADA por stop-loss pero cuyo comparador de
     * veinte sesiones todavia no tiene desenlace (`baseline_pending`)
     * cuenta como cerrada, pero no aporta una diferencia emparejable.
     */
    public function testUnaOperacionCerradaConComparadorPendienteNoAportaDiferenciaEmparejada(): void
    {
        $replays = [
            $this->replay('AAA', [$this->trade('2024-01-01', '2024-02-01', 5.0, null)]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(1, $summary['entries_closed']);
        self::assertSame(0, $summary['cohorts_naive']);
    }

    public function testCuentaLosEventosDeRevisarTesisDeTodasLasOperaciones(): void
    {
        $replays = [
            $this->replay('AAA', [$this->trade('2024-01-01', '2024-02-01', 5.0, 2.0, revisarTesisEvents: 2)]),
            $this->replay('BBB', [$this->trade('2024-01-01', '2024-02-01', 5.0, 2.0, revisarTesisEvents: 1)]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(3, $summary['revisar_tesis_events_total']);
    }
}
