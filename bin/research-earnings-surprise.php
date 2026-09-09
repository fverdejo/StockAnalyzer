<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Services\EarningsEventReturnCalculator;
use StockAnalyzer\Services\EodhdEarningsHistoryParser;
use StockAnalyzer\Services\PreEventTechnicalSnapshotCalculator;

/**
 * Estudio exploratorio aislado del score de produccion.
 *
 * Hipotesis primaria (declarada antes de ver el resultado): dentro de cada
 * mes de anuncio, el quintil con mayor `surprisePercent` de BPA obtiene mas
 * retorno posterior ajustado por SPY que el quintil inferior. Se prueban
 * solo 3 horizontes (5/20/60 sesiones), con umbral Bonferroni |t| >= 2,394.
 *
 * Ejecucion (solo usa datos locales; no llama a Yahoo ni EODHD):
 *   ddev exec php bin/research-earnings-surprise.php
 */

const EARNINGS_HORIZONS = [5, 20, 60];
const BONFERRONI_T_THRESHOLD = 2.394;
const TECHNICAL_HOLDOUT_START = '2022-01';
const TECHNICAL_MIN_FLAGGED_PER_MONTH = 3;

$options = getopt('', ['universe-file::', 'output::', 'min-estimate::', 'min-cohort::']);
$projectRoot = dirname(__DIR__);
$universeOption = is_string($options['universe-file'] ?? null)
    ? (string) $options['universe-file']
    : 'storage/scratch/point_in_time_universe.txt';
$outputOption = is_string($options['output'] ?? null)
    ? (string) $options['output']
    : 'storage/scratch/earnings_surprise_backtest_results.json';
$minimumEstimate = max(0.0, (float) ($options['min-estimate'] ?? 0.05));
$minimumCohort = max(10, (int) ($options['min-cohort'] ?? 20));
$universePath = absoluteProjectPath($projectRoot, $universeOption);
$outputPath = absoluteProjectPath($projectRoot, $outputOption);

if (!is_file($universePath)) {
    throw new RuntimeException('No existe el fichero de universo: ' . $universePath);
}

if (!is_dir(dirname($outputPath))) {
    throw new RuntimeException('No existe el directorio de salida: ' . dirname($outputPath));
}

$tickers = loadTickers($universePath);

if ($tickers === []) {
    throw new RuntimeException('El universo esta vacio.');
}

$connection = new Connection();
$pdo = $connection->getPdo();
$cache = new MarketDataCacheRepository($connection);
$parser = new EodhdEarningsHistoryParser();
$returnCalculator = new EarningsEventReturnCalculator();
$technicalCalculator = new PreEventTechnicalSnapshotCalculator();
$neverExpireDuringResearch = new DateInterval('P100Y');
$benchmarkHistory = $cache->findHistory('SPY', $neverExpireDuringResearch, '10y');

if ($benchmarkHistory === null || $benchmarkHistory === []) {
    throw new RuntimeException('Falta SPY 10y en market_history_cache; el estudio no descargara datos automaticamente.');
}

sortHistory($benchmarkHistory);
$benchmarkByDate = indexHistoryByDate($benchmarkHistory);
$memberships = loadMemberships($pdo);
$rawStatement = $pdo->prepare(
    'SELECT payload_json, fetched_at FROM eodhd_raw_fundamentals WHERE ticker = :ticker LIMIT 1'
);

$audit = [
    'universe_tickers' => count($tickers),
    'raw_payloads_found' => 0,
    'raw_payloads_missing' => 0,
    'raw_payloads_invalid' => 0,
    'price_histories_found' => 0,
    'price_histories_missing' => 0,
    'events_parsed' => 0,
    'events_after_archive_date' => 0,
    'events_reported_before_period_end' => 0,
    'events_missing_actual_or_estimate' => 0,
    'events_estimate_nonpositive_or_below_floor' => 0,
    'events_missing_surprise_percent' => 0,
    'events_without_entry_price_or_spy' => 0,
    'events_outside_index_membership' => 0,
    'events_using_unknown_membership_start' => 0,
    'technical_snapshots_available' => 0,
    'technical_snapshots_missing' => 0,
    'technical_snapshots_below_sma200' => 0,
    'eligible_events' => 0,
    'before_after_market' => [],
    'missing_return_by_horizon' => array_fill_keys(EARNINGS_HORIZONS, 0),
    'observations_by_horizon' => array_fill_keys(EARNINGS_HORIZONS, 0),
];

/** @var array<int,array<string,list<array{ticker:string,surprise:float,alpha:float,stock_return:float,entry_date:string,exit_date:string,below_sma200:?bool,distance_to_sma200_pct:?float}>>> $observations */
$observations = array_fill_keys(EARNINGS_HORIZONS, []);

foreach ($tickers as $position => $ticker) {
    $rawStatement->execute(['ticker' => $ticker]);
    $rawRow = $rawStatement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($rawRow) || !is_string($rawRow['payload_json'] ?? null)) {
        $audit['raw_payloads_missing']++;
        continue;
    }

    $audit['raw_payloads_found']++;

    try {
        $events = $parser->parse($ticker, $rawRow['payload_json']);
        $archiveDate = new DateTimeImmutable(substr((string) $rawRow['fetched_at'], 0, 10));
    } catch (Throwable) {
        $audit['raw_payloads_invalid']++;
        continue;
    }

    $history = $cache->findHistory($ticker, $neverExpireDuringResearch, '10y');

    if ($history === null || $history === []) {
        $audit['price_histories_missing']++;
        continue;
    }

    $audit['price_histories_found']++;
    sortHistory($history);

    foreach ($events as $event) {
        $audit['events_parsed']++;
        $timingLabel = strtolower($event->beforeAfterMarket ?? 'unknown');
        $audit['before_after_market'][$timingLabel] = ($audit['before_after_market'][$timingLabel] ?? 0) + 1;

        if ($event->reportDate > $archiveDate) {
            $audit['events_after_archive_date']++;
            continue;
        }

        if ($event->reportDate < $event->fiscalPeriodEnd) {
            $audit['events_reported_before_period_end']++;
            continue;
        }

        if ($event->epsActual === null || $event->epsEstimate === null) {
            $audit['events_missing_actual_or_estimate']++;
            continue;
        }

        // El porcentaje de sorpresa es inestable cuando el consenso ronda
        // cero y dificil de interpretar con BPA esperado negativo. Esta
        // primera prueba se limita por tanto a consenso positivo >= 0,05.
        if ($event->epsEstimate < $minimumEstimate) {
            $audit['events_estimate_nonpositive_or_below_floor']++;
            continue;
        }

        if ($event->surprisePercent === null) {
            $audit['events_missing_surprise_percent']++;
            continue;
        }

        // Horizonte 1 se usa solo para obtener la fecha de entrada. No es
        // una hipotesis adicional ni aparece entre los resultados.
        $entryProbe = $returnCalculator->calculate($event, $history, $benchmarkByDate, 1);

        if ($entryProbe === null) {
            $audit['events_without_entry_price_or_spy']++;
            continue;
        }

        $entryDate = $entryProbe['entry_date'];
        $membership = $memberships[$ticker] ?? null;

        if (!membershipCovers($membership, $entryDate)) {
            $audit['events_outside_index_membership']++;
            continue;
        }

        if (($membership['start_date'] ?? null) === null) {
            $audit['events_using_unknown_membership_start']++;
        }

        $audit['eligible_events']++;
        $technical = $technicalCalculator->calculate($event, $history);

        if ($technical === null) {
            $audit['technical_snapshots_missing']++;
        } else {
            $audit['technical_snapshots_available']++;
            $audit['technical_snapshots_below_sma200'] += $technical['below_sma200'] ? 1 : 0;
        }

        $cohort = $event->reportDate->format('Y-m');

        foreach (EARNINGS_HORIZONS as $horizon) {
            $return = $returnCalculator->calculate($event, $history, $benchmarkByDate, $horizon);

            if ($return === null) {
                $audit['missing_return_by_horizon'][$horizon]++;
                continue;
            }

            $observations[$horizon][$cohort][] = [
                'ticker' => $ticker,
                'surprise' => $event->surprisePercent,
                'alpha' => $return['market_adjusted_return_pct'],
                'stock_return' => $return['stock_return_pct'],
                'entry_date' => $return['entry_date'],
                'exit_date' => $return['exit_date'],
                'below_sma200' => $technical['below_sma200'] ?? null,
                'distance_to_sma200_pct' => $technical['distance_to_sma200_pct'] ?? null,
            ];
            $audit['observations_by_horizon'][$horizon]++;
        }
    }

    if (($position + 1) % 50 === 0) {
        fwrite(STDERR, sprintf("Procesados %d/%d tickers\n", $position + 1, count($tickers)));
    }
}

ksort($audit['before_after_market']);
$horizonResults = [];

foreach (EARNINGS_HORIZONS as $horizon) {
    $horizonResults[(string) $horizon] = analyzeHorizon(
        $observations[$horizon],
        $horizon,
        $minimumCohort
    );
}

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'study' => 'earnings_surprise_post_announcement_drift',
    'status' => 'research_only_not_used_by_production_score',
    'hypothesis' => 'El quintil mensual con mayor sorpresa de BPA supera al quintil inferior despues del anuncio.',
    'primary_metric' => 'mean_monthly_q5_minus_q1_market_adjusted_return_pct',
    'data' => [
        'fundamentals' => 'eodhd_raw_fundamentals.Earnings.History (archivo local)',
        'prices' => 'market_history_cache range=10y (archivo local)',
        'benchmark' => 'SPY, mismas fechas y convencion open-to-close',
        'universe_file' => projectRelativePath($projectRoot, $universePath),
    ],
    'predeclared_rules' => [
        'entry' => 'Apertura de la primera sesion estrictamente posterior a reportDate, tambien para BeforeMarket.',
        'exit' => 'Cierre situado N sesiones despues de la entrada.',
        'minimum_eps_estimate' => $minimumEstimate,
        'eps_estimate_rule' => 'Solo consenso positivo y >= minimum_eps_estimate.',
        'membership' => 'Debe pertenecer a GSPC en la fecha de entrada; start_date NULL se trata como ya miembro.',
        'ranking' => 'Quintiles globales dentro de cada mes de reportDate.',
        'minimum_events_per_month' => $minimumCohort,
        'horizons_sessions' => EARNINGS_HORIZONS,
        'newey_west_lag' => 'ceil(horizon/21), minimo 1, sobre series mensuales.',
        'multiple_testing' => 'Bonferroni para 3 horizontes primarios; evidencia si |t_HAC| >= 2.394.',
        'technical_follow_up' => [
            'feature' => 'Q1 de sorpresa mensual Y ultimo cierre anterior a reportDate por debajo de SMA200.',
            'single_horizon' => 60,
            'discovery_period' => 'Antes de 2022-01.',
            'locked_holdout' => 'Desde 2022-01.',
            'minimum_flagged_per_month' => TECHNICAL_MIN_FLAGGED_PER_MONTH,
            'success' => 'Holdout >=36 meses, universo-flags >=1 pp, t HAC >=2, >=60% meses positivos e incremento frente a Q1 con tendencia fuerte >0.',
        ],
    ],
    'known_limitations' => [
        'EODHD puede revisar historicamente epsEstimate o epsActual; un unico archivo actual no demuestra que el consenso no fuese revisado.',
        'El universo historico disponible incluye componentes actuales y antiguos capturados, pero puede no contener todas las bajas de diez anos.',
        'start_date desconocida se interpreta igual que IndexMembershipRepository: el valor pudo pertenecer antes del inicio del tracking de EODHD.',
        'Los precios son retornos de precio, sin modelar costes, deslizamiento, impuestos ni dividendos.',
        'Un resultado exploratorio positivo necesita una validacion temporal posterior antes de modificar el score.',
    ],
    'audit' => $audit,
    'horizons' => $horizonResults,
    'technical_interaction_60' => analyzeTechnicalInteraction(
        $observations[60],
        $minimumCohort,
        TECHNICAL_HOLDOUT_START,
        TECHNICAL_MIN_FLAGGED_PER_MONTH
    ),
];

$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

if (file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
    throw new RuntimeException('No se pudo escribir la salida: ' . $outputPath);
}

echo json_encode([
    'output' => projectRelativePath($projectRoot, $outputPath),
    'audit' => $audit,
    'primary_results' => array_map(
        static fn (array $horizon): array => [
            'cohorts' => $horizon['eligible_monthly_cohorts'],
            'mean_q5_minus_q1_alpha_pct' => $horizon['primary']['mean'],
            'newey_west_t' => $horizon['primary']['newey_west_t'],
            'bonferroni_evidence' => $horizon['primary']['bonferroni_evidence'],
            'early_half_mean' => $horizon['primary']['early_half_mean'],
            'late_half_mean' => $horizon['primary']['late_half_mean'],
            'top_vs_all_mean' => $horizon['secondary_top_vs_all']['mean'],
            'all_vs_bottom_mean' => $horizon['exploratory_all_vs_bottom']['mean'],
            'rank_ic_mean' => $horizon['diagnostic_monthly_rank_ic']['mean'],
        ],
        $horizonResults
    ),
    'technical_interaction_60' => $result['technical_interaction_60'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;

function absoluteProjectPath(string $root, string $path): string
{
    return str_starts_with($path, '/') ? $path : $root . '/' . ltrim($path, '/');
}

function projectRelativePath(string $root, string $path): string
{
    $prefix = rtrim($root, '/') . '/';

    return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
}

/** @return list<string> */
function loadTickers(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return [];
    }

    $tickers = [];

    foreach ($lines as $line) {
        foreach (preg_split('/[\s,;]+/', trim($line)) ?: [] as $ticker) {
            $ticker = strtoupper(trim($ticker));

            if ($ticker !== '') {
                $tickers[$ticker] = true;
            }
        }
    }

    return array_keys($tickers);
}

/** @param list<HistoricalQuote> $history */
function sortHistory(array &$history): void
{
    usort(
        $history,
        static fn (HistoricalQuote $left, HistoricalQuote $right): int => $left->getDate() <=> $right->getDate()
    );
}

/**
 * @param list<HistoricalQuote> $history
 * @return array<string,HistoricalQuote>
 */
function indexHistoryByDate(array $history): array
{
    $indexed = [];

    foreach ($history as $quote) {
        $indexed[$quote->getDate()->format('Y-m-d')] = $quote;
    }

    return $indexed;
}

/**
 * @return array<string,array{start_date:?string,end_date:?string}>
 */
function loadMemberships(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT ticker, start_date, end_date FROM index_membership WHERE index_code = 'GSPC'"
    );
    $memberships = [];

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $memberships[(string) $row['ticker']] = [
            'start_date' => is_string($row['start_date'] ?? null) ? $row['start_date'] : null,
            'end_date' => is_string($row['end_date'] ?? null) ? $row['end_date'] : null,
        ];
    }

    return $memberships;
}

/** @param array{start_date:?string,end_date:?string}|null $membership */
function membershipCovers(?array $membership, string $date): bool
{
    if ($membership === null) {
        return false;
    }

    if ($membership['start_date'] !== null && $date < $membership['start_date']) {
        return false;
    }

    if ($membership['end_date'] !== null && $date > $membership['end_date']) {
        return false;
    }

    return true;
}

/**
 * @param array<string,list<array{ticker:string,surprise:float,alpha:float,stock_return:float,entry_date:string,exit_date:string,below_sma200:?bool,distance_to_sma200_pct:?float}>> $byMonth
 * @return array<string,mixed>
 */
function analyzeHorizon(array $byMonth, int $horizon, int $minimumCohort): array
{
    ksort($byMonth);
    $cohorts = [];

    foreach ($byMonth as $month => $rows) {
        if (count($rows) < $minimumCohort) {
            continue;
        }

        usort($rows, static function (array $left, array $right): int {
            $bySurprise = $left['surprise'] <=> $right['surprise'];

            return $bySurprise !== 0 ? $bySurprise : $left['ticker'] <=> $right['ticker'];
        });

        $count = count($rows);
        $quintileSize = max(1, (int) floor($count * 0.20));
        $bottom = array_slice($rows, 0, $quintileSize);
        $top = array_slice($rows, -$quintileSize);
        $allAlpha = array_column($rows, 'alpha');
        $bottomAlpha = array_column($bottom, 'alpha');
        $topAlpha = array_column($top, 'alpha');
        $quintileMeans = [];

        for ($quintile = 0; $quintile < 5; $quintile++) {
            $start = (int) floor(($quintile * $count) / 5);
            $end = (int) floor((($quintile + 1) * $count) / 5);
            $slice = array_slice($rows, $start, max(1, $end - $start));
            $quintileMeans['q' . ($quintile + 1)] = rounded(mean(array_column($slice, 'alpha')));
        }

        $bottomMean = mean($bottomAlpha);
        $topMean = mean($topAlpha);
        $allMean = mean($allAlpha);
        $rankIc = spearman(
            array_map(static fn (array $row): float => $row['surprise'], $rows),
            array_map(static fn (array $row): float => $row['alpha'], $rows)
        );

        $cohorts[] = [
            'month' => $month,
            'events' => $count,
            'quintile_size' => $quintileSize,
            'q5_minus_q1_alpha_pct' => rounded($topMean - $bottomMean),
            'q5_minus_all_alpha_pct' => rounded($topMean - $allMean),
            'all_minus_q1_alpha_pct' => rounded($allMean - $bottomMean),
            'rank_ic' => rounded($rankIc),
            'quintile_alpha_pct' => $quintileMeans,
        ];
    }

    $lag = max(1, (int) ceil($horizon / 21));
    $primarySeries = array_map(static fn (array $row): float => $row['q5_minus_q1_alpha_pct'], $cohorts);
    $topVsAllSeries = array_map(static fn (array $row): float => $row['q5_minus_all_alpha_pct'], $cohorts);
    $allVsBottomSeries = array_map(static fn (array $row): float => $row['all_minus_q1_alpha_pct'], $cohorts);
    $rankIcSeries = array_values(array_filter(
        array_map(static fn (array $row): ?float => $row['rank_ic'], $cohorts),
        static fn (?float $value): bool => $value !== null
    ));
    $primary = stats($primarySeries, $lag);
    $split = splitMeans($primarySeries);
    $middle = intdiv(count($primarySeries), 2);
    $primary['early_half_mean'] = $split['early'];
    $primary['late_half_mean'] = $split['late'];
    $primary['early_half_stats'] = stats(array_slice($primarySeries, 0, $middle), $lag);
    $primary['late_half_stats'] = stats(array_slice($primarySeries, $middle), $lag);
    $primary['bonferroni_threshold_abs_t'] = BONFERRONI_T_THRESHOLD;
    $primary['bonferroni_evidence'] = $primary['newey_west_t'] !== null
        && abs($primary['newey_west_t']) >= BONFERRONI_T_THRESHOLD;

    $averageQuintileAlpha = [];

    foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $quintile) {
        $values = array_map(
            static fn (array $row): float => $row['quintile_alpha_pct'][$quintile],
            $cohorts
        );
        $averageQuintileAlpha[$quintile] = $values === [] ? null : rounded(mean($values));
    }

    return [
        'horizon_sessions' => $horizon,
        'newey_west_lag' => $lag,
        'eligible_monthly_cohorts' => count($cohorts),
        'primary' => $primary,
        'secondary_top_vs_all' => stats($topVsAllSeries, $lag),
        // Diagnostico anadido para interpretar si un spread positivo viene
        // de ganadores fuertes o de evitar sorpresas muy negativas. No es
        // una hipotesis primaria ni hereda su umbral de decision.
        'exploratory_all_vs_bottom' => stats($allVsBottomSeries, $lag),
        'diagnostic_monthly_rank_ic' => stats($rankIcSeries, $lag),
        'average_quintile_alpha_pct' => $averageQuintileAlpha,
        'cohorts' => $cohorts,
    ];
}

/**
 * Seguimiento tecnico predeclarado tras el estudio de sorpresa aislada.
 * Solo se evalua a 60 sesiones y el periodo desde 2022-01 actua como
 * holdout bloqueado antes de calcular esta interaccion.
 *
 * @param array<string,list<array{ticker:string,surprise:float,alpha:float,stock_return:float,entry_date:string,exit_date:string,below_sma200:?bool,distance_to_sma200_pct:?float}>> $byMonth
 * @return array<string,mixed>
 */
function analyzeTechnicalInteraction(
    array $byMonth,
    int $minimumCohort,
    string $holdoutStart,
    int $minimumFlagged
): array {
    ksort($byMonth);
    $cohorts = [];
    $purgedDiscoveryOutcomes = 0;
    $holdoutStartDate = $holdoutStart . '-01';
    $eventGroups = [
        'discovery' => ['universe' => [], 'bottom' => [], 'weak' => [], 'flagged' => []],
        'holdout' => ['universe' => [], 'bottom' => [], 'weak' => [], 'flagged' => []],
    ];

    foreach ($byMonth as $month => $rows) {
        $period = $month < $holdoutStart ? 'discovery' : 'holdout';
        if ($period === 'discovery') {
            $beforePurge = count($rows);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['exit_date'] < $holdoutStartDate
            ));
            $purgedDiscoveryOutcomes += $beforePurge - count($rows);
        }

        if (count($rows) < $minimumCohort) {
            continue;
        }

        usort($rows, static function (array $left, array $right): int {
            $bySurprise = $left['surprise'] <=> $right['surprise'];

            return $bySurprise !== 0 ? $bySurprise : $left['ticker'] <=> $right['ticker'];
        });

        $quintileSize = max(1, (int) floor(count($rows) * 0.20));
        $bottom = array_slice($rows, 0, $quintileSize);
        $weak = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['below_sma200'] === true
        ));
        $flagged = array_values(array_filter(
            $bottom,
            static fn (array $row): bool => $row['below_sma200'] === true
        ));
        $bottomStrong = array_values(array_filter(
            $bottom,
            static fn (array $row): bool => $row['below_sma200'] === false
        ));

        if (count($flagged) < $minimumFlagged) {
            continue;
        }

        $universeMean = mean(array_column($rows, 'alpha'));
        $bottomMean = mean(array_column($bottom, 'alpha'));
        $weakMean = $weak !== [] ? mean(array_column($weak, 'alpha')) : null;
        $flaggedMean = mean(array_column($flagged, 'alpha'));
        $bottomStrongMean = count($bottomStrong) >= $minimumFlagged
            ? mean(array_column($bottomStrong, 'alpha'))
            : null;
        $cohorts[] = [
            'month' => $month,
            'period' => $period,
            'universe_events' => count($rows),
            'bottom_events' => count($bottom),
            'weak_trend_events' => count($weak),
            'flagged_events' => count($flagged),
            'bottom_strong_events' => count($bottomStrong),
            'universe_minus_flagged_alpha_pct' => rounded($universeMean - $flaggedMean),
            'universe_minus_bottom_alpha_pct' => rounded($universeMean - $bottomMean),
            'universe_minus_weak_alpha_pct' => $weakMean === null ? null : rounded($universeMean - $weakMean),
            'bottom_strong_minus_flagged_alpha_pct' => $bottomStrongMean === null
                ? null
                : rounded($bottomStrongMean - $flaggedMean),
        ];

        foreach (['universe' => $rows, 'bottom' => $bottom, 'weak' => $weak, 'flagged' => $flagged] as $group => $groupRows) {
            foreach ($groupRows as $row) {
                $eventGroups[$period][$group][] = $row;
            }
        }
    }

    $lag = 3;
    $discovery = interactionPeriodSummary($cohorts, 'discovery', $lag);
    $holdout = interactionPeriodSummary($cohorts, 'holdout', $lag);
    $criteria = [
        'at_least_36_holdout_months' => $holdout['primary_universe_minus_flagged']['n'] >= 36,
        'holdout_effect_at_least_1pp' => ($holdout['primary_universe_minus_flagged']['mean'] ?? -INF) >= 1.0,
        'holdout_newey_west_t_at_least_2' => ($holdout['primary_universe_minus_flagged']['newey_west_t'] ?? -INF) >= 2.0,
        'at_least_60pct_positive_months' => ($holdout['primary_universe_minus_flagged']['positive_pct'] ?? -INF) >= 60.0,
        'incremental_to_bottom_only_is_positive' => ($holdout['incremental_weak_vs_strong_bottom']['mean'] ?? -INF) > 0.0,
    ];

    return [
        'status' => 'exploratory_follow_up_not_production',
        'signal' => 'Bottom 20% mensual de sorpresa de BPA y cierre preanuncio por debajo de SMA200.',
        'outcome' => 'Universo menos senal en alpha frente a SPY a 60 sesiones; positivo significa que evitar la senal ayuda.',
        'holdout_start' => $holdoutStart,
        'discovery_outcomes_purged_crossing_holdout' => $purgedDiscoveryOutcomes,
        'minimum_flagged_per_month' => $minimumFlagged,
        'newey_west_lag' => $lag,
        'discovery' => $discovery,
        'holdout' => $holdout,
        'holdout_event_level_diagnostics' => [
            'universe' => eventOutcomeSummary($eventGroups['holdout']['universe']),
            'bottom_surprise' => eventOutcomeSummary($eventGroups['holdout']['bottom']),
            'weak_trend' => eventOutcomeSummary($eventGroups['holdout']['weak']),
            'flagged_interaction' => eventOutcomeSummary($eventGroups['holdout']['flagged']),
        ],
        'success_criteria' => $criteria,
        'passes_all_success_criteria' => !in_array(false, $criteria, true),
        'cohorts' => $cohorts,
    ];
}

/**
 * @param list<array<string,mixed>> $cohorts
 * @return array<string,mixed>
 */
function interactionPeriodSummary(array $cohorts, string $period, int $lag): array
{
    $selected = array_values(array_filter(
        $cohorts,
        static fn (array $cohort): bool => $cohort['period'] === $period
    ));

    return [
        'months' => count($selected),
        'primary_universe_minus_flagged' => stats(cohortFloatValues($selected, 'universe_minus_flagged_alpha_pct'), $lag),
        'control_universe_minus_bottom' => stats(cohortFloatValues($selected, 'universe_minus_bottom_alpha_pct'), $lag),
        'control_universe_minus_weak_trend' => stats(cohortFloatValues($selected, 'universe_minus_weak_alpha_pct'), $lag),
        'incremental_weak_vs_strong_bottom' => stats(cohortFloatValues($selected, 'bottom_strong_minus_flagged_alpha_pct'), $lag),
    ];
}

/** @param list<array<string,mixed>> $rows @return list<float> */
function cohortFloatValues(array $rows, string $field): array
{
    $values = [];

    foreach ($rows as $row) {
        if (is_float($row[$field] ?? null) || is_int($row[$field] ?? null)) {
            $values[] = (float) $row[$field];
        }
    }

    return $values;
}

/**
 * @param list<array{ticker:string,surprise:float,alpha:float,stock_return:float,entry_date:string,exit_date:string,below_sma200:?bool,distance_to_sma200_pct:?float}> $rows
 * @return array{events:int,mean_alpha_pct:?float,mean_stock_return_pct:?float,negative_alpha_pct:?float,stock_loss_10pct_or_worse_pct:?float}
 */
function eventOutcomeSummary(array $rows): array
{
    if ($rows === []) {
        return [
            'events' => 0,
            'mean_alpha_pct' => null,
            'mean_stock_return_pct' => null,
            'negative_alpha_pct' => null,
            'stock_loss_10pct_or_worse_pct' => null,
        ];
    }

    $negativeAlpha = 0;
    $largeLoss = 0;

    foreach ($rows as $row) {
        $negativeAlpha += $row['alpha'] < 0.0 ? 1 : 0;
        $largeLoss += $row['stock_return'] <= -10.0 ? 1 : 0;
    }

    return [
        'events' => count($rows),
        'mean_alpha_pct' => rounded(mean(array_column($rows, 'alpha'))),
        'mean_stock_return_pct' => rounded(mean(array_column($rows, 'stock_return'))),
        'negative_alpha_pct' => rounded(($negativeAlpha / count($rows)) * 100.0),
        'stock_loss_10pct_or_worse_pct' => rounded(($largeLoss / count($rows)) * 100.0),
    ];
}

/**
 * @param list<float> $values
 * @return array{n:int,mean:?float,standard_deviation:?float,iid_t:?float,newey_west_se:?float,newey_west_t:?float,positive_pct:?float}
 */
function stats(array $values, int $lag): array
{
    $count = count($values);

    if ($count === 0) {
        return [
            'n' => 0,
            'mean' => null,
            'standard_deviation' => null,
            'iid_t' => null,
            'newey_west_se' => null,
            'newey_west_t' => null,
            'positive_pct' => null,
        ];
    }

    $mean = mean($values);
    $sumSquared = 0.0;
    $positive = 0;

    foreach ($values as $value) {
        $sumSquared += ($value - $mean) ** 2;
        $positive += $value > 0.0 ? 1 : 0;
    }

    $standardDeviation = $count > 1 ? sqrt($sumSquared / ($count - 1)) : null;
    $iidSe = $standardDeviation !== null ? $standardDeviation / sqrt($count) : null;
    $iidT = $iidSe !== null && $iidSe > 0.0 ? $mean / $iidSe : null;
    $residuals = array_map(static fn (float $value): float => $value - $mean, $values);
    $longRunVariance = $sumSquared / $count;
    $usableLag = min($lag, $count - 1);

    for ($offset = 1; $offset <= $usableLag; $offset++) {
        $covariance = 0.0;

        for ($index = $offset; $index < $count; $index++) {
            $covariance += $residuals[$index] * $residuals[$index - $offset];
        }

        $covariance /= $count;
        $bartlettWeight = 1.0 - ($offset / ($usableLag + 1));
        $longRunVariance += 2.0 * $bartlettWeight * $covariance;
    }

    $neweyWestSe = sqrt(max(0.0, $longRunVariance) / $count);
    $neweyWestT = $neweyWestSe > 0.0 ? $mean / $neweyWestSe : null;

    return [
        'n' => $count,
        'mean' => rounded($mean),
        'standard_deviation' => rounded($standardDeviation),
        'iid_t' => rounded($iidT),
        'newey_west_se' => rounded($neweyWestSe),
        'newey_west_t' => rounded($neweyWestT),
        'positive_pct' => rounded(($positive / $count) * 100.0),
    ];
}

/** @param list<float> $values @return array{early:?float,late:?float} */
function splitMeans(array $values): array
{
    if (count($values) < 2) {
        return ['early' => null, 'late' => null];
    }

    $middle = intdiv(count($values), 2);

    return [
        'early' => rounded(mean(array_slice($values, 0, $middle))),
        'late' => rounded(mean(array_slice($values, $middle))),
    ];
}

/** @param list<float> $values */
function mean(array $values): float
{
    return array_sum($values) / count($values);
}

/** @param list<float> $left @param list<float> $right */
function spearman(array $left, array $right): ?float
{
    if (count($left) !== count($right) || count($left) < 3) {
        return null;
    }

    return pearson(averageRanks($left), averageRanks($right));
}

/** @param list<float> $values @return list<float> */
function averageRanks(array $values): array
{
    $indexed = [];

    foreach ($values as $index => $value) {
        $indexed[] = ['index' => $index, 'value' => $value];
    }

    usort($indexed, static fn (array $left, array $right): int => $left['value'] <=> $right['value']);
    $ranks = array_fill(0, count($values), 0.0);
    $position = 0;

    while ($position < count($indexed)) {
        $end = $position;

        while ($end + 1 < count($indexed) && $indexed[$end + 1]['value'] === $indexed[$position]['value']) {
            $end++;
        }

        $averageRank = (($position + 1) + ($end + 1)) / 2.0;

        for ($cursor = $position; $cursor <= $end; $cursor++) {
            $ranks[$indexed[$cursor]['index']] = $averageRank;
        }

        $position = $end + 1;
    }

    return $ranks;
}

/** @param list<float> $left @param list<float> $right */
function pearson(array $left, array $right): ?float
{
    $leftMean = mean($left);
    $rightMean = mean($right);
    $numerator = 0.0;
    $leftSquared = 0.0;
    $rightSquared = 0.0;

    foreach ($left as $index => $leftValue) {
        $leftDelta = $leftValue - $leftMean;
        $rightDelta = $right[$index] - $rightMean;
        $numerator += $leftDelta * $rightDelta;
        $leftSquared += $leftDelta ** 2;
        $rightSquared += $rightDelta ** 2;
    }

    $denominator = sqrt($leftSquared * $rightSquared);

    return $denominator > 0.0 ? $numerator / $denominator : null;
}

function rounded(?float $value): ?float
{
    return $value === null ? null : round($value, 6);
}
