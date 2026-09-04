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
        private readonly ?IndexMembershipCheckerInterface $indexMembership = null,
        /**
         * P3.3 (`REVISION_MOTOR_CODEX_2026-09-02.md`): puntua un factor
         * fundamental por su posicion relativa dentro del sector, no contra
         * un umbral fijo. Pura (sin red ni base de datos, ver su propio
         * docblock), mismo patron de "instanciar por defecto" que
         * `$dividendGrowthCalculator`/`$backtestingConfig` -- ningun test
         * que no use `mode='fundamental'` necesita inyectar nada distinto.
         */
        private readonly RelativeFundamentalScorer $fundamentalScorer = new RelativeFundamentalScorer()
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
     * P0.3 (`versions.md`, 2026-09-02): cuantas muestras de `sampleHistory()`
     * se descartaron por no tener suficiente historico para Momentum 12-1
     * (`TechnicalAnalyzer::momentumSkippingRecent()`, necesita mas de 250
     * cierres). Se reinicia al EMPEZAR cada llamada a `sampleHistory()` (una
     * llamada = un ticker, completa antes de devolver el control), no en
     * `backtestTicker()`: `runCrossSectional()` tambien necesita leerlo, una
     * vez por ticker, para publicar el total del universo.
     */
    private int $momentumNullDropped = 0;

    /**
     * Si el `marketCap` que acaba de devolver `fundamentalsAt()` vino de un
     * snapshot historico real point-in-time o del fallback a los
     * fundamentales de HOY (P3.4, `REVISION_MOTOR_CODEX_2026-09-02.md`,
     * seccion "1. Marca de procedencia del market cap"). Se sobrescribe en
     * CADA llamada -- `stockAt()`/`fundamentalsAt()` se invocan una unica vez
     * por muestra dentro de `sampleHistory()`, sin llamadas anidadas ni
     * paralelas, asi que leerlo justo despues de `stockAt()` (antes de que la
     * siguiente iteracion lo sobrescriba) siempre describe la muestra que se
     * esta construyendo.
     */
    private bool $lastMarketCapWasPointInTime = false;

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
     *        investigacion via CLI que no afecta al pipeline real). Tambien
     *        acepta 'momentum' (P3.4) y 'fundamental' (P3.3) por compartir
     *        validacion con `runCrossSectional()`, pero aqui ninguno de los
     *        dos tiene efecto propio: los dos rankings neutralizados
     *        (sector/tamaño para momentum, sector para fundamental) solo
     *        existen dentro de `runCrossSectional()`, asi que en un
     *        backtest de un unico ticker se comportan identico a 'full'.
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
     * - Entre dos fechas evaluadas deben pasar al menos $horizonDays SESIONES
     *   bursatiles reales (P0.2, `versions.md` 2026-09-02, no dias naturales:
     *   antes de esta version se comparaban dias naturales contra
     *   $horizonDays, que en el resto de la clase se trata como sesiones. Con
     *   el patron estandar del proyecto (horizonte 20) 20 sesiones ocupan
     *   ~28 dias naturales por los fines de semana, asi que dos fechas
     *   separadas 20-27 dias naturales se contaban como independientes sin
     *   que sus ventanas de forward_return dejaran de solaparse. El
     *   calendario bursatil real se construye con la UNION de las fechas de
     *   `$history` de TODOS los tickers recorridos (ya se pide una vez por
     *   ticker para las muestras, ver `collectSamplesWithHistory()`): dos
     *   fechas cuentan como independientes solo si hay al menos
     *   $horizonDays sesiones reales -- vistas por al menos un ticker del
     *   universo -- entre ellas, no un numero de dias naturales que varia
     *   por festivos/fines de semana.
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
     *        puntuacion (o de mayor Momentum 12-1 neutralizado con
     *        $mode='momentum', ver `rankByMomentumNeutral()`), igual que el
     *        Top del dashboard.
     * @param string $mode 'full' o 'technical', mismo significado que en
     *        `run()`. 'momentum' (P3.4, `REVISION_MOTOR_CODEX_2026-09-02.md`)
     *        rankea cada fecha por Momentum 12-1 neutralizado por sector y
     *        por tamaño en vez de por `percentage` (el score de 50 puntos):
     *        ver `rankByMomentumNeutral()` para el criterio exacto.
     *        'fundamental' (P3.3, misma referencia) rankea cada fecha por un
     *        score fundamental de tres familias (Valor/Calidad/Solidez)
     *        percentil-por-sector: ver `rankByFundamentalNeutral()`. En
     *        estos dos modos (roadmap.md, "Prioridad cero-ter" punto 5,
     *        `2026-09-04`) la alpha PRINCIPAL de cada fecha se mide contra
     *        el subconjunto ELEGIBLE de esa fecha (`$ranking['eligible']`),
     *        no contra `$daySamples` completo: mezclar el top elegido entre
     *        elegibles con un benchmark que incluye tickers que ya se sabia,
     *        antes de rankear, que no podian competir (sin marketCap PIT,
     *        sector demasiado pequeño, sin fundamentales/factores PIT...)
     *        desplazaria la alpha sin motivo. La comparacion contra
     *        `$daySamples` completo se conserva como diagnostico secundario
     *        en cada entrada de `dates[]`
     *        (`universe_avg_forward_return_all_available`/
     *        `alpha_vs_all_available`). 'full'/'technical' no tienen filtro
     *        de elegibilidad propio y no cambian de comportamiento.
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
        $momentumNullDropped = 0;
        // P0.2: calendario bursatil real, union de las fechas de $history de
        // TODOS los tickers recorridos (ver el docblock de este metodo).
        $tradingCalendar = [];

        foreach ($tickers as $ticker) {
            try {
                $collected = $this->collectSamplesWithHistory($ticker, $horizonDays, $step, $mode);
                $momentumNullDropped += $this->momentumNullDropped;

                foreach ($collected['history'] as $quote) {
                    $tradingCalendar[$quote->getDate()->format('Y-m-d')] = true;
                }

                foreach ($collected['samples'] as $sample) {
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
                        // P3.4: expuestos siempre (no solo con
                        // $mode='momentum') para no bifurcar esta
                        // recopilacion por modo -- full/technical
                        // simplemente no los leen.
                        'momentum12m1' => $sample['momentum12m1'],
                        'sector' => $sample['sector'],
                        'market_cap' => $sample['market_cap'],
                        'market_cap_is_point_in_time' => $sample['market_cap_is_point_in_time'],
                        // P3.3: expuestos siempre, mismo criterio que los
                        // campos de momentum de arriba -- full/technical/
                        // momentum simplemente no los leen.
                        'free_cash_flow_yield' => $sample['free_cash_flow_yield'],
                        'ev_to_ebitda' => $sample['ev_to_ebitda'],
                        'roic' => $sample['roic'],
                        'operating_margin' => $sample['operating_margin'],
                        'debt_to_equity' => $sample['debt_to_equity'],
                        'earnings_yield' => $sample['earnings_yield'],
                        'cash_conversion' => $sample['cash_conversion'],
                        'fundamentals_is_point_in_time' => $sample['fundamentals_is_point_in_time'],
                    ];
                }
            } catch (\Throwable $exception) {
                $errors[$ticker] = $exception->getMessage();
            }
        }

        ksort($samplesByDate);
        ksort($tradingCalendar);
        $sessionIndex = array_flip(array_keys($tradingCalendar));

        $dates = [];
        $alphas = [];
        $topAverages = [];
        $universeAverages = [];
        $topReturns = [];
        $universeReturns = [];
        $droppedLowBreadth = 0;
        $droppedOverlapping = 0;
        // P3.4: contadores de descarte de la neutralizacion momentum (ver
        // `rankByMomentumNeutral()`). Se quedan en 0 fuera de
        // $mode='momentum' -- irrelevantes ahi, mismo criterio que
        // `samples_dropped_not_member` cuando no hay universo point-in-time.
        $droppedThinSector = 0;
        $droppedNoMarketCapPit = 0;
        // P3.3: descarte propio de `rankByFundamentalNeutral()` (ver su
        // docblock), mismo criterio de "0 fuera de $mode='fundamental'"
        // que los dos contadores de arriba.
        $droppedNoFundamentalsPit = 0;
        // roadmap.md, "Prioridad cero-ter" punto 1 (2026-09-04): descarte
        // propio de `rankByFundamentalNeutral()` cuando NINGUNA de las tres
        // familias aporto dato utilizable ese dia (mismo criterio "0 fuera
        // de $mode='fundamental'" que el resto de contadores de esta zona).
        $droppedNoUsableFactors = 0;
        $lastEvaluatedDate = null;

        foreach ($samplesByDate as $date => $daySamples) {
            if (count($daySamples) <= $topN) {
                $droppedLowBreadth++;
                continue;
            }

            if ($lastEvaluatedDate !== null) {
                $sessionGap = $this->tradingSessionGap($lastEvaluatedDate, (string) $date, $sessionIndex);

                if ($sessionGap === null || $sessionGap < $horizonDays) {
                    $droppedOverlapping++;
                    continue;
                }
            }

            // roadmap.md, "Prioridad cero-ter" punto 5 (2026-09-04):
            // $eligibleReturns queda en null para 'full'/'technical' (sin
            // filtro de elegibilidad propio, la alpha se mide contra
            // $daySamples completo, como siempre). Para 'momentum'/
            // 'fundamental' recoge los retornos del subconjunto ELIGIBLE
            // que devuelve cada ranking (`$ranking['eligible']`): el
            // universo COMPLETO que sobrevivio a los filtros de esa funcion
            // antes del top-N, no solo los $topN seleccionados. Mezclar el
            // top elegido entre elegibles con un benchmark que incluye
            // tickers que nunca podian entrar (sin marketCap PIT, sector
            // demasiado pequeño, sin fundamentales/factores PIT...)
            // desplazaria la alpha sin motivo.
            $eligibleReturns = null;

            if ($mode === 'momentum') {
                $ranking = $this->rankByMomentumNeutral($daySamples, $topN);
                $droppedThinSector += $ranking['dropped_thin_sector'];
                $droppedNoMarketCapPit += $ranking['dropped_no_marketcap_pit'];

                if ($ranking['eligible'] === []) {
                    // Ninguna muestra sobrevivio a la neutralizacion
                    // sector/tamaño ese dia (sectores todos demasiado
                    // pequeños, o ninguna con marketCap point-in-time): sin
                    // candidatos que ordenar no hay alpha que calcular.
                    // Mismo criterio que "amplitud insuficiente" de arriba;
                    // en la practica, muy improbable con un universo de
                    // cientos de tickers.
                    $droppedLowBreadth++;
                    continue;
                }

                $selected = $ranking['selected'];
                $eligibleReturns = array_column($ranking['eligible'], 'forward_return');
            } elseif ($mode === 'fundamental') {
                $ranking = $this->rankByFundamentalNeutral($daySamples, $topN);
                $droppedNoFundamentalsPit += $ranking['dropped_no_fundamentals_pit'];
                $droppedNoUsableFactors += $ranking['dropped_no_usable_factors'];

                if ($ranking['eligible'] === []) {
                    // Mismo criterio que 'momentum' arriba: sin
                    // supervivientes con fundamentales/factores utilizables
                    // ese dia (muy improbable con un universo de cientos de
                    // tickers), no hay alpha que calcular.
                    $droppedLowBreadth++;
                    continue;
                }

                $selected = $ranking['selected'];
                $eligibleReturns = array_column($ranking['eligible'], 'forward_return');
            } else {
                $selected = array_slice($this->rankByPercentage($daySamples), 0, $topN);
            }

            $lastEvaluatedDate = (string) $date;
            $selectedReturns = array_column($selected, 'forward_return');
            $dayReturns = array_column($daySamples, 'forward_return');
            $topAverage = array_sum($selectedReturns) / count($selectedReturns);
            // La cifra PRINCIPAL (la que entra en alpha/avg_universe_forward_return
            // y en todos los t-stats de crossSectionalStatistics()): contra
            // $eligibleReturns cuando lo hay, contra $daySamples completo si
            // no (full/technical, sin cambio de comportamiento).
            $universeReturnsForAlpha = $eligibleReturns ?? $dayReturns;
            $universeAverage = array_sum($universeReturnsForAlpha) / count($universeReturnsForAlpha);

            $alphas[] = $topAverage - $universeAverage;
            $topAverages[] = $topAverage;
            $universeAverages[] = $universeAverage;
            $topReturns = array_merge($topReturns, $selectedReturns);
            $universeReturns = array_merge($universeReturns, $universeReturnsForAlpha);

            $dateEntry = [
                'date' => (string) $date,
                'universe_size' => count($daySamples),
                'top_tickers' => array_column($selected, 'ticker'),
                'top_avg_forward_return' => round($topAverage, 2),
                'universe_avg_forward_return' => round($universeAverage, 2),
                'alpha' => round($topAverage - $universeAverage, 2),
            ];

            if ($eligibleReturns !== null) {
                // Diagnostico secundario (roadmap.md, "Prioridad cero-ter"
                // punto 5): misma comparacion que antes de este cambio,
                // contra $daySamples completo, conservada para poder
                // contrastar ambas lecturas sin perder la anterior. NO entra
                // en $alphas/$universeReturns (la cifra principal ya usa el
                // universo elegible), asi que no afecta a
                // crossSectionalStatistics().
                $universeAverageAllAvailable = array_sum($dayReturns) / count($dayReturns);
                $dateEntry['universe_avg_forward_return_all_available'] = round($universeAverageAllAvailable, 2);
                $dateEntry['alpha_vs_all_available'] = round($topAverage - $universeAverageAllAvailable, 2);
            }

            $dates[] = $dateEntry;
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
                // P0.3: cuantas muestras del universo se descartaron por no
                // tener suficiente historico para Momentum 12-1 (ver el
                // docblock de $momentumNullDropped). Se publica igual que
                // dates_dropped_low_breadth/dates_dropped_overlapping para
                // que la merma sea visible, no implicita.
                'samples_dropped_momentum_null' => $momentumNullDropped,
                // P3.4: descartes propios de la neutralizacion momentum
                // (ver `rankByMomentumNeutral()`), publicados igual que el
                // resto de contadores de merma -- 0/0 cuando $mode no es
                // 'momentum', no se llego a comprobar nada.
                'samples_dropped_thin_sector' => $droppedThinSector,
                'samples_dropped_no_marketcap_pit' => $droppedNoMarketCapPit,
                // P3.3: descarte propio de `rankByFundamentalNeutral()`,
                // publicado igual que el resto -- 0 cuando $mode no es
                // 'fundamental', no se llego a comprobar nada.
                'samples_dropped_no_fundamentals_pit' => $droppedNoFundamentalsPit,
                // roadmap.md, "Prioridad cero-ter" punto 1 (2026-09-04):
                // cuantas muestras con fundamentales point-in-time reales
                // no aportaron NINGUN factor utilizable (ni valor propio ni
                // peers suficientes en ninguna de las tres familias), mismo
                // criterio de "0 fuera de $mode='fundamental'" de arriba.
                'samples_dropped_no_usable_factors' => $droppedNoUsableFactors,
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
     * P3.4 (`REVISION_MOTOR_CODEX_2026-09-02.md`, seccion "3. Nuevo modo
     * 'momentum'"): cualquier sector con menos de este numero de muestras
     * elegibles en una fecha queda fuera de la neutralizacion ese dia
     * (`rankByMomentumNeutral()`) -- con pocos pares no hay con que
     * neutralizar de forma fiable.
     */
    private const MIN_SECTOR_SAMPLES_MOMENTUM = 20;

    /**
     * Selecciona el top-N de una fecha para $mode='momentum': ranking por
     * Momentum 12-1 NEUTRALIZADO por sector y por tamaño, no por
     * `percentage` (el score de 50 puntos). Se calcula DENTRO de la fecha
     * (informacion transversal del dia, no por ticker aislado), en cuatro
     * pasos:
     *
     * a. Agrupa `$daySamples` por sector; cualquier sector con menos de
     *    `MIN_SECTOR_SAMPLES_MOMENTUM` muestras ese dia queda excluido POR
     *    COMPLETO de la neutralizacion (no solo de su propia mediana).
     * b. De las restantes, cualquiera sin `market_cap_is_point_in_time`
     *    real tambien se descarta: sin eso no se puede confiar en su bucket
     *    de tamaño (tercil).
     * c. `momentum_sector_neutral` = `momentum12m1` menos la mediana del
     *    mismo sector, misma fecha, entre las supervivientes de (a)+(b).
     * d. Terciles de `market_cap` cross-sectional entre las supervivientes
     *    (rango 0/1/2 por posicion relativa, no por cuantil fijo de valor);
     *    `momentum_neutral` = `momentum_sector_neutral` menos la mediana
     *    del mismo tercil, misma fecha.
     * e. Top-N por `momentum_neutral` descendente, mismo desempate
     *    alfabetico que `rankByPercentage()`.
     *
     * @param list<array{ticker: string, percentage: float, forward_return: float, momentum12m1: ?float, sector: string, market_cap: ?float, market_cap_is_point_in_time: bool}> $daySamples
     * @return array{selected: list<array{ticker: string, forward_return: float}>, eligible: list<array{ticker: string, forward_return: float}>, dropped_thin_sector: int, dropped_no_marketcap_pit: int}
     */
    private function rankByMomentumNeutral(array $daySamples, int $topN): array
    {
        $bySector = [];

        foreach ($daySamples as $sample) {
            $bySector[$sample['sector']][] = $sample;
        }

        // a. Sectores demasiado pequeños ese dia, fuera por completo.
        $eligible = [];
        $droppedThinSector = 0;

        foreach ($bySector as $sectorSamples) {
            if (count($sectorSamples) < self::MIN_SECTOR_SAMPLES_MOMENTUM) {
                $droppedThinSector += count($sectorSamples);

                continue;
            }

            foreach ($sectorSamples as $sample) {
                $eligible[] = $sample;
            }
        }

        // b. Sin marketCap point-in-time no se puede confiar en su tercil.
        $survivors = [];
        $droppedNoMarketCapPit = 0;

        foreach ($eligible as $sample) {
            if ($sample['market_cap_is_point_in_time'] !== true || $sample['market_cap'] === null) {
                $droppedNoMarketCapPit++;

                continue;
            }

            // roadmap.md, "Prioridad cero-ter" punto 2 (2026-09-04):
            // momentum12m1 paso a ser `?float` en la muestra compartida
            // porque `sampleHistory()` ya no lo exige para
            // mode='fundamental' -- pero SIGUE exigiendolo (descarta la
            // muestra entera, ver P0.3) para mode='momentum', el UNICO modo
            // que invoca esta funcion. Este chequeo nunca deberia disparar
            // en la practica: es un guard de tipos (reasignar el campo tras
            // la comprobacion deja `$sample` con `momentum12m1: float` de
            // cara a PHPStan), no una regla de negocio nueva.
            $momentum = $sample['momentum12m1'];

            if ($momentum === null) {
                $droppedNoMarketCapPit++;

                continue;
            }

            $sample['momentum12m1'] = $momentum;
            $survivors[] = $sample;
        }

        if ($survivors === []) {
            return [
                'selected' => [],
                'eligible' => [],
                'dropped_thin_sector' => $droppedThinSector,
                'dropped_no_marketcap_pit' => $droppedNoMarketCapPit,
            ];
        }

        // Punto 5 (roadmap.md, "Prioridad cero-ter", 2026-09-04): el
        // universo ELEGIBLE para el benchmark de alpha es exactamente este
        // conjunto -- los que ya pasaron (a) y (b), antes de neutralizar y
        // recortar al top-N. Tomado aqui, antes de que los pasos c-e muten
        // $survivors/$byMarketCap con campos derivados.
        $eligibleResult = array_map(
            static fn (array $sample): array => [
                'ticker' => $sample['ticker'],
                'forward_return' => $sample['forward_return'],
            ],
            $survivors
        );

        // c. Neutralizacion sectorial: momentum menos la mediana de su
        // propio sector, misma fecha, entre las supervivientes de (a)+(b).
        $momentumsBySector = [];

        foreach ($survivors as $sample) {
            $momentumsBySector[$sample['sector']][] = $sample['momentum12m1'];
        }

        $sectorMedians = [];

        foreach ($momentumsBySector as $sector => $momentums) {
            $sectorMedians[$sector] = $this->median($momentums);
        }

        foreach ($survivors as &$sample) {
            $sample['momentum_sector_neutral'] = $sample['momentum12m1'] - $sectorMedians[$sample['sector']];
        }

        unset($sample);

        // d. Terciles de tamaño cross-sectional entre las supervivientes
        // (ordenadas por marketCap ascendente, bucket = posicion relativa
        // dentro del dia, no un umbral de valor fijo).
        $byMarketCap = $survivors;
        usort(
            $byMarketCap,
            static fn (array $left, array $right): int => $left['market_cap'] <=> $right['market_cap']
        );
        $survivorCount = count($byMarketCap);
        /** @var array<int,list<float>> $tercileMomentums Sin pre-sembrar con [] por indice: PHPStan infiere despues (erroneamente) que toda entrada acumulada en el bucle de abajo es non-empty-list, y marca como codigo muerto la comprobacion de vacio que sigue -- ver el comentario de mas abajo, un tercil SI puede quedar sin ninguna muestra. */
        $tercileMomentums = [];

        foreach ($byMarketCap as $rank => &$sample) {
            $sample['size_tercile'] = min(intdiv($rank * 3, $survivorCount), 2);
            $tercileMomentums[$sample['size_tercile']][] = $sample['momentum_sector_neutral'];
        }

        unset($sample);

        // Con muy pocas supervivientes (self::MIN_SECTOR_SAMPLES_MOMENTUM ya
        // garantiza >=20 por sector superviviente, pero un dia con un unico
        // sector elegible podria dejar algun tercil vacio) un tercil puede
        // no recibir ninguna muestra: 0,0 de relleno, nunca leido de verdad
        // porque ningun `size_tercile` apunta a el (`median()` con un array
        // vacio no es un caso valido, ver su docblock).
        $tercileMedians = [0 => 0.0, 1 => 0.0, 2 => 0.0];

        foreach ([0, 1, 2] as $tercile) {
            if (isset($tercileMomentums[$tercile])) {
                $tercileMedians[$tercile] = $this->median($tercileMomentums[$tercile]);
            }
        }

        foreach ($byMarketCap as &$sample) {
            $sample['momentum_neutral'] = $sample['momentum_sector_neutral'] - $tercileMedians[$sample['size_tercile']];
        }

        unset($sample);

        // e. Top-N por momentum_neutral descendente.
        usort(
            $byMarketCap,
            static fn (array $left, array $right): int
                => [$right['momentum_neutral'], $left['ticker']] <=> [$left['momentum_neutral'], $right['ticker']]
        );

        $selected = array_map(
            static fn (array $sample): array => [
                'ticker' => $sample['ticker'],
                'forward_return' => $sample['forward_return'],
            ],
            array_slice($byMarketCap, 0, $topN)
        );

        return [
            'selected' => $selected,
            'eligible' => $eligibleResult,
            'dropped_thin_sector' => $droppedThinSector,
            'dropped_no_marketcap_pit' => $droppedNoMarketCapPit,
        ];
    }

    /**
     * P3.3 (`REVISION_MOTOR_CODEX_2026-09-02.md`, seccion P3.3, mas la
     * especificacion de `inversor-fundamental`/`auditor-estadistico`), REDUCIDO
     * de siete a **cinco** factores el `2026-09-04` (`roadmap.md`, "Prioridad
     * cero-ter", punto 1): `earnings_yield` y `cash_conversion` son `null`
     * en el 100% de los snapshots de `fundamentals_history` (se añadieron
     * DESPUES de generar esa tabla), asi que en la practica no aportaban
     * percentil real, solo el punto medio constante (50) que
     * `RelativeFundamentalScorer::pointsFor(null, 100)` devuelve para "sin
     * dato" -- eso hacia que Solidez (deuda/patrimonio, el unico factor real
     * de esa familia) pesara ~1/3 del score total en vez de ~1/9 como cada
     * factor activo de Valor/Calidad. Quedan los cinco factores realmente
     * presentes en el historico, agrupados en las mismas tres familias
     * (`value`/`quality` con dos factores cada una, `soundness` con uno) con
     * peso igual en el calculo. Cada entrada es la clave de la muestra
     * (`sampleHistory()`) y si un valor MAYOR es mejor para
     * `RelativeFundamentalScorer::percentileRank()`.
     *
     * Ausencia de dato ya NO cae al punto medio (ver
     * `rankByFundamentalNeutral()`): un factor sin valor propio, o sin
     * peers suficientes, se EXCLUYE del promedio de su familia en vez de
     * puntuar como neutral -- "sin opinar" y "neutral" son cosas distintas,
     * y confundirlas fue precisamente la causa del sesgo de arriba.
     *
     * Deliberadamente sin la familia "Cambio" (mejora YoY de margen, ROIC,
     * deuda, ventas, FCF): exige una segunda consulta point-in-time por
     * muestra (fecha - 365 dias) para cada campo, que no existe hoy y no es
     * un cambio trivial -- queda como pendiente explicito, no implementado
     * en este lote.
     *
     * @var array<string,list<array{field: string, higher_is_better: bool}>>
     */
    private const FUNDAMENTAL_FACTORS = [
        'value' => [
            ['field' => 'free_cash_flow_yield', 'higher_is_better' => true],
            ['field' => 'ev_to_ebitda', 'higher_is_better' => false],
        ],
        'quality' => [
            ['field' => 'roic', 'higher_is_better' => true],
            ['field' => 'operating_margin', 'higher_is_better' => true],
        ],
        'soundness' => [
            ['field' => 'debt_to_equity', 'higher_is_better' => false],
        ],
    ];

    /**
     * Selecciona el top-N de una fecha para $mode='fundamental': ranking
     * por un score fundamental de tres familias (Valor/Calidad/Solidez,
     * `self::FUNDAMENTAL_FACTORS`), cada una puntuada por posicion
     * PERCENTIL dentro del propio sector el mismo dia
     * (`RelativeFundamentalScorer`), no por umbrales absolutos tipo Graham.
     *
     * a. Descarta muestras sin fundamentales point-in-time reales
     *    (`fundamentals_is_point_in_time !== true`): sin eso no se puede
     *    confiar en ningun factor de la muestra, el snapshot entero viene
     *    del fallback a hoy.
     * b. Con las supervivientes, agrupa por sector -- los peers de cada
     *    factor son SOLO del mismo sector, MISMO DIA, nunca todo el
     *    universo.
     * c. Por cada uno de los cinco factores (`self::FUNDAMENTAL_FACTORS`,
     *    reducido de siete el `2026-09-04`, ver su docblock): si la propia
     *    muestra no tiene dato para ese factor, o si el sector no llega a
     *    `RelativeFundamentalScorer::MIN_PEERS` (8) peers con dato no nulo
     *    para ese factor concreto, el factor se EXCLUYE del promedio de su
     *    familia -- no se llama a `pointsFor(null, 100)` ni se inyecta un
     *    50 (`roadmap.md`, "Prioridad cero-ter" punto 1, `2026-09-04`):
     *    ausencia de dato no es lo mismo que "neutral", y tratarlas igual
     *    fue precisamente lo que hizo que Solidez pesara el triple de lo
     *    que deberia en la medicion de P3.3 (`versions.md`, entradas del
     *    `2026-09-03`). A diferencia de
     *    `rankByMomentumNeutral()`, aqui NO se excluye el sector entero por
     *    tener pocas muestras, es un diseño deliberado (especificado por
     *    `inversor-fundamental`/`auditor-estadistico`, 2026-09-03): con un
     *    numero reducido de sectores y un universo de cientos de tickers,
     *    excluir el sector completo dejaria demasiado pocas muestras
     *    utilizables, a diferencia del filtro de tamaño minimo que si tiene
     *    sentido para Momentum 12-1.
     * d. `valor_familia` = media de `pointsFor()` de los factores CON dato
     *    utilizable de esa familia (Solidez tiene un unico factor: su
     *    "media" es el si mismo). Si ninguno de los factores de una familia
     *    tuvo dato utilizable ese dia, la familia entera se excluye del
     *    promedio siguiente en vez de aportar un valor inventado.
     *    `fundamental_score` = media de las familias CON dato (peso igual
     *    entre ellas, sin inclinar hacia ninguna). Si NINGUNA de las tres
     *    familias aporto dato utilizable, la muestra entera se excluye del
     *    ranking (`dropped_no_usable_factors`): sin datos, no hay opinion
     *    que dar, y menos que competir en el top-N.
     * e. Top-N por `fundamental_score` descendente, mismo desempate
     *    alfabetico que `rankByPercentage()`/`rankByMomentumNeutral()`.
     *
     * @param list<array{ticker: string, percentage: float, forward_return: float, sector: string, fundamentals_is_point_in_time: bool, free_cash_flow_yield: ?float, ev_to_ebitda: ?float, roic: ?float, operating_margin: ?float, debt_to_equity: ?float, earnings_yield: ?float, cash_conversion: ?float}> $daySamples
     * @return array{selected: list<array{ticker: string, forward_return: float}>, eligible: list<array{ticker: string, forward_return: float}>, dropped_no_fundamentals_pit: int, dropped_no_usable_factors: int}
     */
    private function rankByFundamentalNeutral(array $daySamples, int $topN): array
    {
        // a. Sin fundamentales point-in-time reales, fuera.
        $survivors = [];
        $droppedNoFundamentalsPit = 0;

        foreach ($daySamples as $sample) {
            if ($sample['fundamentals_is_point_in_time'] !== true) {
                $droppedNoFundamentalsPit++;

                continue;
            }

            $survivors[] = $sample;
        }

        if ($survivors === []) {
            return [
                'selected' => [],
                'eligible' => [],
                'dropped_no_fundamentals_pit' => $droppedNoFundamentalsPit,
                'dropped_no_usable_factors' => 0,
            ];
        }

        // b. Peers por sector, MISMO DIA -- tomados de $survivors, que no se
        // muta: `$usable` de abajo es un array nuevo, para que un peer nunca
        // se compare consigo mismo con datos ya recalculados.
        $bySector = [];

        foreach ($survivors as $sample) {
            $bySector[$sample['sector']][] = $sample;
        }

        // c. + d. Percentil por factor CON dato, media por familia CON dato,
        // media de familias CON dato. Cualquier nivel sin nada utilizable se
        // excluye del nivel siguiente en vez de rellenarse con un 50.
        $usable = [];
        $droppedNoUsableFactors = 0;

        foreach ($survivors as $sample) {
            $sectorPeers = $bySector[$sample['sector']];
            $sampleValues = $this->fundamentalFactorValues($sample);
            $familyScores = [];

            foreach (self::FUNDAMENTAL_FACTORS as $family => $factors) {
                $points = [];

                foreach ($factors as $factor) {
                    $field = $factor['field'];
                    $value = $sampleValues[$field];

                    if ($value === null) {
                        // Sin dato para ESTA muestra en este factor: se
                        // excluye del promedio de la familia, no se rellena.
                        continue;
                    }

                    $peerValues = [];

                    foreach ($sectorPeers as $peer) {
                        if ($peer['ticker'] === $sample['ticker']) {
                            continue;
                        }

                        $peerValue = $this->fundamentalFactorValues($peer)[$field];

                        if ($peerValue !== null) {
                            $peerValues[] = $peerValue;
                        }
                    }

                    $percentile = $this->fundamentalScorer->percentileRank($value, $peerValues, $factor['higher_is_better']);

                    if ($percentile === null) {
                        // Sector con menos de MIN_PEERS peers con dato no
                        // nulo para este factor concreto: misma exclusion
                        // que "sin dato propio", no un 50 de relleno.
                        continue;
                    }

                    $points[] = $this->fundamentalScorer->pointsFor($percentile, 100.0);
                }

                if ($points === []) {
                    // Ningun factor de esta familia tuvo dato utilizable
                    // hoy: la familia entera queda fuera del promedio.
                    continue;
                }

                $familyScores[$family] = array_sum($points) / count($points);
            }

            if ($familyScores === []) {
                // Ninguna de las tres familias aporto dato utilizable: la
                // muestra no puede rankearse, ni siquiera ser "elegible".
                $droppedNoUsableFactors++;

                continue;
            }

            $sample['fundamental_score'] = array_sum($familyScores) / count($familyScores);
            $usable[] = $sample;
        }

        if ($usable === []) {
            return [
                'selected' => [],
                'eligible' => [],
                'dropped_no_fundamentals_pit' => $droppedNoFundamentalsPit,
                'dropped_no_usable_factors' => $droppedNoUsableFactors,
            ];
        }

        // e. Top-N por fundamental_score descendente.
        usort(
            $usable,
            static fn (array $left, array $right): int
                => [$right['fundamental_score'], $left['ticker']] <=> [$left['fundamental_score'], $right['ticker']]
        );

        $eligible = array_map(
            static fn (array $sample): array => [
                'ticker' => $sample['ticker'],
                'forward_return' => $sample['forward_return'],
            ],
            $usable
        );

        return [
            'selected' => array_slice($eligible, 0, $topN),
            'eligible' => $eligible,
            'dropped_no_fundamentals_pit' => $droppedNoFundamentalsPit,
            'dropped_no_usable_factors' => $droppedNoUsableFactors,
        ];
    }

    /**
     * Los siete valores fundamentales crudos de una muestra en un mapa
     * plano (campo => valor), para poder indexar por el `field` de
     * `self::FUNDAMENTAL_FACTORS` (una cadena que PHPStan no puede acotar a
     * un literal fijo) sin arriesgar un acceso de indice dinamico sobre el
     * array-shape estricto de `$daySamples`/`$survivors`. Devuelve los
     * siete campos (incluidos `earnings_yield`/`cash_conversion`, aunque
     * `self::FUNDAMENTAL_FACTORS` ya no los use para rankear desde el
     * `2026-09-04`): siguen expuestos aqui porque otros consumidores de la
     * muestra (ficha de detalle, API) los muestran igualmente.
     *
     * @param array{free_cash_flow_yield: ?float, ev_to_ebitda: ?float, roic: ?float, operating_margin: ?float, debt_to_equity: ?float, earnings_yield: ?float, cash_conversion: ?float} $sample
     * @return array<string,?float>
     */
    private function fundamentalFactorValues(array $sample): array
    {
        return [
            'free_cash_flow_yield' => $sample['free_cash_flow_yield'],
            'ev_to_ebitda' => $sample['ev_to_ebitda'],
            'roic' => $sample['roic'],
            'operating_margin' => $sample['operating_margin'],
            'debt_to_equity' => $sample['debt_to_equity'],
            'earnings_yield' => $sample['earnings_yield'],
            'cash_conversion' => $sample['cash_conversion'],
        ];
    }

    /**
     * Mediana simple (posicion, no varianza): promedio de los dos valores
     * centrales si el numero de elementos es par, el valor central si es
     * impar. Reimplementada aqui en vez de compartida porque no hay un
     * lugar comun de utilidades numericas en el proyecto todavia -- mismo
     * criterio que `DividendGrowthCalculator::median()`, cada clase que
     * necesita una mediana la calcula localmente.
     *
     * @param list<float> $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    /**
     * Sesiones bursatiles REALES entre dos fechas ya evaluadas (P0.2, ver el
     * docblock de `runCrossSectional()`). `$sessionIndex` es la posicion de
     * cada fecha dentro del calendario bursatil real construido con la UNION
     * de las fechas de `$history` de todos los tickers recorridos: no un
     * calendario generico de mercado, sino literalmente los dias en los que
     * al menos un ticker del universo tuvo una vela. `null` si alguna de las
     * dos fechas no aparece en ese calendario (no deberia ocurrir nunca en la
     * practica, ya que toda fecha de `$samplesByDate` viene de una vela real
     * de algun ticker ya recorrido, pero se trata como "no se puede
     * confirmar independencia" en vez de asumirla).
     *
     * @param array<string,int> $sessionIndex
     */
    private function tradingSessionGap(string $previousDate, string $currentDate, array $sessionIndex): ?int
    {
        if (!isset($sessionIndex[$previousDate], $sessionIndex[$currentDate])) {
            return null;
        }

        return $sessionIndex[$currentDate] - $sessionIndex[$previousDate];
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
     * Devuelve tambien `$history` (P0.2, ver el docblock de
     * `runCrossSectional()`): esa fecha de la caller construye el calendario
     * bursatil real del universo con el mismo historico que ya pedia, sin
     * una segunda llamada al proveedor de mercado por ticker.
     *
     * @return array{samples: list<array{date: string, recommendation: string, percentage: float, forward_return: float, managed_return: ?float, exit_reason: ?string, exit_day: ?int, momentum12m1: ?float, sector: string, market_cap: ?float, market_cap_is_point_in_time: bool, free_cash_flow_yield: ?float, ev_to_ebitda: ?float, roic: ?float, operating_margin: ?float, debt_to_equity: ?float, earnings_yield: ?float, cash_conversion: ?float, fundamentals_is_point_in_time: bool}>, history: list<HistoricalQuote>}
     */
    private function collectSamplesWithHistory(string $ticker, int $horizonDays, int $step, string $mode): array
    {
        $this->assertValidMode($mode);

        $stock = $this->enrichWithDividendGrowth($this->marketDataProvider->getStock($ticker), $ticker);
        $history = $this->marketDataProvider->getHistoricalQuotes($ticker);

        return [
            'samples' => $this->sampleHistory($stock, $history, $horizonDays, $step, $mode),
            'history' => $history,
        ];
    }

    /**
     * P0.1 (`versions.md`, 2026-09-02): la recomendacion se genera con el
     * cierre de `$current` (el ultimo dato conocido al analizar), pero el
     * cron real corre despues del cierre de EEUU -- ese precio no es
     * operable. La entrada mas pronto ejecutable es la APERTURA de la sesion
     * siguiente (`$history[$index + 1]`), y `forward_return`/el horizonte de
     * `simulateManagedExit()` se miden `$horizonDays` sesiones DESDE ESA
     * ENTRADA, no desde la señal: por eso el bucle exige una sesion mas de
     * margen que antes (`$index + 1 + $horizonDays`, no `$index +
     * $horizonDays`). Si `$current` fuese la ultima barra del historico no
     * habria apertura siguiente conocida; el limite del bucle ya lo impide
     * (nunca entra con menos margen del que exige `$entryIndex +
     * $horizonDays < $count`), asi que esa muestra simplemente no se genera,
     * sin necesidad de un descarte aparte.
     *
     * @param list<HistoricalQuote> $history
     * @return list<array{date: string, recommendation: string, percentage: float, forward_return: float, managed_return: ?float, exit_reason: ?string, exit_day: ?int, momentum12m1: ?float, sector: string, market_cap: ?float, market_cap_is_point_in_time: bool, free_cash_flow_yield: ?float, ev_to_ebitda: ?float, roic: ?float, operating_margin: ?float, debt_to_equity: ?float, earnings_yield: ?float, cash_conversion: ?float, fundamentals_is_point_in_time: bool}>
     */
    private function sampleHistory(Stock $stock, array $history, int $horizonDays, int $step, string $mode): array
    {
        $samples = [];
        $minimumLookback = 80;
        $count = count($history);
        // P0.3: se reinicia al EMPEZAR el recorrido de ESTE historico (una
        // llamada = un ticker), ver el docblock de la propiedad.
        $this->momentumNullDropped = 0;

        for ($index = $minimumLookback; $index < $count - $horizonDays - 1; $index += $step) {
            $past = array_slice($history, 0, $index + 1);
            $current = $history[$index];
            $entryIndex = $index + 1;
            $entry = $history[$entryIndex];
            $entryPrice = $entry->getOpen();
            $future = $history[$entryIndex + $horizonDays];
            $synthetic = $this->stockAt($stock, $current);
            // P3.4 (`REVISION_MOTOR_CODEX_2026-09-02.md`): `stockAt()` (via
            // `fundamentalsAt()`) acaba de decidir si el marketCap de ESTA
            // muestra vino de un snapshot historico real o del fallback a
            // hoy (ver el docblock de $lastMarketCapWasPointInTime).
            // Capturado en variable local antes de que la siguiente
            // iteracion lo sobrescriba.
            $marketCapIsPointInTime = $this->lastMarketCapWasPointInTime;
            $technical = $this->technicalAnalyzer->analyze($past);
            $momentum12m1 = $technical->getMomentum12m1();

            // P0.3: Momentum 12-1 (TechnicalAnalyzer::momentumSkippingRecent(),
            // 250 sesiones + 21 de salto) necesita mas de 250 cierres para no
            // devolver null. Con menos, TechnicalScoreAnalyzer::momentum() lo
            // rellenaba con un neutral SILENCIOSO (3,5 puntos, sin ningun
            // Signal que avisara), que competia sin distincion en el ranking
            // contra muestras con momentum real. Se descarta la muestra
            // entera -- no se rellena con un valor inventado -- y se cuenta
            // para que la merma quede visible (ver
            // `samples_dropped_momentum_null`/`fundamentals_point_in_time_pct`,
            // mismo criterio de "no ocultar lo que no se pudo medir").
            //
            // roadmap.md, "Prioridad cero-ter" punto 2 (2026-09-04): esta
            // exigencia se mantiene para 'full'/'technical'/'momentum' (los
            // tres usan momentum12m1, ya sea dentro del score o para
            // rankear en `rankByMomentumNeutral()`), pero NO para
            // 'fundamental': ese modo rankea por `fundamental_score`
            // (`rankByFundamentalNeutral()`), nunca lee `momentum12m1`, y
            // exigirlo igualmente descartaba ~9,7% de la muestra en la
            // corrida real de P3.3 por un motivo ajeno al ranking medido.
            // Verificado antes de este cambio que
            // `$this->scoreCalculator->calculate()` no rompe con
            // `$technical->getMomentum12m1() === null` mas abajo: solo lo
            // consume `TechnicalScoreAnalyzer::momentum()`, que ya rellena
            // un neutral silencioso (3,5/7 puntos) cuando falta -- ese
            // `percentage`/`recommendation` no se usa para rankear en modo
            // 'fundamental' (el ranking usa `fundamental_score`, no
            // `percentage`), asi que el neutral silencioso no contamina
            // nada aqui.
            if ($momentum12m1 === null && $mode !== 'fundamental') {
                ++$this->momentumNullDropped;

                continue;
            }

            $score = $this->scoreCalculator->calculate($synthetic, $technical)->getScore();
            $forwardReturn = (($future->getClose() / $entryPrice) - 1) * 100;

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
                $riskLevels = $this->riskLevelsCalculator->compute($technical, $entryPrice);

                if ($riskLevels !== null) {
                    [$exitReason, $exitPrice, $exitDay] = $this->simulateManagedExit(
                        $history,
                        $entryIndex,
                        $horizonDays,
                        $riskLevels,
                        $future
                    );
                    $managedReturn = $this->netManagedReturn($entryPrice, $exitPrice);
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
                // P3.4 (`REVISION_MOTOR_CODEX_2026-09-02.md`): datos crudos
                // para poder rankear por Momentum 12-1 neutralizado en vez de
                // por `percentage` (ver `BacktestingService::rankByMomentumNeutral()`),
                // sin recalcular nada -- `runCrossSectional()` los toma tal cual.
                'momentum12m1' => $momentum12m1,
                // Sector ACTUAL de la empresa (Company::getSector()), no
                // historico: el modelo no trackea reclasificaciones GICS por
                // fecha. Aproximacion aceptada explicitamente (mismo tipo de
                // limitacion ya asumida para dividendGrowth5y, ver el
                // docblock de enrichWithDividendGrowth()): no hay snapshot
                // historico de sector, asi que se usa el de hoy en todas las
                // fechas pasadas.
                'sector' => $stock->getCompany()->getSector(),
                'market_cap' => $synthetic->getFundamentals()->getMarketCap(),
                'market_cap_is_point_in_time' => $marketCapIsPointInTime,
                // P3.3 (`REVISION_MOTOR_CODEX_2026-09-02.md`): los siete
                // factores fundamentales crudos que consume
                // `rankByFundamentalNeutral()`, expuestos siempre (no solo
                // con $mode='fundamental') por el mismo motivo que los
                // campos de momentum de arriba -- no bifurcar esta
                // recopilacion por modo.
                'free_cash_flow_yield' => $synthetic->getFundamentals()->getFreeCashFlowYield(),
                'ev_to_ebitda' => $synthetic->getFundamentals()->getEvToEbitda(),
                'roic' => $synthetic->getFundamentals()->getRoic(),
                'operating_margin' => $synthetic->getFundamentals()->getOperatingMargin(),
                'debt_to_equity' => $synthetic->getFundamentals()->getDebtToEquity(),
                'earnings_yield' => $synthetic->getFundamentals()->getEarningsYield(),
                'cash_conversion' => $synthetic->getFundamentals()->getCashConversion(),
                // Mismo flag que `market_cap_is_point_in_time` (el snapshot
                // de Fundamentals es un unico objeto: no hay forma de que
                // unos campos vengan point-in-time y otros del fallback a
                // hoy, ver el docblock de `$lastMarketCapWasPointInTime`),
                // expuesto con su propio nombre para que
                // `rankByFundamentalNeutral()` no dependa de un nombre
                // pensado originalmente solo para marketCap.
                'fundamentals_is_point_in_time' => $marketCapIsPointInTime,
            ];
        }

        return $samples;
    }

    private function assertValidMode(string $mode): void
    {
        if (!in_array($mode, ['full', 'technical', 'momentum', 'fundamental'], true)) {
            throw new \InvalidArgumentException("Modo de backtest desconocido: '$mode'. Valores validos: 'full', 'technical', 'momentum', 'fundamental'.");
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
     *
     * P3.4: tambien deja constancia en `$lastMarketCapWasPointInTime` de si
     * el `marketCap` devuelto vino del snapshot o del fallback a hoy -- ver
     * su docblock. Es la misma decision que ya cuentan
     * `pointInTimeHits`/`pointInTimeMisses` (el snapshot es un unico
     * objeto, no hay forma de que unos campos vengan point-in-time y otros
     * no), expuesta por MUESTRA en vez de solo agregada.
     */
    private function fundamentalsAt(string $ticker, DateTimeImmutable $date, Fundamentals $today): Fundamentals
    {
        if (!$this->fundamentalsHistory instanceof FundamentalsHistoryRepository) {
            $this->lastMarketCapWasPointInTime = false;

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
            $this->lastMarketCapWasPointInTime = false;

            return $today;
        }

        ++$this->pointInTimeHits;
        $this->lastMarketCapWasPointInTime = true;
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
