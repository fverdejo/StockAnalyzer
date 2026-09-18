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
 * Diseño del `2026-09-15` (tercera consulta a `auditor-estadistico`, tras
 * un hallazgo real de Astra en `REVISION_REPLAY_MOTOR_ASTRA_2026-09-14.md`,
 * caso 4): estimando primario = media POR OPERACION; incertidumbre =
 * bootstrap de bloques moviles de calendario, no ventanas fijas por
 * cadena/ancho-de-holding (los dos diseños anteriores, ambos con huecos
 * reales encontrados por Astra -- ver el docblock de la clase).
 *
 * Los tests que necesitan reproducibilidad exacta del bootstrap pasan
 * `$seed` explicito: `mt_rand()` con `mt_srand()` fijo es determinista
 * dentro de la misma build de PHP.
 */
final class PolicyReplayStatisticsTest extends TestCase
{
    private const SEED = 20260915;

    /**
     * Fecha exacta a `$daysFromEpoch` dias de una fecha fija (2024-01-01):
     * evita tener que contar meses a mano.
     */
    private function dateAt(int $daysFromEpoch): string
    {
        return (new DateTimeImmutable('2024-01-01'))->modify("+{$daysFromEpoch} days")->format('Y-m-d');
    }

    /**
     * @return array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: float, baseline_return: ?float, baseline_exit_date: ?string, baseline_pending: bool, revisar_tesis_events: int}
     */
    private function trade(
        string $entryDate,
        string $exitDate,
        float $managedReturn,
        ?float $baselineReturn,
        ?string $baselineExitDate = null,
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
            'baseline_exit_date' => $baselineExitDate ?? ($baselineReturn === null ? null : $exitDate),
            'baseline_pending' => $baselineReturn === null,
            'revisar_tesis_events' => $revisarTesisEvents,
        ];
    }

    /**
     * @param list<array<string,mixed>> $trades
     * @return array{ticker: string, trades: list<array<string,mixed>>, entries_total: int, entries_closed: int, entries_pending: int, candidates_excluded_by_membership: int}
     */
    private function replay(string $ticker, array $trades, int $excludedByMembership = 0): array
    {
        return [
            'ticker' => $ticker,
            'trades' => $trades,
            'entries_total' => count($trades),
            'entries_closed' => count(array_filter($trades, static fn (array $t): bool => !$t['pending'])),
            'entries_pending' => count(array_filter($trades, static fn (array $t): bool => $t['pending'])),
            'candidates_excluded_by_membership' => $excludedByMembership,
        ];
    }

    public function testSinOperacionesTodoSaleNulo(): void
    {
        $summary = (new PolicyReplayStatistics())->summarize([]);

        self::assertSame(0, $summary['entries_total']);
        self::assertSame(0, $summary['cohorts']);
        self::assertNull($summary['avg_diff']);
        self::assertNull($summary['se_bootstrap']);
        self::assertNull($summary['ci95_low']);
        self::assertFalse($summary['result_informative']);
    }

    /**
     * Con una sola operacion emparejable, el punto estimado es esa unica
     * diferencia, pero no hay variabilidad que remuestrear -- el bootstrap
     * (que necesita al menos dos observaciones) sale `null`, no una cifra
     * inventada.
     */
    public function testConUnaSolaOperacionElBootstrapNoSeCalcula(): void
    {
        $replays = [$this->replay('AAA', [$this->trade($this->dateAt(0), $this->dateAt(5), 8.0, 5.0)])];

        $summary = (new PolicyReplayStatistics())->summarize($replays, self::SEED);

        self::assertSame(1, $summary['cohorts']);
        self::assertSame(3.0, $summary['avg_diff']);
        self::assertNull($summary['se_bootstrap']);
        self::assertNull($summary['ci95_low']);
        self::assertFalse($summary['result_informative']);
    }

    /**
     * Hallazgo real de Astra (`REVISION_REPLAY_MOTOR_ASTRA_2026-09-14.md`,
     * caso 4, segundo ejemplo): el punto estimado tiene que ser la media
     * POR OPERACION, sin importar como se agrupen en el tiempo. Fixture
     * literal de Astra: 100 diferencias de +1pp muy juntas en el
     * calendario, y 9 diferencias de -1pp muy separadas entre si -- la
     * media por operacion es (100x1 + 9x(-1))/109 ~ +0,83pp; la media "por
     * ventana" (el diseño anterior, ya retirado) habria dado -0,80pp. Solo
     * se comprueba `avg_diff`, no el bootstrap (que depende del ancho de
     * bloque, no de esto).
     */
    public function testElPuntoEstimadoEsLaMediaPorOperacionNoPorVentanaTemporal(): void
    {
        $trades = [];

        for ($i = 0; $i < 100; $i++) {
            // Las 100 entran el mismo dia (mismo instante de mercado),
            // holding de 1 dia: caerian todas en la misma ventana de
            // calendario del diseño ya retirado.
            $trades[] = $this->trade($this->dateAt(0), $this->dateAt(1), 6.0, 5.0);
        }

        for ($i = 0; $i < 9; $i++) {
            // Muy separadas entre si (2.000 dias = ~5,5 años): cada una
            // séria su propia ventana en el diseño ya retirado.
            $trades[] = $this->trade($this->dateAt(2000 * ($i + 1)), $this->dateAt(2000 * ($i + 1) + 1), 4.0, 5.0);
        }

        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)]);

        self::assertSame(109, $summary['cohorts']);
        $expected = round((100 * 1.0 + 9 * (-1.0)) / 109, 2);
        self::assertSame($expected, $summary['avg_diff']);
        self::assertGreaterThan(0.0, $summary['avg_diff'], 'Media por operacion: positiva. La media por ventana (diseño retirado) habria sido negativa.');
    }

    public function testLasOperacionesPendientesQuedanExcluidasDeLaMetricaPrimaria(): void
    {
        $replays = [
            $this->replay('AAA', [
                $this->trade($this->dateAt(0), $this->dateAt(5), 5.0, 2.0),
                $this->trade($this->dateAt(100), $this->dateAt(120), 999.0, null, pending: true),
            ]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(2, $summary['entries_total']);
        self::assertSame(1, $summary['entries_closed']);
        self::assertSame(1, $summary['entries_pending']);
        self::assertSame(50.0, $summary['pct_pending']);
        self::assertSame(1, $summary['cohorts'], 'La pendiente no entra en el contraste pareado.');
        self::assertSame(3.0, $summary['avg_diff']);
        self::assertSame(999.0, $summary['pending_avg_managed_return']);
    }

    /**
     * Una operacion YA CERRADA por stop-loss pero cuyo comparador de
     * veinte sesiones todavia no tiene desenlace (`baseline_exit_date`
     * nulo) cuenta como cerrada, pero no aporta una diferencia emparejable
     * -- sin fecha de salida del comparador tampoco hay forma de calcular
     * su ventana de exposicion para el bootstrap.
     */
    public function testUnaOperacionCerradaConComparadorPendienteNoAportaDiferenciaEmparejada(): void
    {
        $replays = [
            $this->replay('AAA', [$this->trade($this->dateAt(0), $this->dateAt(5), 5.0, null)]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(1, $summary['entries_closed']);
        self::assertSame(0, $summary['cohorts']);
    }

    public function testLasCandidatasExcluidasPorPertenenciaSeSumanDeTodosLosTickers(): void
    {
        $replays = [
            $this->replay('AAA', [$this->trade($this->dateAt(0), $this->dateAt(5), 5.0, 2.0)], excludedByMembership: 3),
            $this->replay('BBB', [$this->trade($this->dateAt(0), $this->dateAt(5), 5.0, 2.0)], excludedByMembership: 2),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(5, $summary['candidates_excluded_by_membership_total']);
    }

    public function testCuentaLosEventosDeRevisarTesisDeTodasLasOperaciones(): void
    {
        $replays = [
            $this->replay('AAA', [$this->trade($this->dateAt(0), $this->dateAt(5), 5.0, 2.0, revisarTesisEvents: 2)]),
            $this->replay('BBB', [$this->trade($this->dateAt(0), $this->dateAt(5), 5.0, 2.0, revisarTesisEvents: 1)]),
        ];

        $summary = (new PolicyReplayStatistics())->summarize($replays);

        self::assertSame(3, $summary['revisar_tesis_events_total']);
    }

    /**
     * Mismo `$seed` y misma entrada -> mismo resultado exacto (Entrega 2:
     * mismo criterio de reproducibilidad que `$asOf`). Sin esto, la
     * medicion real no se podria repetir para verificarla.
     */
    public function testElBootstrapConLaMismaSemillaEsReproducible(): void
    {
        $trades = $this->manyIndependentTrades(40);
        $replays = [$this->replay('AAA', $trades)];

        $first = (new PolicyReplayStatistics())->summarize($replays, self::SEED);
        $second = (new PolicyReplayStatistics())->summarize($replays, self::SEED);

        self::assertSame($first['se_bootstrap'], $second['se_bootstrap']);
        self::assertSame($first['ci95_low'], $second['ci95_low']);
        self::assertSame($first['ci95_high'], $second['ci95_high']);
    }

    public function testElIntervaloDeConfianzaTieneElLimiteInferiorPorDebajoDelSuperior(): void
    {
        $trades = $this->manyIndependentTrades(40);
        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)], self::SEED);

        self::assertLessThanOrEqual($summary['ci95_high'], $summary['ci95_low']);
    }

    /**
     * Regresion de un hallazgo real de Astra
     * (`REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md`, caso 1, prioridad
     * inmediata): `se_bootstrap` se calculaba con `pairedStats()`, que
     * divide la desviacion tipica por `sqrt(n)` -- correcto para el error
     * estandar de una MEDIA de observaciones i.i.d., pero aqui los
     * valores YA son las medias de las 5.000 replicas del bootstrap, cuya
     * desviacion tipica ES la incertidumbre buscada. El bug encogia
     * `se_bootstrap` por un factor de `sqrt(5000)~=70,71` -- verificado
     * por Astra reconstruyendo los mismos sorteos con la misma semilla
     * sobre datos reales archivados (piloto del `2026-09-15`): la
     * desviacion real (1,084) es del mismo orden que `se_naive` (1,049),
     * nunca la cifra que se publicaba entonces (0,015). Para datos
     * razonablemente independientes, `se_bootstrap` no puede ser un orden
     * de magnitud mas pequeño que `se_naive`.
     */
    public function testSeBootstrapNoSeEncogePorLaRaizDeLasReplicas(): void
    {
        $trades = $this->manyIndependentTrades(40);
        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)], self::SEED);

        self::assertNotNull($summary['se_bootstrap']);
        self::assertNotNull($summary['se_naive']);
        self::assertGreaterThan(
            $summary['se_naive'] / 10,
            $summary['se_bootstrap'],
            'se_bootstrap no puede ser un orden de magnitud mas pequeño que se_naive para datos casi independientes -- señal de que se volvio a dividir por sqrt(numero de replicas).'
        );
    }

    /**
     * Consenso de `auditor-estadistico` (`2026-09-15`): por debajo de 30
     * operaciones EFECTIVAS (tras corregir por dependencia temporal), el
     * resultado se marca no informativo -- aqui ni siquiera hay 30
     * operaciones EN BRUTO, asi que el efectivo tiene que ser menor.
     */
    public function testResultInformativeEsFalsoConMuyPocasOperaciones(): void
    {
        $trades = $this->manyIndependentTrades(5);
        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)], self::SEED);

        self::assertFalse($summary['result_informative']);
    }

    /**
     * Con suficientes operaciones BIEN SEPARADAS en el tiempo (sin
     * exposicion compartida entre ellas), el tamaño efectivo deberia
     * acercarse al bruto -- informativo con 40 operaciones independientes.
     */
    public function testResultInformativeEsVerdaderoConSuficientesOperacionesBienSeparadas(): void
    {
        $trades = $this->manyIndependentTrades(40);
        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)], self::SEED);

        self::assertTrue($summary['result_informative'], "effective_n={$summary['effective_n']}, cohorts={$summary['cohorts']}");
        self::assertGreaterThanOrEqual(30.0, (float) $summary['effective_n']);
    }

    /**
     * Regresion de un hallazgo real, encontrado con un piloto sobre datos
     * reales (60 tickers/2 años, `2026-09-15`), NO por Astra: un rango
     * temporal total CORTO frente a la anchura de bloque colapsa
     * `se_bootstrap` de forma artificial (en el piloto real: 0,015 frente
     * a un `se_naive` de 1,049, un `pseudo_t` de -377 -- la misma clase de
     * cifra absurda que motivo la correccion del caso 4 de Astra, solo que
     * causada por una razon distinta: aqui no hace falta que las
     * operaciones AL PROPIO tiempo esten muy solapadas, basta con que el
     * bootstrap no tenga sitio para sortear posiciones de bloque
     * realmente distintas entre si). Fixture: muchas operaciones repartidas
     * en solo ~2 años (700 dias), cada una con una ventana de exposicion
     * larga (~200 dias) -- el ancho de bloque resultante (2x200=400) apenas
     * cabe dos veces en el rango total.
     */
    public function testResultInformativeEsFalsoSinResolucionSuficienteDeBootstrapAunqueHayaMuchasOperaciones(): void
    {
        $trades = [];

        for ($i = 0; $i < 34; $i++) {
            $entry = (int) round($i * (700 / 34));
            $trades[] = $this->trade(
                $this->dateAt($entry),
                $this->dateAt($entry + 200),
                5.0 + ($i % 5),
                5.0,
                $this->dateAt($entry + 195)
            );
        }

        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)], self::SEED);

        self::assertSame(34, $summary['cohorts']);
        self::assertFalse($summary['bootstrap_has_enough_resolution']);
        self::assertFalse($summary['result_informative'], 'Muchas operaciones no bastan si el bootstrap no tiene resolucion temporal real.');
    }

    /**
     * Regresion del bootstrap CIRCULAR (Astra/`auditor-estadistico`,
     * `2026-09-18`, al revisar la medicion completa de 636 tickers):
     * antes de esta correccion, la operacion mas cercana al INICIO del
     * rango temporal solo podia caer dentro de un unico punto de arranque
     * de bloque posible (`blockStart=0`), mientras una operacion del
     * centro caia dentro de `blockWidthDays` puntos distintos -- un sesgo
     * de inclusion real, verificado sobre los 2.842 pares de esa medicion
     * (la primera operacion, ~1000x menos representada que una del
     * centro). Con el bootstrap envuelto como un circulo, TODAS las
     * posiciones tienen la misma probabilidad de arranque.
     *
     * Fixture: la operacion MAS ANTIGUA (por `entry_date`) tiene una
     * diferencia extrema (3.400, frente a 0 en las demas 33) -- si
     * siguiera infrarrepresentada, el percentil 97,5 del bootstrap se
     * quedaria pegado a 0 (la operacion casi nunca se muestrearia);
     * con inclusion justa, algunas replicas SI la capturan y el percentil
     * alto debe reflejarlo con un valor claramente positivo.
     */
    public function testLaOperacionMasAntiguaDelRangoNoQuedaInfrarrepresentadaEnElBootstrap(): void
    {
        $trades = [];

        for ($i = 0; $i < 34; $i++) {
            $entry = (int) round($i * (700 / 34));
            $trades[] = $this->trade(
                $this->dateAt($entry),
                $this->dateAt($entry + 200),
                $i === 0 ? 3405.0 : 5.0,
                5.0,
                $this->dateAt($entry + 195)
            );
        }

        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)], self::SEED);

        self::assertGreaterThan(
            20.0,
            $summary['ci95_high'],
            'La operacion mas antigua del rango debe poder aparecer en las replicas del bootstrap -- un percentil alto pegado a 0 indicaria que sigue infrarrepresentada.'
        );
    }

    /**
     * `calendar_windows_observed` (el diseño de bloques por ancho fijo,
     * ya retirado como criterio de decision) se sigue calculando como
     * campo puramente descriptivo -- no decide nada, pero no desaparece.
     */
    public function testCalendarWindowsObservedEsSoloDescriptivo(): void
    {
        $trades = $this->manyIndependentTrades(10);
        $summary = (new PolicyReplayStatistics())->summarize([$this->replay('AAA', $trades)]);

        self::assertGreaterThan(0, $summary['calendar_windows_observed']);
        self::assertArrayNotHasKey('blocked_design_conclusive', $summary);
    }

    /**
     * 40 operaciones separadas por 200 dias cada una (holding y
     * comparador ambos resueltos en menos de 20 dias): ninguna exposicion
     * se solapa con otra, asi que el efecto de diseño deberia acercarse a
     * 1 (independencia casi total).
     *
     * @return list<array<string,mixed>>
     */
    private function manyIndependentTrades(int $count): array
    {
        $trades = [];

        for ($i = 0; $i < $count; $i++) {
            $entry = 200 * $i;
            // Retorno gestionado variable (no constante): con una
            // diferencia identica en todas las operaciones la varianza
            // "naive" sale exactamente 0, lo que deja indefinido el efecto
            // de diseño (SE_bootstrap/SE_naive) -- nunca pasaria con datos
            // reales, pero un fixture de prueba si puede caer ahi por
            // accidente si no se declara a proposito.
            $managedReturn = 5.0 + ($i % 5);
            $trades[] = $this->trade($this->dateAt($entry), $this->dateAt($entry + 5), $managedReturn, 5.0, $this->dateAt($entry + 20));
        }

        return $trades;
    }
}
