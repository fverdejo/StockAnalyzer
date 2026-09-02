<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateInterval;
use DateTimeImmutable;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Interfaces\IndexMembershipCheckerInterface;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Repository\TickerBacktestCacheRepository;
use Throwable;

/**
 * Limitacion honesta de etiquetado (roadmap.md, "Prioridad cero" punto 3A,
 * `2026-09-01`): los fundamentales usados aqui son
 * `filing-date point-in-time, restatement-safe=false`.
 *
 * `filingDate` (ver `FiscalPeriod`, `PointInTimeFundamentalsBuilder`)
 * garantiza que en una fecha D solo se usa lo YA PUBLICADO en D -- eso
 * elimina el sesgo de "usar el futuro" (mirar un dato que todavia no
 * existia). Pero NO garantiza que el valor servido para un trimestre
 * antiguo sea el que se publico ORIGINALMENTE: si una empresa reformula
 * (`restated`) una cifra despues (correccion contable, cambio de norma,
 * error detectado), EODHD podria devolver hoy la version reformulada para
 * ese mismo `filing_date` antiguo, sin distincion. Investigado y
 * confirmado el `2026-09-01`: ni la documentacion publica de EODHD ni el
 * payload archivado (`eodhd_raw_fundamentals`, revisado campo a campo)
 * mencionan version/vintage/"as reported" vs "restated" -- no hay forma
 * de saberlo con los datos de un unico proveedor y una unica descarga
 * (`eodhd_raw_fundamentals` es UPSERT por ticker, no conserva vintages
 * historicos de re-descargas). No se afirma por tanto que el backtest
 * este completamente libre de sesgo de anticipacion, solo que esta libre
 * del caso mas grosero (usar un trimestre antes de su `filing_date`).
 */
class BacktestingService
{
    public function __construct(
        private readonly MarketDataProviderInterface $marketDataProvider,
        private readonly TechnicalAnalyzer $technicalAnalyzer,
        private readonly ScoreCalculator $scoreCalculator,
        private readonly RiskLevelsCalculator $riskLevelsCalculator,
        private readonly DividendGrowthCalculator $dividendGrowthCalculator = new DividendGrowthCalculator(),
        private readonly BacktestingConfig $backtestingConfig = new BacktestingConfig(),
        /**
         * Snapshots diarios de fundamentales (`v2.74`). Opcional a
         * proposito: sin el, `stockAt()` se comporta como antes de `v2.91`
         * (los fundamentales de hoy en toda fecha pasada), que es lo que
         * necesitan los tests que no hablan con base de datos.
         */
        private readonly ?FundamentalsHistoryRepository $fundamentalsHistory = null,
        /**
         * Universo point-in-time (roadmap.md, "Segundo bloque" punto 5,
         * 2026-09-02): quien era miembro de que indice en que fecha.
         * Opcional a proposito, mismo criterio que `$fundamentalsHistory`:
         * sin el, `runCrossSectional()` se comporta como siempre (la lista
         * de tickers que se le pasa es el universo en TODAS las fechas, con
         * el sesgo de supervivencia que este bloque existe para poder
         * eliminar cuando se le conecta uno).
         *
         * **Limitacion real, no de este mecanismo sino de los datos
         * disponibles hoy** (investigado y confirmado en vivo el
         * 2026-09-02, ver versions.md): el filtro de membresia por si solo
         * NO basta para un backtest real de "antiguos componentes" del S&P
         * 500 -- ademas hace falta precio historico, y ni EODHD (el plan
         * "Fundamentals Data Feed" contratado da 401/403 en
         * `/api/eod`, `/api/div` y `/api/splits`, para CUALQUIER ticker, no
         * solo delisted) ni Yahoo (reutiliza simbolos de empresas
         * delistadas para compañias NO relacionadas -- confirmado con
         * `APC`, `LEH`, `EMC`, entre otros: pedir su historico HOY devuelve
         * precios reales pero de una empresa distinta) sirven ese precio de
         * forma fiable y automatizable. Este parametro deja el MOTOR listo
         * y probado (ver `BacktestingServicePointInTimeUniverseTest`); un
         * backtest real de "S&P 500 desde 2012 con antiguos miembros" sigue
         * bloqueado por ese hueco de precio, no por este filtro.
         */
        private readonly ?IndexMembershipCheckerInterface $indexMembership = null
    ) {
    }

    /**
     * Cuantas muestras del recorrido pudieron usar fundamentales de su
     * propia fecha y cuantas tuvieron que caer en los de hoy. Se reinician
     * en cada `backtestTicker()` para que el porcentaje publicado sea el de
     * ese ticker y no un acumulado de toda la ejecucion.
     */
    private int $pointInTimeHits = 0;
    private int $pointInTimeMisses = 0;

    /**
     * @param list<string> $tickers
     * @param int $step Tamaño del paso entre muestras consecutivas. Con el
     *        $step por defecto (5) y un horizonte tipico de 20 dias, cada
     *        muestra comparte hasta 15 de sus 20 dias de retorno futuro con
     *        la siguiente (autocorrelacion). Para obtener muestras
     *        estadisticamente independientes hay que ejecutar con
     *        $step = $horizonDays (ver tambien 'effective_independent_samples'
     *        en el resultado de cada ticker).
     * @param string $mode 'full' (score completo, el que ve el usuario real)
     *        o 'technical' (solo TECHNICAL+MOMENTUM+RISK, herramienta de
     *        investigacion via CLI que no afecta al pipeline real).
     * @return array<string,mixed>
     */
    public function run(array $tickers, int $horizonDays = 20, int $step = 5, string $mode = 'full'): array
    {
        $results = [];
        $errors = [];

        foreach ($tickers as $ticker) {
            try {
                $results[] = $this->backtestTicker($ticker, $horizonDays, $step, $mode);
            } catch (\Throwable $exception) {
                $errors[$ticker] = $exception->getMessage();
            }
        }

        return [
            'horizon_days' => $horizonDays,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'aggregate' => $this->aggregateUniverse($results),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Agrega en una sola cifra por universo lo que hasta ahora habia que
     * promediar a ojo entre decenas de filas de `results` (ver versions.md
     * v2.59), y añade la lectura por EPISODIOS de mercado.
     *
     * La vista por episodios existe porque las muestras BUY de tickers
     * distintos en la misma fecha NO son independientes: comparten el
     * movimiento del mercado de ese dia. `effective_independent_samples`
     * (v2.31) solo corrige el solape temporal DENTRO de un ticker, no este
     * agrupamiento entre tickers. Por eso `avg_of_monthly_avgs` (un mes = un
     * voto) acompaña siempre a `avg_buy_forward_return` (una muestra = un
     * voto): si ambas se separan mucho, la media global esta dominada por
     * unos pocos episodios de mercado y no por la calidad de la señal.
     *
     * @param list<array<string,mixed>> $results
     * @return array<string,mixed>
     */
    private function aggregateUniverse(array $results): array
    {
        $totalSamples = 0;
        $allDaysWeightedSum = 0.0;
        $allDaysSamples = 0;
        $buyReturns = [];
        $distinctBuyTickers = 0;
        $returnsByMonth = [];

        foreach ($results as $result) {
            $tickerSamples = (int) ($result['samples'] ?? 0);
            $totalSamples += $tickerSamples;

            if (($result['avg_all_days_forward_return'] ?? null) !== null) {
                $allDaysWeightedSum += (float) $result['avg_all_days_forward_return'] * $tickerSamples;
                $allDaysSamples += $tickerSamples;
            }

            /** @var list<array{date: string, forward_return: float}> $buySamples */
            $buySamples = $result['buy_samples'] ?? [];

            if ($buySamples !== []) {
                $distinctBuyTickers++;
            }

            foreach ($buySamples as $buySample) {
                $return = (float) $buySample['forward_return'];
                $buyReturns[] = $return;
                $returnsByMonth[substr((string) $buySample['date'], 0, 7)][] = $return;
            }
        }

        ksort($returnsByMonth);
        $monthlyAverages = [];

        foreach ($returnsByMonth as $month => $monthReturns) {
            $monthlyAverages[$month] = (float) $this->average($monthReturns);
        }

        $avgBuy = $this->average($buyReturns);
        $avgAll = $allDaysSamples > 0 ? round($allDaysWeightedSum / $allDaysSamples, 2) : null;

        return [
            'samples' => $totalSamples,
            'buy_signals' => count($buyReturns),
            'avg_buy_forward_return' => $avgBuy,
            'avg_all_days_forward_return' => $avgAll,
            'buy_alpha_vs_all_days' => ($avgBuy !== null && $avgAll !== null) ? round($avgBuy - $avgAll, 2) : null,
            'win_rate_buy' => $this->winRate($buyReturns),
            'distinct_buy_tickers' => $distinctBuyTickers,
            'distinct_buy_months' => count($monthlyAverages),
            'avg_of_monthly_avgs' => $this->average(array_values($monthlyAverages)),
            'worst_month' => $this->worstMonth($monthlyAverages),
        ];
    }

    /**
     * Mes con la peor media de retornos BUY del universo, el episodio de
     * mercado que mas pesa en contra de la señal. Mismo criterio de
     * resiliencia que el resto del agregado: sin ningun mes con muestras,
     * null. En caso de empate gana el mes mas antiguo (el array llega
     * ordenado por fecha desde `aggregateUniverse()`), para que el resultado
     * sea determinista.
     *
     * @param array<string,float> $monthlyAverages
     * @return array{month: string, avg_forward_return: float}|null
     */
    private function worstMonth(array $monthlyAverages): ?array
    {
        $worstMonth = null;
        $worstAverage = null;

        foreach ($monthlyAverages as $month => $average) {
            if ($worstAverage === null || $average < $worstAverage) {
                $worstMonth = (string) $month;
                $worstAverage = $average;
            }
        }

        if ($worstMonth === null || $worstAverage === null) {
            return null;
        }

        return ['month' => $worstMonth, 'avg_forward_return' => $worstAverage];
    }

    /**
     * Backtest TRANSVERSAL: mide lo que el producto promete de verdad, que es
     * un ranking ("las N mejores acciones para comprar hoy"), no un umbral
     * absoluto.
     *
     * `backtestTicker()` responde "cuando este ticker marca BUY, ¿sube?", y
     * compara cada ticker consigo mismo: si el mercado entero sube, la señal
     * parece buena aunque el ranking no aporte nada. Este metodo responde la
     * pregunta que si decide: en cada fecha, ¿el top-N por puntuacion lo hace
     * mejor que la media de las acciones disponibles ESE MISMO DIA? Al
     * comparar dentro de la misma fecha, el movimiento comun del mercado se
     * cancela por construccion (no hace falta un benchmark externo) y
     * desaparece el problema de que las muestras BUY de tickers distintos en
     * la misma fecha no sean independientes: cada fecha aporta UN dato, la
     * alpha de ese dia.
     *
     * Sobre la independencia entre fechas se apoyan tres reglas:
     *
     * - $step >= $horizonDays (obligatorio): dentro de un ticker, dos
     *   muestras consecutivas no comparten dias de retorno futuro.
     * - Una fecha necesita mas de $topN tickers para evaluarse: con
     *   exactamente $topN, el top-N ES el universo y la alpha valdria 0 por
     *   construccion. Este filtro tambien descarta las fechas sueltas que
     *   aportan los tickers con historico corto, cuya rejilla de muestreo no
     *   coincide con la del resto del universo.
     * - Entre dos fechas evaluadas deben pasar al menos $horizonDays dias
     *   naturales. Es un suelo conservador (con $step sesiones de por medio
     *   el hueco real es mayor), pensado para que un ticker desalineado no
     *   cuele una fecha solapada con la anterior.
     *
     * @param list<string> $tickers Universo candidato. Si $indexCode va
     *        acompañado de un `IndexMembershipCheckerInterface` conectado
     *        (ver constructor), esta lista puede ser AMPLIA (todos los
     *        miembros historicos conocidos de un indice, activos y ya no
     *        activos): el filtro point-in-time decide, fecha a fecha, cual
     *        de ellos "existia" de verdad ese dia. Sin `$indexCode` (o sin
     *        repositorio conectado) el comportamiento es el de siempre: la
     *        lista se trata como el universo COMPLETO en TODAS las fechas,
     *        con el sesgo de supervivencia conocido si viene de
     *        `config/universes.php` (lista de HOY aplicada al pasado).
     * @param int $topN Cuantas acciones se "compran" cada fecha, las de mayor
     *        puntuacion, igual que el Top del dashboard.
     * @param string $mode 'full' o 'technical', mismo significado que en `run()`.
     * @param ?string $indexCode Codigo de indice (roadmap.md, "Segundo
     *        bloque" punto 5, 2026-09-02) contra el que se comprueba
     *        membresia point-in-time va `IndexMembershipCheckerInterface`.
     *        Sin efecto si el servicio no tiene uno conectado (constructor).
     * @return array<string,mixed>
     */
    public function runCrossSectional(
        array $tickers,
        int $horizonDays = 20,
        int $step = 20,
        int $topN = 10,
        string $mode = 'full',
        ?string $indexCode = null
    ): array {
        $this->assertValidMode($mode);

        if ($topN < 1) {
            throw new \InvalidArgumentException("El top-N debe ser al menos 1, recibido $topN.");
        }

        if ($step < $horizonDays) {
            throw new \InvalidArgumentException(
                "Un backtest transversal necesita fechas independientes: el paso ($step) no puede ser menor que el horizonte ($horizonDays)."
            );
        }

        // El filtro point-in-time solo se activa si SE PIDIO (indexCode no
        // nulo) Y hay con que comprobarlo (repositorio conectado): mismo
        // criterio de "opcional, sin romper nada existente" que
        // $fundamentalsHistory en stockAt(). Sin uno de los dos, ningun
        // ticker se descarta por membresia -- comportamiento identico al de
        // antes de este parametro.
        $membershipActive = $indexCode !== null && $this->indexMembership instanceof IndexMembershipCheckerInterface;
        $samplesByDate = [];
        $errors = [];
        $droppedNotMember = 0;
        $samplesKept = 0;

        foreach ($tickers as $ticker) {
            try {
                foreach ($this->collectSamples($ticker, $horizonDays, $step, $mode) as $sample) {
                    if ($membershipActive) {
                        $sampleDate = new \DateTimeImmutable((string) $sample['date']);

                        if (!$this->indexMembership->isMemberAt($ticker, (string) $indexCode, $sampleDate)) {
                            $droppedNotMember++;

                            continue;
                        }
                    }

                    $samplesKept++;
                    $samplesByDate[$sample['date']][] = [
                        'ticker' => strtoupper($ticker),
                        'percentage' => $sample['percentage'],
                        'forward_return' => $sample['forward_return'],
                    ];
                }
            } catch (\Throwable $exception) {
                $errors[$ticker] = $exception->getMessage();
            }
        }

        ksort($samplesByDate);

        $dates = [];
        $alphas = [];
        $topAverages = [];
        $universeAverages = [];
        $topReturns = [];
        $universeReturns = [];
        $droppedLowBreadth = 0;
        $droppedOverlapping = 0;
        $lastEvaluated = null;

        foreach ($samplesByDate as $date => $daySamples) {
            if (count($daySamples) <= $topN) {
                $droppedLowBreadth++;
                continue;
            }

            $currentDate = new \DateTimeImmutable((string) $date);

            if ($lastEvaluated !== null && (int) $lastEvaluated->diff($currentDate)->days < $horizonDays) {
                $droppedOverlapping++;
                continue;
            }

            $lastEvaluated = $currentDate;
            $selected = array_slice($this->rankByPercentage($daySamples), 0, $topN);
            $selectedReturns = array_column($selected, 'forward_return');
            $dayReturns = array_column($daySamples, 'forward_return');
            $topAverage = array_sum($selectedReturns) / count($selectedReturns);
            $universeAverage = array_sum($dayReturns) / count($dayReturns);

            $alphas[] = $topAverage - $universeAverage;
            $topAverages[] = $topAverage;
            $universeAverages[] = $universeAverage;
            $topReturns = array_merge($topReturns, $selectedReturns);
            $universeReturns = array_merge($universeReturns, $dayReturns);

            $dates[] = [
                'date' => (string) $date,
                'universe_size' => count($daySamples),
                'top_tickers' => array_column($selected, 'ticker'),
                'top_avg_forward_return' => round($topAverage, 2),
                'universe_avg_forward_return' => round($universeAverage, 2),
                'alpha' => round($topAverage - $universeAverage, 2),
            ];
        }

        return array_merge(
            [
                'horizon_days' => $horizonDays,
                'step' => $step,
                'top_n' => $topN,
                'mode' => $mode,
                'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'dates_evaluated' => count($dates),
                'dates_dropped_low_breadth' => $droppedLowBreadth,
                'dates_dropped_overlapping' => $droppedOverlapping,
                // Cobertura del universo point-in-time (roadmap.md,
                // "Segundo bloque" punto 5): `point_in_time_universe` es
                // `false` cuando no se pidio (`$indexCode` nulo) o no hay
                // repositorio conectado -- ahi los dos contadores de abajo
                // son irrelevantes (0/0, no se llego a comprobar nada) y no
                // deben leerse como "cobertura del 100%".
                'point_in_time_universe' => $membershipActive,
                'index_code' => $membershipActive ? strtoupper((string) $indexCode) : null,
                'samples_kept' => $samplesKept,
                'samples_dropped_not_member' => $droppedNotMember,
            ],
            $this->crossSectionalStatistics($alphas, $topAverages, $universeAverages, $topReturns, $universeReturns),
            [
                'dates' => $dates,
                'errors' => $errors,
            ]
        );
    }

    /**
     * Estadistica del backtest transversal. La cifra principal es la alpha
     * media POR FECHA (una fecha = un voto, igual criterio que
     * `avg_of_monthly_avgs` en `aggregateUniverse()`) y su t-stat pareado:
     * al restar en la misma fecha, la volatilidad del mercado desaparece del
     * denominador y el t-stat mide de verdad si el ranking ordena.
     *
     * `pooled_alpha_t_stat` acompaña a la cifra principal como contraste: es
     * el mismo calculo que ya usa `backtestTicker()` (Welch sobre las dos
     * nubes de retornos sin emparejar por fecha) y, al ignorar que dos
     * muestras del mismo dia comparten mercado, tiende a dar un error
     * estandar mucho mayor. Si ambos t coinciden en signo pero no en
     * magnitud, la diferencia es exactamente el ruido de mercado que el
     * diseño pareado elimina.
     *
     * @param list<float> $alphas
     * @param list<float> $topAverages
     * @param list<float> $universeAverages
     * @param list<float> $topReturns
     * @param list<float> $universeReturns
     * @return array<string,mixed>
     */
    private function crossSectionalStatistics(
        array $alphas,
        array $topAverages,
        array $universeAverages,
        array $topReturns,
        array $universeReturns
    ): array {
        $meanAlpha = $alphas !== [] ? array_sum($alphas) / count($alphas) : null;
        $alphaStdDev = $this->stdDev($alphas);
        $alphaStdErr = $alphaStdDev !== null ? $alphaStdDev / sqrt(count($alphas)) : null;
        $pooledStdErr = $this->welchStdErr($topReturns, $universeReturns);
        $positiveAlphas = count(array_filter($alphas, static fn (float $alpha): bool => $alpha > 0.0));

        return [
            'avg_top_n_forward_return' => $this->average($topAverages),
            'avg_universe_forward_return' => $this->average($universeAverages),
            'avg_alpha' => $meanAlpha !== null ? round($meanAlpha, 2) : null,
            'alpha_stddev' => $alphaStdDev !== null ? round($alphaStdDev, 2) : null,
            'alpha_stderr' => $alphaStdErr !== null ? round($alphaStdErr, 2) : null,
            'alpha_ci95_low' => ($meanAlpha !== null && $alphaStdErr !== null)
                ? round($meanAlpha - (1.96 * $alphaStdErr), 2)
                : null,
            'alpha_ci95_high' => ($meanAlpha !== null && $alphaStdErr !== null)
                ? round($meanAlpha + (1.96 * $alphaStdErr), 2)
                : null,
            'alpha_t_stat' => ($meanAlpha !== null && $alphaStdErr !== null && $alphaStdErr > 0.0)
                ? round($meanAlpha / $alphaStdErr, 2)
                : null,
            'dates_with_positive_alpha' => $positiveAlphas,
            'pct_dates_positive_alpha' => $alphas !== []
                ? round(($positiveAlphas / count($alphas)) * 100, 2)
                : null,
            'win_rate_top_n' => $this->winRate($topReturns),
            'win_rate_universe' => $this->winRate($universeReturns),
            'pooled_alpha_stderr' => $pooledStdErr !== null ? round($pooledStdErr, 2) : null,
            'pooled_alpha_t_stat' => ($meanAlpha !== null && $pooledStdErr !== null && $pooledStdErr > 0.0)
                ? round($meanAlpha / $pooledStdErr, 2)
                : null,
        ];
    }

    /**
     * Ranking de una fecha por puntuacion descendente. El desempate por
     * ticker (alfabetico) no es cosmetico: sin el, dos acciones empatadas
     * entrarian o no en el top-N segun el orden en que se pidieron los
     * tickers, y el mismo backtest daria numeros distintos.
     *
     * @param list<array{ticker: string, percentage: float, forward_return: float}> $daySamples
     * @return list<array{ticker: string, percentage: float, forward_return: float}>
     */
    private function rankByPercentage(array $daySamples): array
    {
        usort(
            $daySamples,
            static fn (array $left, array $right): int
                => [$right['percentage'], $left['ticker']] <=> [$left['percentage'], $right['ticker']]
        );

        return $daySamples;
    }

    /**
     * Version de un solo ticker de `backtestTicker()`, pensada para uso
     * interactivo (ver versions.md v2.23: historial de la señal de compra
     * en la ficha de detalle). A diferencia de `run()`, nunca lanza: si el
     * calculo falla (proveedor, historico insuficiente...) devuelve null
     * para que la pagina que la invoque siga funcionando sin el panel.
     *
     * @return array<string,mixed>|null
     */
    public function runForTicker(string $ticker, int $horizonDays = 20, int $step = 5): ?array
    {
        try {
            return $this->backtestTicker($ticker, $horizonDays, $step);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Version cacheada de `runForTicker()` (ver versions.md v2.34,
     * `TickerBacktestCacheRepository`): solo cachea resultados en modo
     * 'full' (el que ve el usuario real), nunca los de `--mode=technical`
     * de `bin/backtest.php`, que es una herramienta de investigacion y no
     * debe contaminar la cache de produccion.
     *
     * @return array<string,mixed>|null
     */
    public function runForTickerCached(
        string $ticker,
        TickerBacktestCacheRepository $cache,
        int $horizonDays = 20,
        int $step = 5,
        ?DateInterval $ttl = null
    ): ?array {
        $ttl ??= new DateInterval('P1D');
        $cached = $cache->find($ticker, $horizonDays, $step, $ttl);

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->runForTicker($ticker, $horizonDays, $step);

        if ($result !== null) {
            $cache->save($ticker, $horizonDays, $step, $result);
        }

        return $result;
    }

    /**
     * Agrega el historial de señal de compra (mismo criterio que
     * backtestTicker()/runForTicker()) de un grupo de tickers, ponderando
     * por el numero de muestras gestionadas de cada uno. Pensado para dar
     * una cifra con mas soporte estadistico cuando el historico de un
     * ticker individual es corto (ver v2.34): un grupo de tickers del mismo
     * sector, NUNCA una mezcla arbitraria (la homogeneidad la decide el
     * llamador via UniverseConfig::narrowestSectorFor()).
     *
     * Recorre los tickers uno a uno via `runForTickerCached()` en vez de
     * `run()` (que recalcula TODOS los tickers de golpe sin cache): la
     * mayoria vendran de cache tras un primer "calentamiento" (ver
     * `bin/backtest.php --persist`). Como mucho `$maxLiveComputations`
     * tickers sin cachear se calculan de verdad en una misma llamada; el
     * resto de tickers sin cache se excluyen del agregado de esta respuesta
     * concreta para no bloquear la peticion esperando calcular un grupo
     * entero (hasta ~50 tickers).
     *
     * @param list<string> $tickers
     * @return array{buy_managed_samples: int, avg_buy_managed_return: ?float}|null
     */
    public function runForPeerGroup(
        array $tickers,
        TickerBacktestCacheRepository $cache,
        int $horizonDays = 20,
        int $step = 5,
        int $maxLiveComputations = 5
    ): ?array {
        $totalSamples = 0;
        $weightedReturnSum = 0.0;
        $liveComputations = 0;
        $ttl = new DateInterval('P1D');

        foreach ($tickers as $ticker) {
            $cached = $cache->find($ticker, $horizonDays, $step, $ttl);

            if ($cached === null) {
                if ($liveComputations >= $maxLiveComputations) {
                    continue;
                }

                $liveComputations++;
                $cached = $this->runForTickerCached($ticker, $cache, $horizonDays, $step, $ttl);
            }

            if ($cached === null) {
                continue;
            }

            $samples = (int) $cached['buy_managed_samples'];

            if ($samples > 0 && $cached['avg_buy_managed_return'] !== null) {
                $totalSamples += $samples;
                $weightedReturnSum += $cached['avg_buy_managed_return'] * $samples;
            }
        }

        if ($totalSamples === 0) {
            return null;
        }

        return [
            'buy_managed_samples' => $totalSamples,
            'avg_buy_managed_return' => round($weightedReturnSum / $totalSamples, 2),
        ];
    }

    /**
     * Recorrido walk-forward de un ticker: una muestra por cada fecha de
     * señal, con la puntuacion que habria visto el usuario ese dia y el
     * retorno a $horizonDays vistas.
     *
     * Esta separado de `backtestTicker()` porque hay dos lecturas distintas
     * de las MISMAS muestras: la absoluta (umbrales BUY/SELL de un ticker
     * contra si mismo, `backtestTicker()`) y la transversal (ranking de
     * tickers en una misma fecha, `runCrossSectional()`). Duplicar el
     * recorrido para la segunda habria significado dos definiciones de
     * "muestra" que podrian divergir con el tiempo.
     *
     * @return list<array{date: string, recommendation: string, percentage: float, forward_return: float, managed_return: ?float, exit_reason: ?string, exit_day: ?int}>
     */
    private function collectSamples(string $ticker, int $horizonDays, int $step, string $mode): array
    {
        $this->assertValidMode($mode);

        return $this->sampleHistory(
            $this->enrichWithDividendGrowth($this->marketDataProvider->getStock($ticker), $ticker),
            $this->marketDataProvider->getHistoricalQuotes($ticker),
            $horizonDays,
            $step,
            $mode
        );
    }

    /**
     * @param list<HistoricalQuote> $history
     * @return list<array{date: string, recommendation: string, percentage: float, forward_return: float, managed_return: ?float, exit_reason: ?string, exit_day: ?int}>
     */
    private function sampleHistory(Stock $stock, array $history, int $horizonDays, int $step, string $mode): array
    {
        $samples = [];
        $minimumLookback = 80;
        $count = count($history);

        for ($index = $minimumLookback; $index < $count - $horizonDays; $index += $step) {
            $past = array_slice($history, 0, $index + 1);
            $current = $history[$index];
            $future = $history[$index + $horizonDays];
            $synthetic = $this->stockAt($stock, $current);
            $technical = $this->technicalAnalyzer->analyze($past);
            $score = $this->scoreCalculator->calculate($synthetic, $technical)->getScore();
            $forwardReturn = (($future->getClose() / $current->getClose()) - 1) * 100;

            if ($mode === 'technical') {
                $weights = $this->scoreCalculator->getWeights();
                $scores = $score->getScores();
                $technicalMax = $weights->getMax(ScoreCategory::TECHNICAL)
                    + $weights->getMax(ScoreCategory::MOMENTUM)
                    + $weights->getMax(ScoreCategory::RISK);
                $technicalTotal = ($scores[ScoreCategory::TECHNICAL->value] ?? 0)
                    + ($scores[ScoreCategory::MOMENTUM->value] ?? 0)
                    + ($scores[ScoreCategory::RISK->value] ?? 0);
                $percentage = $technicalMax > 0 ? round(($technicalTotal / $technicalMax) * 100, 2) : 0.0;
                $recommendation = Score::recommendationFor($percentage);
            } else {
                $percentage = $score->getPercentage();
                $recommendation = $score->getRecommendation();
            }

            $managedReturn = null;
            $exitReason = null;
            $exitDay = null;

            if ($recommendation === 'BUY') {
                $riskLevels = $this->riskLevelsCalculator->compute($technical, $current->getClose());

                if ($riskLevels !== null) {
                    [$exitReason, $exitPrice, $exitDay] = $this->simulateManagedExit(
                        $history,
                        $index,
                        $horizonDays,
                        $riskLevels,
                        $future
                    );
                    $managedReturn = $this->netManagedReturn($current->getClose(), $exitPrice);
                }
            }

            $samples[] = [
                'date' => $current->getDate()->format('Y-m-d'),
                'recommendation' => $recommendation,
                'percentage' => $percentage,
                'forward_return' => round($forwardReturn, 2),
                'managed_return' => $managedReturn,
                'exit_reason' => $exitReason,
                'exit_day' => $exitDay,
            ];
        }

        return $samples;
    }

    private function assertValidMode(string $mode): void
    {
        if (!in_array($mode, ['full', 'technical'], true)) {
            throw new \InvalidArgumentException("Modo de backtest desconocido: '$mode'. Valores validos: 'full', 'technical'.");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function backtestTicker(string $ticker, int $horizonDays, int $step, string $mode = 'full'): array
    {
        $this->assertValidMode($mode);
        // Por ticker, no acumulado: el porcentaje que se publica abajo tiene
        // que describir ESTE recorrido.
        $this->pointInTimeHits = 0;
        $this->pointInTimeMisses = 0;

        $stock = $this->enrichWithDividendGrowth($this->marketDataProvider->getStock($ticker), $ticker);
        $history = $this->marketDataProvider->getHistoricalQuotes($ticker);
        $samples = $this->sampleHistory($stock, $history, $horizonDays, $step, $mode);
        $count = count($history);

        $buyReturns = $this->returnsFor($samples, ['BUY']);
        $sellReturns = $this->returnsFor($samples, ['SELL', 'STRONG SELL']);
        $managedSamples = $this->managedSamplesFor($samples, ['BUY']);
        $benchmark = $count > $horizonDays
            ? (($history[$count - 1]->getClose() / $history[0]->getClose()) - 1) * 100
            : 0.0;
        $allReturns = array_column($samples, 'forward_return');
        $avgAll = $this->average($allReturns);
        $avgBuy = $this->average($buyReturns);
        $alpha = ($avgBuy !== null && $avgAll !== null) ? round($avgBuy - $avgAll, 2) : null;
        $buyStdDev = $this->stdDev($buyReturns);
        $buyStdErr = $buyStdDev !== null ? $buyStdDev / sqrt(count($buyReturns)) : null;
        $alphaStdErr = $this->welchStdErr($buyReturns, $allReturns);

        return [
            'ticker' => strtoupper($ticker),
            'samples' => count($samples),
            'effective_independent_samples' => (int) floor(
                count($samples) / max(1, (int) ceil($horizonDays / $step))
            ),
            'buy_signals' => count($buyReturns),
            'sell_signals' => count($sellReturns),
            'avg_buy_forward_return' => $this->average($buyReturns),
            'avg_sell_forward_return' => $this->average($sellReturns),
            'win_rate_buy' => $this->winRate($buyReturns),
            'win_rate_sell' => $this->winRate($sellReturns),
            'avg_all_days_forward_return' => $avgAll,
            'win_rate_all_days' => $this->winRate($allReturns),
            'buy_alpha_vs_all_days' => $alpha,
            'buy_return_stddev' => $buyStdDev !== null ? round($buyStdDev, 2) : null,
            'buy_return_stderr' => $buyStdErr !== null ? round($buyStdErr, 2) : null,
            'buy_return_ci95_low' => ($avgBuy !== null && $buyStdErr !== null)
                ? round($avgBuy - (1.96 * $buyStdErr), 2)
                : null,
            'buy_return_ci95_high' => ($avgBuy !== null && $buyStdErr !== null)
                ? round($avgBuy + (1.96 * $buyStdErr), 2)
                : null,
            'buy_alpha_stderr' => $alphaStdErr !== null ? round($alphaStdErr, 2) : null,
            'buy_alpha_t_stat' => ($alpha !== null && $alphaStdErr !== null && $alphaStdErr > 0.0)
                ? round($alpha / $alphaStdErr, 2)
                : null,
            'benchmark_return' => round($benchmark, 2),
            // Que porcentaje de las muestras uso fundamentales de su propia
            // fecha en vez de los de hoy (`v2.91`). Sin esta cifra, un
            // backtest con el 2% de cobertura y otro con el 100% se leen
            // exactamente igual, y el primero sigue arrastrando el sesgo de
            // anticipacion sobre el 56% del peso del score. `null` cuando no
            // hay repositorio conectado, que es distinto de 0,0: 0,0
            // significa "se busco y no habia nada".
            'fundamentals_point_in_time_pct' => $this->pointInTimePercent(),
            'recent_samples' => array_slice($samples, -10),
            'buy_samples' => $this->datedReturnsFor($samples, ['BUY']),
            'buy_managed_samples' => count($managedSamples),
            'avg_buy_managed_return' => $this->average(array_map(
                static fn (array $sample): float => (float) $sample['managed_return'],
                $managedSamples
            )),
            'max_drawdown_managed' => $this->worstManagedReturn($managedSamples),
            'stop_loss_rate' => $this->rateOf($managedSamples, 'stop_loss'),
            'target_rate' => $this->rateOf($managedSamples, 'target'),
            'horizon_rate' => $this->rateOf($managedSamples, 'horizon'),
        ];
    }

    /**
     * Recorre el historico dia a dia desde la señal hasta el horizonte para
     * saber si el stop-loss/objetivo basado en ATR14 (RiskLevelsCalculator)
     * se dispara antes que el horizonte fijo. Criterio conservador: si un
     * mismo dia cruza stop y objetivo a la vez, se asume que el stop-loss
     * se ejecuta primero, porque no hay datos intradia para saber cual de
     * los dos sucedio antes.
     *
     * @param list<HistoricalQuote> $history
     * @return array{0: string, 1: float, 2: int}
     */
    private function simulateManagedExit(
        array $history,
        int $index,
        int $horizonDays,
        RiskLevels $riskLevels,
        HistoricalQuote $future
    ): array {
        for ($offset = 1; $offset <= $horizonDays; $offset++) {
            $day = $history[$index + $offset];
            $hitStop = $day->getLow() <= $riskLevels->getStopLoss();
            $hitTarget = $day->getHigh() >= $riskLevels->getTarget();

            if ($hitStop) {
                // Hueco de apertura (v2.73): si la sesion ABRE ya por debajo
                // del stop, la orden no se ejecuta al stop — se ejecuta a la
                // apertura, que es peor. Cobrar el stop en ese caso es la
                // forma mas silenciosa de inflar el resultado, y afecta
                // justo a los peores dias, que son los que definen el
                // drawdown.
                $exitPrice = min($day->getOpen(), $riskLevels->getStopLoss());

                return ['stop_loss', $exitPrice, $offset];
            }

            if ($hitTarget) {
                // Simetrico y por el mismo motivo: si abre por encima del
                // objetivo, la venta se ejecuta a la apertura, que aqui
                // juega a favor. Modelar solo el hueco malo seria sesgar el
                // resultado en la direccion contraria.
                $exitPrice = max($day->getOpen(), $riskLevels->getTarget());

                return ['target', $exitPrice, $offset];
            }
        }

        return ['horizon', $future->getClose(), $horizonDays];
    }

    /**
     * Retorno de una operacion completa descontando el coste de operar
     * (v2.73): se paga al comprar y al vender, asi que el viaje completo
     * cuesta dos veces `getCostRate()`. Con el 0 implicito de antes, una
     * estrategia que entra y sale mucho parecia rentable aunque su ventaja
     * fuese menor que la comision.
     *
     * Solo se aplica al retorno GESTIONADO, que es el que afirma "esto es
     * lo que habria pasado operando asi". `forward_return` se queda bruto a
     * proposito: mide el movimiento del mercado, no una operacion, y es la
     * referencia contra la que se calcula la alpha (descontar el coste en
     * los dos lados de una resta no cambia la resta, pero si haria pensar
     * que el numero incluye algo que no incluye).
     */
    private function netManagedReturn(float $entryPrice, float $exitPrice): float
    {
        $cost = $this->backtestingConfig->getCostRate();
        $netEntry = $entryPrice * (1 + $cost);
        $netExit = $exitPrice * (1 - $cost);

        return round((($netExit / $netEntry) - 1) * 100, 2);
    }

    /**
     * Igual que el resto de Fundamentals (PER, ROE...), dividendGrowth5y se
     * calcula una unica vez con el historial de dividendos MAS RECIENTE
     * disponible y se trata como constante durante todo el recorrido
     * historico de backtestTicker()/stockAt(): no hay forma de reconstruir
     * el historial de dividendos "tal y como se veia" en cada fecha pasada
     * sin guardar snapshots historicos que hoy no existen. Misma
     * simplificacion conocida que ya asume el resto del backtest para
     * cualquier campo fundamental.
     */
    private function enrichWithDividendGrowth(Stock $stock, string $ticker): Stock
    {
        $dividendHistory = $this->marketDataProvider->getDividendHistory($ticker);
        $dividendGrowth5y = $this->dividendGrowthCalculator->calculate($dividendHistory);

        return new Stock(
            $stock->getCompany(),
            $stock->getQuote(),
            $stock->getFundamentals()->withDividendGrowth5y($dividendGrowth5y)
        );
    }

    /**
     * El `Stock` tal y como se veia en una fecha pasada: cotizacion de ese
     * dia y, desde `v2.91`, los fundamentales que se le conocian **en esa
     * fecha** si hay snapshot en `fundamentals_history` (`v2.74`).
     *
     * Hasta aqui el backtest reutilizaba los fundamentales de HOY para
     * cada fecha pasada, asi que FUNDAMENTAL+VALUATION+QUALITY+DIVIDEND —el
     * 56% del peso del score— entraban como una constante por ticker y con
     * sesgo de anticipacion. Eso significa que los veredictos "neutro en
     * backtest" de `v2.51`, `v2.64` y `v2.88` en realidad solo midieron el
     * bloque tecnico.
     *
     * **Si no hay snapshot para esa fecha se sigue usando el de hoy**, no
     * se salta la muestra. Es deliberado: la serie empezo a acumularse el
     * 2026-08-14 y saltar todo lo anterior dejaria el backtest sin muestras
     * durante meses, cambiando un sesgo conocido por un backtest vacio. Lo
     * que no puede pasar es que la mezcla sea invisible, y por eso cada
     * muestra cuenta en `pointInTimeHits`/`pointInTimeMisses` y el
     * resultado publica el porcentaje real (ver `fundamentalsPointInTimePct`).
     */
    private function stockAt(Stock $stock, HistoricalQuote $historical): Stock
    {
        $company = $stock->getCompany();

        return new Stock(
            new Company(
                $company->getTicker(),
                $company->getName(),
                $company->getSector(),
                $company->getIndustry(),
                $company->getMarket(),
                $company->getCurrency()
            ),
            new Quote(
                $historical->getClose(),
                $historical->getOpen(),
                $historical->getHigh(),
                $historical->getLow(),
                $historical->getClose(),
                $historical->getVolume(),
                $historical->getDate()
            ),
            $this->fundamentalsAt($company->getTicker(), $historical->getDate(), $stock->getFundamentals())
        );
    }

    /**
     * Porcentaje de muestras que uso fundamentales de su propia fecha.
     * `null` sin repositorio conectado: ahi la pregunta no se llego a
     * hacer, que no es lo mismo que hacerla y no encontrar nada.
     */
    private function pointInTimePercent(): ?float
    {
        if (!$this->fundamentalsHistory instanceof FundamentalsHistoryRepository) {
            return null;
        }

        $total = $this->pointInTimeHits + $this->pointInTimeMisses;

        return $total === 0 ? 0.0 : round($this->pointInTimeHits / $total * 100, 2);
    }

    /**
     * Fundamentales de un ticker en una fecha concreta, con los de hoy como
     * respaldo. Lleva la cuenta de aciertos y fallos para que el resultado
     * del backtest pueda decir de cuanto se fia de verdad.
     *
     * `dividendGrowth5y` se conserva del objeto actual cuando el snapshot no
     * lo trae: se calcula aparte en `withDividendGrowth()` a partir del
     * historial de dividendos, que tampoco es reconstruible hacia atras
     * (limitacion ya documentada en `v2.64`).
     */
    private function fundamentalsAt(string $ticker, DateTimeImmutable $date, Fundamentals $today): Fundamentals
    {
        if (!$this->fundamentalsHistory instanceof FundamentalsHistoryRepository) {
            return $today;
        }

        try {
            $snapshot = $this->fundamentalsHistory->findAsOf($ticker, $date);
        } catch (Throwable) {
            // Un fallo de base de datos no puede tumbar un backtest de diez
            // años: se degrada al comportamiento anterior, contado como
            // fallo para que se note en la cobertura.
            $snapshot = null;
        }

        if ($snapshot === null) {
            ++$this->pointInTimeMisses;

            return $today;
        }

        ++$this->pointInTimeHits;
        $historical = FundamentalsHistoryRepository::fromArray($snapshot);

        return $historical->getDividendGrowth5y() === null
            ? $historical->withDividendGrowth5y($today->getDividendGrowth5y())
            : $historical;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param list<string> $recommendations
     * @return list<float>
     */
    private function returnsFor(array $samples, array $recommendations): array
    {
        $returns = [];

        foreach ($samples as $sample) {
            if (in_array((string) $sample['recommendation'], $recommendations, true)) {
                $returns[] = (float) $sample['forward_return'];
            }
        }

        return $returns;
    }

    /**
     * Muestras BUY con su fecha, no solo su retorno: base del agregado por
     * universo/episodios de mercado de `run()` (ver versions.md v2.59), que
     * necesita saber en que mes cayo cada muestra y de que ticker viene, no
     * solo su valor. Version "con fecha" de `returnsFor()`, que se mantiene
     * para todo lo que solo necesita la lista de retornos.
     *
     * @param list<array<string,mixed>> $samples
     * @param list<string> $recommendations
     * @return list<array{date: string, forward_return: float}>
     */
    private function datedReturnsFor(array $samples, array $recommendations): array
    {
        $dated = [];

        foreach ($samples as $sample) {
            if (in_array((string) $sample['recommendation'], $recommendations, true)) {
                $dated[] = [
                    'date' => (string) $sample['date'],
                    'forward_return' => (float) $sample['forward_return'],
                ];
            }
        }

        return $dated;
    }

    /**
     * @param list<float> $values
     */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * Desviacion tipica MUESTRAL (denominador n-1, no n): las muestras de un
     * backtest son una muestra del comportamiento de la señal, no la
     * poblacion completa de todos los dias posibles. Devuelve el valor sin
     * redondear (quien lo publica en el resultado lo redondea, para que los
     * estadisticos derivados no acumulen error de redondeo). Mismo criterio
     * de resiliencia que `average()`/`winRate()`: sin dispersion calculable
     * (menos de 2 valores), null en vez de dividir por cero.
     *
     * @param list<float> $values
     */
    private function stdDev(array $values): ?float
    {
        $count = count($values);

        if ($count < 2) {
            return null;
        }

        $mean = array_sum($values) / $count;
        $sumOfSquares = 0.0;

        foreach ($values as $value) {
            $sumOfSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumOfSquares / ($count - 1));
    }

    /**
     * Error estandar de la diferencia entre dos medias por la formula de
     * Welch (`sqrt(sB²/nB + sA²/nA)`), sin asumir que ambos grupos tengan la
     * misma varianza: el grupo BUY es un subconjunto pequeño y mas selectivo
     * que "todos los dias", asi que la version de varianza combinada seria
     * una hipotesis que estos datos no respaldan. Sin redondear, igual que
     * `stdDev()`. null si alguno de los dos grupos no tiene dispersion
     * calculable (menos de 2 muestras).
     *
     * @param list<float> $groupB
     * @param list<float> $groupA
     */
    private function welchStdErr(array $groupB, array $groupA): ?float
    {
        $stdDevB = $this->stdDev($groupB);
        $stdDevA = $this->stdDev($groupA);

        if ($stdDevB === null || $stdDevA === null) {
            return null;
        }

        return sqrt((($stdDevB ** 2) / count($groupB)) + (($stdDevA ** 2) / count($groupA)));
    }

    /**
     * Muestras con recomendacion de compra que ademas tuvieron niveles de
     * riesgo calculables (managed_return no nulo): base tanto para
     * avg_buy_managed_return como para las tasas de salida.
     *
     * @param list<array<string,mixed>> $samples
     * @param list<string> $recommendations
     * @return list<array<string,mixed>>
     */
    private function managedSamplesFor(array $samples, array $recommendations): array
    {
        $managed = [];

        foreach ($samples as $sample) {
            if (
                in_array((string) $sample['recommendation'], $recommendations, true)
                && $sample['managed_return'] !== null
            ) {
                $managed[] = $sample;
            }
        }

        return $managed;
    }

    /**
     * Porcentaje de muestras con forward_return positivo (0% exacto no
     * cuenta como acierto: sin movimiento no hay ganancia que respalde la
     * señal). Mismo criterio de resiliencia que average(): sin muestras,
     * null en vez de dividir por cero.
     *
     * @param list<float> $returns
     */
    private function winRate(array $returns): ?float
    {
        if ($returns === []) {
            return null;
        }

        $wins = 0;

        foreach ($returns as $return) {
            if ($return > 0) {
                $wins++;
            }
        }

        return round(($wins / count($returns)) * 100, 2);
    }

    /**
     * Peor managed_return individual entre las muestras BUY gestionadas: el
     * drawdown mas severo que habria sufrido la estrategia gestionada, no
     * la media (avg_buy_managed_return ya la reporta). Mismo criterio de
     * resiliencia que el resto del agregado: sin muestras, null.
     *
     * @param list<array<string,mixed>> $managedSamples
     */
    private function worstManagedReturn(array $managedSamples): ?float
    {
        if ($managedSamples === []) {
            return null;
        }

        $returns = array_map(
            static fn (array $sample): float => (float) $sample['managed_return'],
            $managedSamples
        );

        return min($returns);
    }

    /**
     * @param list<array<string,mixed>> $managedSamples
     */
    private function rateOf(array $managedSamples, string $reason): ?float
    {
        if ($managedSamples === []) {
            return null;
        }

        $matching = array_filter(
            $managedSamples,
            static fn (array $sample): bool => $sample['exit_reason'] === $reason
        );

        return round((count($matching) / count($managedSamples)) * 100, 2);
    }
}
