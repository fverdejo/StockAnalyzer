<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;

/**
 * Agrega los resultados de `PolicyReplaySimulator::replay()` de MUCHOS
 * tickers en una unica medicion de utilidad economica (Entrega 3/4 de
 * `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`).
 *
 * Metrica primaria: diferencia PAREADA por operacion entre el retorno
 * gestionado (siguiendo `PositionDecisionAdvisor` de verdad) y el
 * comparador de veinte sesiones fijas, SOLO sobre operaciones YA CERRADAS
 * por stop-loss (las pendientes al corte se excluyen de esta metrica,
 * consenso de `2026-09-13`: no se les atribuye una orden que la politica
 * nunca dio).
 *
 * **Historia del diseño estadistico, tres rondas con `auditor-estadistico`
 * -- cada una encontro un hueco real en la anterior, dejarlo escrito para
 * no repetir los mismos errores:**
 *
 * 1. (`2026-09-13`) Primer diseño: cada operacion como un voto
 *    independiente ("naive"). Problema: a diferencia de
 *    `runCrossSectional()` (donde `step >= horizonDays` GARANTIZA que dos
 *    fechas evaluadas nunca comparten ventana de retorno), aqui las
 *    operaciones duran meses variables sin horizonte fijo -- una entrada de
 *    marzo y otra de junio pueden compartir exposicion al mismo tramo de
 *    mercado. Se introduce un segundo contraste "blocked" como diagnostico.
 * 2. (`2026-09-14`) El primer "blocked" (una cadena que se extiende
 *    mientras la siguiente entrada caiga antes de que salga la mas tardia
 *    del bloque anterior, mezclando tickers distintos) degeneraba a 1-3
 *    bloques con universos grandes -- verificado con un piloto real (82
 *    diferencias -> 3 bloques). Se corrige a ventanas de CALENDARIO de
 *    ancho fijo (mediana de holding del brazo GESTIONADO).
 * 3. (`2026-09-15`, hallazgo real de Astra,
 *    `REVISION_REPLAY_MOTOR_ASTRA_2026-09-14.md`, caso 4) Ese segundo
 *    diseño segui­a sin garantizar independencia: solo miraba cuanto dura
 *    el brazo GESTIONADO, nunca la ventana de exposicion del brazo
 *    COMPARADOR (20 sesiones fijas desde la MISMA entrada, que puede
 *    solaparse con la de otras operaciones aunque el brazo gestionado sea
 *    brevisimo). Contraejemplo de Astra, reproducible con el codigo de
 *    entonces: 12 operaciones que salen a la sesion siguiente (holding
 *    gestionado ~1 dia) pero cuyos 12 comparadores de 20 sesiones
 *    comparten una misma sesion futura -- el diseño anterior daba 12
 *    "bloques independientes" de 1 dia y `t=-110,97`, una cifra absurda.
 *    Astra señala ademas un segundo defecto, no solo de incertidumbre sino
 *    de MAGNITUD: ponderar por VENTANA en vez de por OPERACION cambia el
 *    propio punto estimado (ejemplo de Astra: 100 diferencias de +1pp en
 *    una ventana y nueve diferencias de -1pp en nueve ventanas dan
 *    +0,83pp por operacion pero -0,80pp por ventana -- preguntas
 *    DISTINTAS, no solo precision distinta).
 *
 * **Diseño final (tercera consulta a `auditor-estadistico`, `2026-09-15`):**
 *
 * - **Estimando primario: media POR OPERACION**, peso igual a cada trade
 *   -- coherente con el resto del proyecto (cada muestra de
 *   `runCrossSectional()`, cada BUY, pesa igual sin importar en que tramo
 *   de calendario cae). Es la respuesta a "si actuo sobre una señal BUY,
 *   que exceso espero", no "que exceso tuvo cada tramo de mercado". Esto
 *   NUNCA cambia con el metodo de incertidumbre usado.
 * - **Incertidumbre: bootstrap de bloques MOVILES de calendario** (Künsch
 *   1989; Politis & White 2004), no HAC/Newey-West -- HAC exige una serie
 *   UNICA, regular y con periodos consecutivos igualmente espaciados
 *   (documentacion de `statsmodels.stats.sandwich_covariance.cov_hac`,
 *   citada por Astra); aqui hay N operaciones irregulares con dos brazos
 *   de duracion distinta, y agregarlas a una rejilla diaria reintroduce el
 *   mismo conflicto de ponderacion (¿que valor tiene un dia con varias
 *   operaciones activas?). El bootstrap de bloques evita ese paso.
 *   Algoritmo (`bootstrapUncertainty()`): anchura de bloque
 *   `W = 2 x percentil90(duracion de exposicion de cada operacion)`,
 *   donde "duracion de exposicion" es desde la entrada hasta la fecha MAS
 *   TARDIA entre la salida gestionada y la salida del comparador --
 *   cubriendo asi la ventana de AMBOS brazos, no solo el gestionado (la
 *   correccion central de esta ronda). Se sortean bloques de calendario
 *   de anchura `W` CON REEMPLAZO (no una particion fija) hasta cubrir el
 *   rango temporal total, se concatenan las operaciones de los bloques
 *   sorteados en una pseudo-muestra, y se repite 5.000 veces: el punto
 *   estimado sigue siendo la media real (no la media de las replicas), el
 *   error estandar es la desviacion tipica de las medias de las replicas,
 *   y el intervalo de confianza al 95% son los percentiles 2,5/97,5 de
 *   esas medias.
 * - **`MIN_CONCLUSIVE_BLOCKS` se retira.** El antiguo conteo de ventanas
 *   fijas se conserva SOLO como campo descriptivo
 *   (`calendar_windows_observed`, ya no "conclusive" de nada). El umbral
 *   real ahora es el TAMAÑO EFECTIVO DE MUESTRA tras el bootstrap
 *   (efecto de diseño de Kish: `DEFF = (SE_bootstrap/SE_naive)^2`,
 *   `n_efectivo = N/DEFF`) -- por debajo de `MIN_EFFECTIVE_N` (30), el
 *   resultado se marca explicitamente como no informativo, con
 *   independencia de si el intervalo de confianza excluye o no el cero.
 *
 * `avg_diff`/`se_naive`/`t_stat_naive` (asumiendo independencia i.i.d.,
 * SIN corregir por dependencia temporal) se conservan como diagnostico de
 * contraste: la divergencia entre `se_naive` y `se_bootstrap` es, en si
 * misma, la medida de cuanto exceso de confianza introduciria ignorar la
 * dependencia -- mismo espiritu que `alpha_t_stat` vs `pooled_alpha_t_stat`
 * en `BacktestingService::runCrossSectional()`.
 *
 * **Salvaguarda propia, no pedida explicitamente por `auditor-estadistico`
 * pero necesaria para que el diseño anterior no vuelva a producir un
 * numero absurdo** (verificado con un piloto real de 60 tickers/2 años el
 * `2026-09-15`, DESPUES de implementar el diseño de arriba): si el rango
 * temporal total cubierto por las operaciones es corto en comparacion con
 * `$blockWidthDays` (aqui, 2 años frente a un ancho de bloque de ~7 meses),
 * solo caben un puñado de posiciones de bloque distintas -- el bootstrap
 * sortea casi siempre bloques que se solapan casi por completo entre si, y
 * la varianza entre replicas colapsa de forma artificial (`se_bootstrap`
 * salio 0,015 frente a `se_naive`=1,049, un `pseudo_t` de -377: exactamente
 * el tipo de cifra absurda que motivo esta ronda de correccion). Con menos
 * de `MIN_BLOCKS_FOR_RELIABLE_BOOTSTRAP` posiciones de bloque distintas
 * posibles en el rango, no hay resolucion suficiente para que el
 * remuestreo signifique nada: `result_informative` sale `false` con
 * independencia del tamaño de muestra efectivo calculado.
 */
final class PolicyReplayStatistics
{
    private const BOOTSTRAP_REPLICATES = 5000;

    /**
     * Si el rango temporal total cabe en menos de este numero de bloques
     * de anchura `$blockWidthDays`, el bootstrap no tiene suficientes
     * posiciones de bloque distintas para producir una varianza entre
     * replicas que signifique algo -- ver el docblock de la clase.
     */
    private const MIN_BLOCKS_FOR_RELIABLE_BOOTSTRAP = 20;

    /**
     * Tamaño de muestra EFECTIVO minimo (tras corregir por dependencia
     * temporal, efecto de diseño de Kish) para tratar el intervalo de
     * confianza como informativo. Por debajo de esto, `result_informative`
     * sale `false` con independencia de si el intervalo excluye el cero.
     */
    private const MIN_EFFECTIVE_N = 30;

    /**
     * @param list<array{ticker: string, trades: list<array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: ?float, baseline_return: ?float, baseline_exit_date: ?string, baseline_pending: bool, revisar_tesis_events: int}>, entries_total: int, entries_closed: int, entries_pending: int, candidates_excluded_by_membership: int}> $replaysByTicker
     * @param ?int $seed Semilla de `mt_srand()` para que el bootstrap sea reproducible (Entrega 2: mismo criterio que `$asOf`). `null` usa el estado ambiental del generador -- aceptable para uso exploratorio, no para una medicion que se quiera poder repetir exactamente.
     * @return array{entries_total: int, entries_closed: int, entries_pending: int, pct_pending: ?float, pending_avg_managed_return: ?float, candidates_excluded_by_membership_total: int, cohorts: int, avg_diff: ?float, se_naive: ?float, t_stat_naive: ?float, se_bootstrap: ?float, ci95_low: ?float, ci95_high: ?float, pseudo_t_bootstrap: ?float, block_width_days: ?int, bootstrap_replicates: int, design_effect: ?float, effective_n: ?float, bootstrap_has_enough_resolution: bool, result_informative: bool, ci_excludes_zero: ?bool, calendar_windows_observed: int, revisar_tesis_events_total: int}
     */
    public function summarize(array $replaysByTicker, ?int $seed = null): array
    {
        $entriesTotal = 0;
        $entriesClosed = 0;
        $entriesPending = 0;
        $pendingManagedReturns = [];
        $revisarTesisEventsTotal = 0;
        $excludedByMembershipTotal = 0;
        /** @var list<array{entry_date: string, exposure_end_date: string, diff: float}> $pairedDiffs */
        $pairedDiffs = [];

        foreach ($replaysByTicker as $replay) {
            $excludedByMembershipTotal += $replay['candidates_excluded_by_membership'];

            foreach ($replay['trades'] as $trade) {
                $entriesTotal++;
                $revisarTesisEventsTotal += $trade['revisar_tesis_events'];

                if ($trade['pending']) {
                    $entriesPending++;

                    // Correccion del 2026-09-16 (caso 3 de
                    // `REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md`):
                    // `managed_return` puede ser `null` de verdad ahora
                    // (un episodio de `PolicyReplayEpisodeSimulator` sin
                    // NINGUN desenlace conocido todavia, ni siquiera el
                    // del stop) -- desconocido no es lo mismo que cero, y
                    // no debe entrar en la media de pendientes.
                    if ($trade['managed_return'] !== null) {
                        $pendingManagedReturns[] = $trade['managed_return'];
                    }

                    continue;
                }

                $entriesClosed++;

                if ($trade['baseline_return'] === null || $trade['baseline_exit_date'] === null) {
                    // Operacion ya cerrada por stop-loss, pero el
                    // comparador de 20 sesiones todavia no tiene desenlace
                    // (cerca del corte): no se puede emparejar, se excluye
                    // solo de la diferencia, no del conteo de cerradas.
                    continue;
                }

                // Ventana de exposicion de ESTA operacion: desde la
                // entrada hasta la fecha MAS TARDIA entre la salida
                // gestionada y la salida del comparador -- cubre AMBOS
                // brazos (caso 4 de Astra, `2026-09-14`), no solo cuanto
                // duro la gestionada.
                $exposureEndDate = $trade['exit_date'] > $trade['baseline_exit_date']
                    ? $trade['exit_date']
                    : $trade['baseline_exit_date'];

                $pairedDiffs[] = [
                    'entry_date' => $trade['entry_date'],
                    'exposure_end_date' => $exposureEndDate,
                    'diff' => $trade['managed_return'] - $trade['baseline_return'],
                ];
            }
        }

        usort($pairedDiffs, static fn (array $a, array $b): int => $a['entry_date'] <=> $b['entry_date']);

        $diffValues = array_column($pairedDiffs, 'diff');
        [$naiveMean, $naiveStderr, $naiveT] = $this->pairedStats($diffValues);

        $bootstrap = $this->bootstrapUncertainty($pairedDiffs, $seed);
        $designEffect = $bootstrap['se_bootstrap'] !== null && $naiveStderr !== null && $naiveStderr > 0.0
            ? ($bootstrap['se_bootstrap'] / $naiveStderr) ** 2
            : null;
        // Tope en N (Kish): un DEFF<1 puede aparecer por puro ruido de
        // muestreo del propio bootstrap (pocos bloques posibles, K de
        // sorteos mayor que el numero real de operaciones) sin que eso
        // signifique de verdad "mas informacion independiente de la que
        // se observo" -- el tamaño efectivo nunca puede superar el numero
        // de operaciones realmente medidas.
        $effectiveN = $designEffect !== null && $designEffect > 0.0
            ? min(count($diffValues), count($diffValues) / $designEffect)
            : null;
        $ciExcludesZero = $bootstrap['ci95_low'] !== null && $bootstrap['ci95_high'] !== null
            ? ($bootstrap['ci95_low'] > 0.0 || $bootstrap['ci95_high'] < 0.0)
            : null;
        // Verificado con un piloto real (60 tickers/2 años, `2026-09-15`):
        // sin esta salvaguarda, un rango temporal corto frente al ancho de
        // bloque colapsa `se_bootstrap` de forma artificial (0,015 frente
        // a un `se_naive` de 1,049, `pseudo_t`=-377) -- ver el docblock de
        // la clase.
        $bootstrapHasEnoughResolution = $bootstrap['blocks_in_range'] !== null
            && $bootstrap['blocks_in_range'] >= self::MIN_BLOCKS_FOR_RELIABLE_BOOTSTRAP;
        $resultInformative = $bootstrapHasEnoughResolution
            && $effectiveN !== null
            && $effectiveN >= self::MIN_EFFECTIVE_N;

        return [
            'entries_total' => $entriesTotal,
            'entries_closed' => $entriesClosed,
            'entries_pending' => $entriesPending,
            'pct_pending' => $entriesTotal > 0 ? round($entriesPending / $entriesTotal * 100, 2) : null,
            'pending_avg_managed_return' => $this->average($pendingManagedReturns),
            'candidates_excluded_by_membership_total' => $excludedByMembershipTotal,
            'cohorts' => count($diffValues),
            'avg_diff' => $naiveMean,
            'se_naive' => $naiveStderr,
            't_stat_naive' => $naiveT,
            'se_bootstrap' => $bootstrap['se_bootstrap'],
            'ci95_low' => $bootstrap['ci95_low'],
            'ci95_high' => $bootstrap['ci95_high'],
            'pseudo_t_bootstrap' => $bootstrap['se_bootstrap'] !== null && $bootstrap['se_bootstrap'] > 0.0 && $naiveMean !== null
                ? round($naiveMean / $bootstrap['se_bootstrap'], 2)
                : null,
            'block_width_days' => $bootstrap['block_width_days'],
            'bootstrap_replicates' => self::BOOTSTRAP_REPLICATES,
            'design_effect' => $designEffect !== null ? round($designEffect, 3) : null,
            'effective_n' => $effectiveN !== null ? round($effectiveN, 1) : null,
            'bootstrap_has_enough_resolution' => $bootstrapHasEnoughResolution,
            'result_informative' => $resultInformative,
            'ci_excludes_zero' => $ciExcludesZero,
            // Descriptivo unicamente (diseño de la ronda anterior,
            // `2026-09-14`): cuantas ventanas de calendario de ancho fijo
            // (mediana de holding GESTIONADO) contienen al menos una
            // operacion. Ya NO se usa para decidir nada -- Astra: "sustituir
            // la etiqueta concluyente por una descripcion de suficiencia de
            // grupos".
            'calendar_windows_observed' => $this->countCalendarWindows($pairedDiffs),
            'revisar_tesis_events_total' => $revisarTesisEventsTotal,
        ];
    }

    /**
     * Bootstrap de bloques moviles de calendario (Künsch 1989; Politis &
     * White 2004): ver el docblock de la clase para el porque. Devuelve
     * `null` en todos los campos con menos de dos operaciones emparejables
     * (no hay dependencia que estimar ni variabilidad que remuestrear).
     *
     * @param list<array{entry_date: string, exposure_end_date: string, diff: float}> $diffs YA ordenados por entry_date
     * @return array{se_bootstrap: ?float, ci95_low: ?float, ci95_high: ?float, block_width_days: ?int, blocks_in_range: ?float}
     */
    private function bootstrapUncertainty(array $diffs, ?int $seed): array
    {
        $n = count($diffs);

        if ($n < 2) {
            return ['se_bootstrap' => null, 'ci95_low' => null, 'ci95_high' => null, 'block_width_days' => null, 'blocks_in_range' => null];
        }

        $entryDates = array_map(static fn (array $item): DateTimeImmutable => new DateTimeImmutable($item['entry_date']), $diffs);
        $exposureEndDates = array_map(static fn (array $item): DateTimeImmutable => new DateTimeImmutable($item['exposure_end_date']), $diffs);

        $start = $entryDates[0];

        foreach ($entryDates as $date) {
            if ($date < $start) {
                $start = $date;
            }
        }

        $end = $exposureEndDates[0];

        foreach ($exposureEndDates as $date) {
            if ($date > $end) {
                $end = $date;
            }
        }

        $exposureSpans = [];

        foreach ($diffs as $index => $item) {
            $exposureSpans[] = $entryDates[$index]->diff($exposureEndDates[$index])->days;
        }

        sort($exposureSpans);
        $blockWidthDays = max(1, (int) round(2 * $this->percentile($exposureSpans, 0.9)));

        $totalRangeDays = max(1, $start->diff($end)->days);
        // +1: un bloque que arranca en $maxStartOffset debe poder cubrir
        // la fecha final del rango ($totalRangeDays) -- con
        // `max(0, $totalRangeDays - $blockWidthDays)` (sin el +1) el
        // ultimo bloque posible terminaba justo ANTES de esa fecha
        // (intervalo semiabierto), dejandola fuera de todo sorteo posible.
        $maxStartOffset = max(0, $totalRangeDays - $blockWidthDays + 1);
        $blockCount = max(1, (int) ceil($totalRangeDays / $blockWidthDays));

        // Indice ordenado por dia desde $start, para localizar por
        // busqueda binaria (en vez de recorrer las N operaciones en cada
        // uno de los `BOOTSTRAP_REPLICATES x $blockCount` sorteos) que
        // operaciones caen dentro de un bloque sorteado.
        $entryOffsets = [];

        foreach ($entryDates as $date) {
            $entryOffsets[] = $start->diff($date)->days;
        }

        if ($seed !== null) {
            mt_srand($seed);
        }

        $replicateMeans = [];

        for ($replicate = 0; $replicate < self::BOOTSTRAP_REPLICATES; $replicate++) {
            $sampledDiffs = [];

            for ($block = 0; $block < $blockCount; $block++) {
                $blockStart = mt_rand(0, $maxStartOffset);
                $blockEndExclusive = $blockStart + $blockWidthDays;

                foreach ($this->indexesInRange($entryOffsets, $blockStart, $blockEndExclusive) as $index) {
                    $sampledDiffs[] = $diffs[$index]['diff'];
                }
            }

            if ($sampledDiffs === []) {
                // Sorteo sin ninguna operacion cubierta (posible solo con
                // muestras muy pequeñas y $blockCount bajo): se repite ese
                // replica en vez de contar una media indefinida.
                $replicate--;

                continue;
            }

            $replicateMeans[] = array_sum($sampledDiffs) / count($sampledDiffs);
        }

        // Correccion del 2026-09-15/16 (hallazgo real de Astra,
        // `REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md`, caso 1,
        // prioridad inmediata): `pairedStats()` divide la desviacion
        // tipica por `sqrt(n)` porque esta pensada para el error estandar
        // de una MEDIA de observaciones i.i.d. -- pero `$replicateMeans`
        // YA SON las medias de las 5.000 replicas del bootstrap, no
        // observaciones individuales. Su desviacion tipica ES la
        // incertidumbre bootstrap por definicion (documentacion oficial de
        // `scipy.stats.bootstrap`, citada por Astra); dividirla otra vez
        // por `sqrt(5000)` calculaba la precision Monte Carlo de la MEDIA
        // de las replicas, no el error estandar buscado -- encogia
        // `se_bootstrap` por un factor de ~70,71 (`sqrt(5000)`) respecto al
        // valor correcto. Verificado por Astra reconstruyendo los mismos
        // sorteos con la misma semilla sobre los pilotos ya archivados:
        // la desviacion real (0,227825-1,084) es del mismo orden que
        // `se_naive`, nunca la cifra absurda que se publicaba (0,002-0,015).
        // Esto invalida la propia salvaguarda de resolucion temporal que
        // se añadio ese mismo dia (`bootstrap_has_enough_resolution`): se
        // diseño para un sintoma que en realidad era este bug de formula,
        // no falta de resolucion del remuestreo -- se conserva por ahora
        // (Astra: "corregir esta formula no demuestra que sobre ninguna"),
        // pendiente de revisar su justificacion por separado con el
        // resultado YA corregido.
        $seBootstrap = $this->standardDeviation($replicateMeans);
        sort($replicateMeans);

        return [
            'se_bootstrap' => $seBootstrap,
            'ci95_low' => round($this->percentile($replicateMeans, 0.025), 2),
            'ci95_high' => round($this->percentile($replicateMeans, 0.975), 2),
            'block_width_days' => $blockWidthDays,
            // Cuantas anchuras de bloque caben en el rango temporal total
            // (SIN redondear a entero ni forzar un minimo de 1, a
            // diferencia de $blockCount, que es para decidir cuantos
            // bloques SORTEAR por replica): la resolucion real que tiene
            // el bootstrap para variar de una replica a otra. Ver
            // `MIN_BLOCKS_FOR_RELIABLE_BOOTSTRAP`.
            'blocks_in_range' => $totalRangeDays / $blockWidthDays,
        ];
    }

    /**
     * Indices de `$sortedOffsets` cuyo valor cae en `[$from, $to)`, via
     * busqueda binaria (asume `$sortedOffsets` ya ordenado ascendente,
     * garantizado porque `$diffs` llega ordenado por `entry_date`).
     *
     * @param list<int> $sortedOffsets
     * @return list<int> indices (posiciones en $sortedOffsets, no valores)
     */
    private function indexesInRange(array $sortedOffsets, int $from, int $to): array
    {
        $count = count($sortedOffsets);
        $lower = $this->lowerBound($sortedOffsets, $from);
        $indexes = [];

        for ($i = $lower; $i < $count && $sortedOffsets[$i] < $to; $i++) {
            $indexes[] = $i;
        }

        return $indexes;
    }

    /**
     * Primer indice cuyo valor es `>= $value` (busqueda binaria estandar).
     *
     * @param list<int> $sortedValues
     */
    private function lowerBound(array $sortedValues, int $value): int
    {
        $low = 0;
        $high = count($sortedValues);

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if ($sortedValues[$mid] < $value) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    /**
     * Percentil por interpolacion lineal (metodo habitual, mismo criterio
     * que el percentil por defecto de numpy): `$p` en `[0,1]`.
     *
     * @param list<int|float> $sortedValues YA ordenados ascendentemente, no vacio
     */
    private function percentile(array $sortedValues, float $p): float
    {
        $n = count($sortedValues);

        if ($n === 1) {
            return (float) $sortedValues[0];
        }

        $rank = $p * ($n - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return (float) $sortedValues[$lower];
        }

        $fraction = $rank - $lower;

        return $sortedValues[$lower] + $fraction * ($sortedValues[$upper] - $sortedValues[$lower]);
    }

    /**
     * Descriptivo unicamente, ver el docblock de la clase: cuantas
     * ventanas de calendario de ancho fijo (mediana de holding del brazo
     * GESTIONADO, diseño de la ronda del `2026-09-14`) contienen al menos
     * una operacion.
     *
     * @param list<array{entry_date: string, exposure_end_date: string, diff: float}> $diffs
     */
    private function countCalendarWindows(array $diffs): int
    {
        if ($diffs === []) {
            return 0;
        }

        $holdingDays = array_map(
            static fn (array $item): int => (new DateTimeImmutable($item['entry_date']))
                ->diff(new DateTimeImmutable($item['exposure_end_date']))
                ->days,
            $diffs
        );
        sort($holdingDays);
        $windowDays = max(1, $this->median($holdingDays));

        $entryDates = array_map(static fn (array $item): DateTimeImmutable => new DateTimeImmutable($item['entry_date']), $diffs);
        $start = $entryDates[0];

        foreach ($entryDates as $date) {
            if ($date < $start) {
                $start = $date;
            }
        }

        $windows = [];

        foreach ($entryDates as $date) {
            $windows[intdiv($start->diff($date)->days, $windowDays)] = true;
        }

        return count($windows);
    }

    /**
     * @param list<int> $sortedValues YA ordenados ascendentemente
     */
    private function median(array $sortedValues): int
    {
        $n = count($sortedValues);
        $mid = intdiv($n, 2);

        if ($n % 2 === 1) {
            return $sortedValues[$mid];
        }

        return intdiv($sortedValues[$mid - 1] + $sortedValues[$mid], 2);
    }

    /**
     * Desviacion tipica MUESTRAL (n-1), SIN dividir por `sqrt(n)` --
     * distinta a proposito de `pairedStats()`. Uso especifico: la
     * incertidumbre bootstrap es la desviacion tipica de las medias de las
     * replicas, no el error estandar de esas medias tratadas como si
     * fueran una muestra i.i.d. nueva sobre la que aplicar el mismo
     * `pairedStats()` (ver el comentario en `bootstrapUncertainty()`, caso
     * 1 de `REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md`).
     *
     * @param list<float> $values
     */
    private function standardDeviation(array $values): ?float
    {
        $n = count($values);

        if ($n < 2) {
            return null;
        }

        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / ($n - 1);

        return round(sqrt($variance), 3);
    }

    /**
     * @param list<float> $values
     * @return array{0: ?float, 1: ?float, 2: ?float} media, error estandar, t
     */
    private function pairedStats(array $values): array
    {
        $n = count($values);

        if ($n === 0) {
            return [null, null, null];
        }

        $mean = array_sum($values) / $n;

        if ($n < 2) {
            return [round($mean, 2), null, null];
        }

        $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / ($n - 1);
        $stderr = sqrt($variance) / sqrt($n);
        $t = $stderr > 0.0 ? $mean / $stderr : null;

        return [round($mean, 2), round($stderr, 3), $t !== null ? round($t, 2) : null];
    }

    /**
     * @param list<float> $values
     */
    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }
}
