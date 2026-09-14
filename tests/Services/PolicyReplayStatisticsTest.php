<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\PolicyReplayStatistics;

/**
 * `PolicyReplayStatistics` (Entrega 3/4 de
 * `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`): agrega los resultados de
 * `PolicyReplaySimulator::replay()` de muchos tickers en la medicion de
 * utilidad economica.
 *
 * El diseño de bloques temporales (`t_stat_blocked`) se corrigio el
 * `2026-09-14` tras una segunda consulta a `auditor-estadistico`: el
 * primer diseño (una cadena que se extiende mientras la siguiente entrada
 * caiga antes de que salga la mas tardia del bloque anterior, MEZCLANDO
 * tickers distintos) colapsaba casi todo en 1-2 bloques con universos
 * grandes y holdings largos -- verificado con un piloto real de 60
 * tickers (82 diferencias -> solo 3 bloques). El diseño actual particiona
 * el CALENDARIO en ventanas de ancho fijo (mediana de duracion de las
 * propias operaciones, en dias naturales), no una cadena por solape.
 */
final class PolicyReplayStatisticsTest extends TestCase
{
    /**
     * Fecha exacta a `$daysFromEpoch` dias de una fecha fija (2024-01-01):
     * evita tener que contar meses a mano para construir fixtures del
     * diseño de ventanas de calendario.
     */
    private function dateAt(int $daysFromEpoch): string
    {
        return (new DateTimeImmutable('2024-01-01'))->modify("+{$daysFromEpoch} days")->format('Y-m-d');
    }

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
     * `W` (anchura de bloque) = mediana de la duracion de las propias
     * operaciones. Con las tres operaciones de este test duran
     * exactamente 30 dias cada una, `W=30`. Dos entradas (dia 0 y dia 10)
     * caen en la MISMA ventana de calendario `[0,30)` y se promedian a un
     * solo bloque; la tercera (dia 100) cae en la ventana `[90,120)`,
     * bloque aparte.
     */
    public function testDosOperacionesEnLaMismaVentanaDeCalendarioSePromedianEnUnBloque(): void
    {
        $replays = [
            $this->replay('AAA', [
                $this->trade($this->dateAt(0), $this->dateAt(30), 10.0, 0.0),
                $this->trade($this->dateAt(10), $this->dateAt(40), 20.0, 0.0),
            ]),
            $this->replay('BBB', [
                $this->trade($this->dateAt(100), $this->dateAt(130), -5.0, 0.0),
            ]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(3, $summary['cohorts_naive']);
        self::assertSame(30, $summary['block_width_days']);
        self::assertSame(2, $summary['cohorts_blocked'], 'Las dos primeras comparten ventana de calendario; la tercera, muy posterior, cae en otra.');
        self::assertSame(5.0, $summary['avg_diff_blocked'], 'Media de los bloques [15,0 (media de 10 y 20)] y [-5,0]: (15+(-5))/2.');
    }

    /**
     * Borde de la ventana: con `W=30` a partir del dia 0, una entrada
     * exactamente en el dia 30 cae en la ventana SIGUIENTE (`[30,60)`),
     * no en la primera (`[0,30)`) -- el intervalo es semiabierto por la
     * derecha.
     */
    public function testUnaEntradaExactamenteEnElLimiteDeLaVentanaEmpiezaLaSiguiente(): void
    {
        $replays = [
            $this->replay('AAA', [
                $this->trade($this->dateAt(0), $this->dateAt(30), 4.0, 0.0),
                $this->trade($this->dateAt(30), $this->dateAt(60), 6.0, 0.0),
            ]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(2, $summary['cohorts_blocked']);
    }

    /**
     * Consenso de `auditor-estadistico` (`2026-09-14`): con menos de 10
     * bloques, `t_stat_blocked` se calcula igual (diagnostico), pero el
     * hallazgo se marca como NO CONCLUYENTE -- predeclarado antes de medir
     * el universo completo, no decidido despues de ver el numero.
     */
    public function testElDisenoBloqueadoNoEsConcluyenteConMenosDeDiezBloques(): void
    {
        $trades = [];

        for ($i = 0; $i < 9; $i++) {
            $trades[] = $this->trade($this->dateAt($i * 10), $this->dateAt($i * 10 + 1), 1.0, 0.0);
        }

        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)]);

        self::assertSame(9, $summary['cohorts_blocked']);
        self::assertFalse($summary['blocked_design_conclusive']);
    }

    public function testElDisenoBloqueadoEsConcluyenteConDiezBloquesOMas(): void
    {
        $trades = [];

        for ($i = 0; $i < 10; $i++) {
            $trades[] = $this->trade($this->dateAt($i * 10), $this->dateAt($i * 10 + 1), 1.0, 0.0);
        }

        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)]);

        self::assertSame(10, $summary['cohorts_blocked']);
        self::assertTrue($summary['blocked_design_conclusive']);
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
