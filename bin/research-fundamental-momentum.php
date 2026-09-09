<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Services\FundamentalMomentumCalculator;
use StockAnalyzer\Services\FundamentalsQualityAuditor;

/**
 * Descubrimiento/validacion temporal de cambios fundamentales interanuales.
 *
 * La fase discovery nunca carga fechas desde 2022. La fase validation exige
 * que se indique una unica senal y un unico horizonte elegidos previamente:
 *
 *   ddev exec php bin/research-fundamental-momentum.php --phase=discovery
 *   ddev exec php bin/research-fundamental-momentum.php \
 *     --phase=validation --candidate=composite --horizon=60
 */

const FM_FACTORS = [
    'revenue_growth_acceleration',
    'operating_margin_change',
    'roic_change',
    'debt_to_equity_improvement',
    'fcf_delta_yield',
];
const FM_CANDIDATES = [
    'revenue_growth_acceleration',
    'operating_margin_change',
    'roic_change',
    'debt_to_equity_improvement',
    'fcf_delta_yield',
    'composite',
];
const FM_HOLDOUT_START = '2022-01-01';
const FM_DISCOVERY_END = '2021-12-31';
const FM_HORIZONS = [20, 60];
const FM_MIN_SECTOR_PEERS = 5;
const FM_MIN_CROSS_SECTION = 50;

$options = getopt('', ['phase::', 'candidate::', 'horizon::', 'output::']);
$phase = is_string($options['phase'] ?? null) ? (string) $options['phase'] : 'discovery';

if (!in_array($phase, ['discovery', 'validation'], true)) {
    throw new InvalidArgumentException("--phase debe ser 'discovery' o 'validation'.");
}

$selectedCandidate = is_string($options['candidate'] ?? null) ? (string) $options['candidate'] : null;
$selectedHorizon = is_string($options['horizon'] ?? null) ? (int) $options['horizon'] : null;

if ($phase === 'validation') {
    if (!in_array($selectedCandidate, FM_CANDIDATES, true)) {
        throw new InvalidArgumentException('--candidate es obligatorio en validation y debe ser una senal conocida.');
    }

    if (!in_array($selectedHorizon, FM_HORIZONS, true)) {
        throw new InvalidArgumentException('--horizon es obligatorio en validation y debe ser 20 o 60.');
    }
}

$root = dirname(__DIR__);
$outputDefault = $phase === 'discovery'
    ? 'storage/scratch/fundamental_momentum_discovery.json'
    : sprintf('storage/scratch/fundamental_momentum_validation_%s_%d.json', $selectedCandidate, $selectedHorizon);
$outputOption = is_string($options['output'] ?? null) ? (string) $options['output'] : $outputDefault;
$outputPath = str_starts_with($outputOption, '/') ? $outputOption : $root . '/' . $outputOption;
$universePath = $root . '/storage/scratch/point_in_time_universe.txt';
$universeTickers = fmLoadTickers($universePath);
$connection = new Connection();
$qualityFilter = fmQualityFilter($universeTickers, $connection);
$tickers = $qualityFilter['eligible_tickers'];
$pdo = $connection->getPdo();
$cache = new MarketDataCacheRepository($connection);
$ttl = new DateInterval('P100Y');
$spyHistory = $cache->findHistory('SPY', $ttl, '10y');

if ($spyHistory === null || $spyHistory === []) {
    throw new RuntimeException('Falta SPY 10y en cache; este estudio no descarga datos.');
}

fmSortHistory($spyHistory);
$spyByDate = fmIndexHistory($spyHistory);
$allSignals = fmMonthlySignals($spyHistory);
$signals = array_values(array_filter(
    $allSignals,
    static fn (string $date): bool => $phase === 'discovery'
        ? $date <= FM_DISCOVERY_END
        : $date >= FM_HOLDOUT_START
));
$previousDateBySignal = [];

foreach ($signals as $signalDate) {
    $target = (new DateTimeImmutable($signalDate))->modify('-1 year')->format('Y-m-d');
    $previous = fmLastDateOnOrBefore($spyHistory, $target);

    if ($previous !== null) {
        $previousDateBySignal[$signalDate] = $previous;
    }
}

$neededDates = array_values(array_unique(array_merge($signals, array_values($previousDateBySignal))));
$placeholders = implode(',', array_fill(0, count($neededDates), '?'));
$fundamentalStatement = $pdo->prepare(
    "SELECT snapshot_date, fundamentals_payload
       FROM fundamentals_history
      WHERE ticker = ? AND snapshot_date IN ($placeholders)"
);
$sectors = fmLoadSectors($pdo);
$memberships = fmLoadMemberships($pdo);
$factorCalculator = new FundamentalMomentumCalculator();
$audit = [
    'phase' => $phase,
    'universe_tickers' => count($universeTickers),
    'quality_filter' => $qualityFilter['audit'],
    'signal_months_considered' => count($signals),
    'price_histories_found' => 0,
    'price_histories_missing' => 0,
    'ticker_months_missing_fundamental_pair' => 0,
    'ticker_months_outside_membership' => 0,
    'ticker_months_with_observation' => 0,
    'factor_values' => array_fill_keys(FM_FACTORS, 0),
    'returns_available' => array_fill_keys(FM_HORIZONS, 0),
    'returns_purged_crossing_holdout' => array_fill_keys(FM_HORIZONS, 0),
    'latest_outcome_exit_date' => array_fill_keys(FM_HORIZONS, null),
];

/** @var array<string,list<array<string,mixed>>> $observationsByDate */
$observationsByDate = [];

foreach ($tickers as $tickerPosition => $ticker) {
    $history = $cache->findHistory($ticker, $ttl, '10y');

    if ($history === null || $history === []) {
        $audit['price_histories_missing']++;
        continue;
    }

    $audit['price_histories_found']++;
    fmSortHistory($history);
    $fundamentalStatement->execute(array_merge([$ticker], $neededDates));
    $fundamentalsByDate = [];

    while (($row = $fundamentalStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $decoded = json_decode((string) $row['fundamentals_payload'], true);

        if (is_array($decoded)) {
            $fundamentalsByDate[(string) $row['snapshot_date']] = $decoded;
        }
    }

    foreach ($signals as $signalDate) {
        $previousDate = $previousDateBySignal[$signalDate] ?? null;
        $current = $fundamentalsByDate[$signalDate] ?? null;
        $previous = $previousDate !== null ? ($fundamentalsByDate[$previousDate] ?? null) : null;

        if (!is_array($current) || !is_array($previous)) {
            $audit['ticker_months_missing_fundamental_pair']++;
            continue;
        }

        $entryProbe = fmForwardReturn($signalDate, $history, $spyByDate, 1);

        if ($entryProbe === null || !fmMembershipCovers($memberships[$ticker] ?? null, $entryProbe['entry_date'])) {
            $audit['ticker_months_outside_membership']++;
            continue;
        }

        $factors = $factorCalculator->calculate($current, $previous);

        foreach (FM_FACTORS as $factor) {
            $audit['factor_values'][$factor] += $factors[$factor] !== null ? 1 : 0;
        }

        $returns = [];

        foreach (FM_HORIZONS as $horizon) {
            $return = fmForwardReturn($signalDate, $history, $spyByDate, $horizon);

            if ($return === null) {
                continue;
            }

            if ($phase === 'discovery' && $return['exit_date'] >= FM_HOLDOUT_START) {
                $audit['returns_purged_crossing_holdout'][$horizon]++;
                continue;
            }

            $returns[$horizon] = $return;
            $audit['returns_available'][$horizon]++;
            $latestExit = $audit['latest_outcome_exit_date'][$horizon];

            if (!is_string($latestExit) || $return['exit_date'] > $latestExit) {
                $audit['latest_outcome_exit_date'][$horizon] = $return['exit_date'];
            }
        }

        if ($returns === []) {
            continue;
        }

        $observationsByDate[$signalDate][] = [
            'ticker' => $ticker,
            'sector' => $sectors[$ticker] ?? 'Other',
            'factors' => $factors,
            'returns' => $returns,
        ];
        $audit['ticker_months_with_observation']++;
    }

    if (($tickerPosition + 1) % 50 === 0) {
        fwrite(STDERR, sprintf("Procesados %d/%d tickers\n", $tickerPosition + 1, count($tickers)));
    }
}

$candidateScoresByDate = fmCandidateScores($observationsByDate);
$candidatesToAnalyze = $phase === 'discovery' ? FM_CANDIDATES : [$selectedCandidate];
$horizonsToAnalyze = $phase === 'discovery' ? FM_HORIZONS : [$selectedHorizon];
$results = [];

foreach ($candidatesToAnalyze as $candidate) {
    foreach ($horizonsToAnalyze as $horizon) {
        $results[$candidate][(string) $horizon] = fmAnalyzeCandidate(
            $observationsByDate,
            $candidateScoresByDate,
            $candidate,
            $horizon
        );
    }
}

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'study' => 'sector_neutral_fundamental_momentum',
    'phase' => $phase,
    'date_boundary' => $phase === 'discovery'
        ? [
            'signals_considered_through' => FM_DISCOVERY_END,
            'outcomes_strictly_before' => FM_HOLDOUT_START,
            'holdout_observations_not_loaded' => true,
        ]
        : ['from' => FM_HOLDOUT_START, 'candidate' => $selectedCandidate, 'horizon' => $selectedHorizon],
    'status' => 'research_only_not_used_by_production_score',
    'rules' => [
        'signal_date' => 'Ultima sesion de cada mes; entrada en la apertura de la siguiente sesion.',
        'comparison_period' => 'Snapshot de la fecha de senal frente a la ultima sesion SPY anterior o igual a un ano antes.',
        'ranking' => 'Percentil dentro del sector, minimo 5 peers; mayor siempre es mejor.',
        'portfolio' => 'Quintil superior menos quintil inferior, equiponderado, alpha frente a SPY.',
        'composite' => 'Media de percentiles sectoriales disponibles; exige al menos 3 de 5 factores.',
        'quality_filter' => 'Misma lista dinamica del backtest fundamental: excluye el ticker completo si la auditoria raw o parseada detecta filing_before_period_end.',
        'temporal_purge' => 'En discovery se descarta individualmente cualquier outcome cuya fecha de salida sea 2022-01-01 o posterior.',
        'minimum_cross_section' => FM_MIN_CROSS_SECTION,
        'newey_west_lag' => 'ceil(horizon/21), minimo 1.',
    ],
    'known_limitations' => [
        'La clasificacion sectorial procede del payload EODHD actual, no de un historial sectorial point-in-time.',
        'El historico fundamental reconstruido puede heredar revisiones posteriores del proveedor.',
        'El universo historico capturado no garantiza todas las bajas del indice.',
        'Retornos de precio sin costes, dividendos, impuestos ni deslizamiento.',
    ],
    'audit' => $audit,
    'results' => $results,
];
$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

if (file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
    throw new RuntimeException('No se pudo escribir ' . $outputPath);
}

$summary = [];

foreach ($results as $candidate => $byHorizon) {
    foreach ($byHorizon as $horizon => $metrics) {
        $summary[$candidate][$horizon] = [
            'months' => $metrics['primary']['n'],
            'mean_top_minus_bottom_alpha_pct' => $metrics['primary']['mean'],
            'newey_west_t' => $metrics['primary']['newey_west_t'],
            'positive_months_pct' => $metrics['primary']['positive_pct'],
            'early_half_mean' => $metrics['early_half']['mean'],
            'late_half_mean' => $metrics['late_half']['mean'],
            'rank_ic_mean' => $metrics['rank_ic']['mean'],
        ];
    }
}

echo json_encode([
    'output' => str_replace($root . '/', '', $outputPath),
    'phase' => $phase,
    'audit' => $audit,
    'summary' => $summary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

/** @return list<string> */
function fmLoadTickers(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $set = [];

    foreach ($lines === false ? [] : $lines as $line) {
        foreach (preg_split('/[\s,;]+/', trim($line)) ?: [] as $ticker) {
            if ($ticker !== '') {
                $set[strtoupper($ticker)] = true;
            }
        }
    }

    return array_keys($set);
}

/**
 * Reproduce exactamente la puerta P3.1 del backtest fundamental existente:
 * audita en cada ejecucion el payload legacy crudo y los periodos parseados,
 * y excluye el ticker completo si cualquiera de las dos vistas contiene un
 * filing_before_period_end.
 *
 * @param list<string> $universeTickers
 * @return array{
 *     eligible_tickers:list<string>,
 *     audit:array{
 *         rule:string,
 *         universe_before_filter:int,
 *         excluded_filing_before_period_end:int,
 *         excluded_tickers:list<string>,
 *         universe_after_filter:int
 *     }
 * }
 */
function fmQualityFilter(array $universeTickers, Connection $connection): array
{
    $rawFundamentals = new EodhdRawFundamentalsRepository($connection);
    $fiscalProvider = new EodhdFiscalPeriodProvider('');
    $auditor = new FundamentalsQualityAuditor();
    $excludedTickers = [];

    foreach ($universeTickers as $ticker) {
        $rawJson = $rawFundamentals->find($ticker);

        if ($rawJson === null) {
            continue;
        }

        try {
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            continue;
        }

        if (!is_array($decoded)) {
            continue;
        }

        $issues = $auditor->auditRawPayload($decoded, $ticker);

        try {
            $periods = $fiscalProvider->parse($decoded, $ticker);
        } catch (\Throwable) {
            $periods = [];
        }

        if ($periods !== []) {
            $issues = [...$issues, ...$auditor->auditParsedPeriods($ticker, $periods)];
        }

        foreach ($issues as $issue) {
            if ($issue->type === 'filing_before_period_end') {
                $excludedTickers[] = strtoupper($ticker);
                break;
            }
        }
    }

    $excludedTickers = array_values(array_unique($excludedTickers));
    sort($excludedTickers);
    $eligibleTickers = array_values(array_diff($universeTickers, $excludedTickers));

    return [
        'eligible_tickers' => $eligibleTickers,
        'audit' => [
            'rule' => 'filing_before_period_end',
            'universe_before_filter' => count($universeTickers),
            'excluded_filing_before_period_end' => count($excludedTickers),
            'excluded_tickers' => $excludedTickers,
            'universe_after_filter' => count($eligibleTickers),
        ],
    ];
}

/** @param list<HistoricalQuote> $history */
function fmSortHistory(array &$history): void
{
    usort($history, static fn (HistoricalQuote $a, HistoricalQuote $b): int => $a->getDate() <=> $b->getDate());
}

/** @param list<HistoricalQuote> $history @return array<string,HistoricalQuote> */
function fmIndexHistory(array $history): array
{
    $indexed = [];

    foreach ($history as $quote) {
        $indexed[$quote->getDate()->format('Y-m-d')] = $quote;
    }

    return $indexed;
}

/** @param list<HistoricalQuote> $history @return list<string> */
function fmMonthlySignals(array $history): array
{
    $lastByMonth = [];

    foreach ($history as $quote) {
        $lastByMonth[$quote->getDate()->format('Y-m')] = $quote->getDate()->format('Y-m-d');
    }

    return array_values($lastByMonth);
}

/** @param list<HistoricalQuote> $history */
function fmLastDateOnOrBefore(array $history, string $target): ?string
{
    $low = 0;
    $high = count($history);

    while ($low < $high) {
        $middle = intdiv($low + $high, 2);

        if ($history[$middle]->getDate()->format('Y-m-d') <= $target) {
            $low = $middle + 1;
        } else {
            $high = $middle;
        }
    }

    return isset($history[$low - 1]) ? $history[$low - 1]->getDate()->format('Y-m-d') : null;
}

/** @return array<string,string> */
function fmLoadSectors(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT ticker, JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.General.Sector')) sector FROM eodhd_raw_fundamentals"
    )->fetchAll(PDO::FETCH_ASSOC);
    $sectors = [];

    foreach ($rows as $row) {
        $sector = is_string($row['sector'] ?? null) && !in_array($row['sector'], ['', 'null'], true)
            ? $row['sector']
            : 'Other';
        $sectors[(string) $row['ticker']] = $sector;
    }

    return $sectors;
}

/** @return array<string,array{start_date:?string,end_date:?string}> */
function fmLoadMemberships(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT ticker, start_date, end_date FROM index_membership WHERE index_code = 'GSPC'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $memberships = [];

    foreach ($rows as $row) {
        $memberships[(string) $row['ticker']] = [
            'start_date' => is_string($row['start_date'] ?? null) ? $row['start_date'] : null,
            'end_date' => is_string($row['end_date'] ?? null) ? $row['end_date'] : null,
        ];
    }

    return $memberships;
}

/** @param array{start_date:?string,end_date:?string}|null $membership */
function fmMembershipCovers(?array $membership, string $date): bool
{
    return $membership !== null
        && ($membership['start_date'] === null || $membership['start_date'] <= $date)
        && ($membership['end_date'] === null || $membership['end_date'] >= $date);
}

/**
 * @param list<HistoricalQuote> $history
 * @param array<string,HistoricalQuote> $benchmark
 * @return array{entry_date:string,exit_date:string,alpha:float,stock_return:float}|null
 */
function fmForwardReturn(string $signalDate, array $history, array $benchmark, int $horizon): ?array
{
    $low = 0;
    $high = count($history);

    while ($low < $high) {
        $middle = intdiv($low + $high, 2);

        if ($history[$middle]->getDate()->format('Y-m-d') <= $signalDate) {
            $low = $middle + 1;
        } else {
            $high = $middle;
        }
    }

    $entry = $history[$low] ?? null;
    $exit = $history[$low + $horizon] ?? null;

    if (!$entry instanceof HistoricalQuote || !$exit instanceof HistoricalQuote) {
        return null;
    }

    if ((new DateTimeImmutable($signalDate))->diff($entry->getDate())->days > 7) {
        return null;
    }

    $entryDate = $entry->getDate()->format('Y-m-d');
    $exitDate = $exit->getDate()->format('Y-m-d');
    $benchmarkEntry = $benchmark[$entryDate] ?? null;
    $benchmarkExit = $benchmark[$exitDate] ?? null;

    if (!$benchmarkEntry instanceof HistoricalQuote || !$benchmarkExit instanceof HistoricalQuote) {
        return null;
    }

    if ($entry->getOpen() <= 0.0 || $exit->getClose() <= 0.0 || $benchmarkEntry->getOpen() <= 0.0 || $benchmarkExit->getClose() <= 0.0) {
        return null;
    }

    $stockReturn = (($exit->getClose() / $entry->getOpen()) - 1.0) * 100.0;
    $benchmarkReturn = (($benchmarkExit->getClose() / $benchmarkEntry->getOpen()) - 1.0) * 100.0;

    return [
        'entry_date' => $entryDate,
        'exit_date' => $exitDate,
        'alpha' => $stockReturn - $benchmarkReturn,
        'stock_return' => $stockReturn,
    ];
}

/**
 * @param array<string,list<array<string,mixed>>> $byDate
 * @return array<string,array<string,array<string,float>>>
 */
function fmCandidateScores(array $byDate): array
{
    $result = [];

    foreach ($byDate as $date => $rows) {
        $factorRanks = [];

        foreach (FM_FACTORS as $factor) {
            $bySector = [];

            foreach ($rows as $row) {
                $value = $row['factors'][$factor] ?? null;

                if (is_float($value) || is_int($value)) {
                    $bySector[(string) $row['sector']][] = ['ticker' => (string) $row['ticker'], 'value' => (float) $value];
                }
            }

            foreach ($bySector as $sectorRows) {
                if (count($sectorRows) < FM_MIN_SECTOR_PEERS) {
                    continue;
                }

                usort($sectorRows, static function (array $a, array $b): int {
                    $byValue = $a['value'] <=> $b['value'];

                    return $byValue !== 0 ? $byValue : $a['ticker'] <=> $b['ticker'];
                });

                foreach ($sectorRows as $index => $sectorRow) {
                    $factorRanks[$factor][$sectorRow['ticker']] = (($index + 1) / count($sectorRows)) * 100.0;
                }
            }
        }

        foreach ($rows as $row) {
            $ticker = (string) $row['ticker'];
            $available = [];

            foreach (FM_FACTORS as $factor) {
                if (isset($factorRanks[$factor][$ticker])) {
                    $result[$date][$factor][$ticker] = $factorRanks[$factor][$ticker];
                    $available[] = $factorRanks[$factor][$ticker];
                }
            }

            if (count($available) >= 3) {
                $result[$date]['composite'][$ticker] = array_sum($available) / count($available);
            }
        }
    }

    return $result;
}

/**
 * @param array<string,list<array<string,mixed>>> $byDate
 * @param array<string,array<string,array<string,float>>> $scores
 * @return array<string,mixed>
 */
function fmAnalyzeCandidate(array $byDate, array $scores, string $candidate, int $horizon): array
{
    $cohorts = [];

    foreach ($byDate as $date => $rows) {
        $eligible = [];

        foreach ($rows as $row) {
            $ticker = (string) $row['ticker'];
            $score = $scores[$date][$candidate][$ticker] ?? null;
            $return = $row['returns'][$horizon] ?? null;

            if (is_float($score) && is_array($return)) {
                $eligible[] = ['ticker' => $ticker, 'score' => $score, 'alpha' => (float) $return['alpha']];
            }
        }

        if (count($eligible) < FM_MIN_CROSS_SECTION) {
            continue;
        }

        usort($eligible, static function (array $a, array $b): int {
            $byScore = $a['score'] <=> $b['score'];

            return $byScore !== 0 ? $byScore : $a['ticker'] <=> $b['ticker'];
        });
        $size = max(1, (int) floor(count($eligible) * 0.20));
        $bottom = array_slice($eligible, 0, $size);
        $top = array_slice($eligible, -$size);
        $allMean = fmMean(array_column($eligible, 'alpha'));
        $bottomMean = fmMean(array_column($bottom, 'alpha'));
        $topMean = fmMean(array_column($top, 'alpha'));
        $cohorts[] = [
            'date' => $date,
            'events' => count($eligible),
            'top_minus_bottom_alpha_pct' => round($topMean - $bottomMean, 6),
            'top_minus_all_alpha_pct' => round($topMean - $allMean, 6),
            'all_minus_bottom_alpha_pct' => round($allMean - $bottomMean, 6),
            'rank_ic' => round(fmPearson(fmRanks(array_column($eligible, 'score')), fmRanks(array_column($eligible, 'alpha'))) ?? 0.0, 6),
        ];
    }

    $lag = max(1, (int) ceil($horizon / 21));
    $primaryValues = array_column($cohorts, 'top_minus_bottom_alpha_pct');
    $middle = intdiv(count($primaryValues), 2);

    return [
        'candidate' => $candidate,
        'horizon' => $horizon,
        'primary' => fmStats($primaryValues, $lag),
        'top_vs_all' => fmStats(array_column($cohorts, 'top_minus_all_alpha_pct'), $lag),
        'all_vs_bottom' => fmStats(array_column($cohorts, 'all_minus_bottom_alpha_pct'), $lag),
        'rank_ic' => fmStats(array_column($cohorts, 'rank_ic'), $lag),
        'early_half' => fmStats(array_slice($primaryValues, 0, $middle), $lag),
        'late_half' => fmStats(array_slice($primaryValues, $middle), $lag),
        'cohorts' => $cohorts,
    ];
}

/** @param list<float> $values @return array{n:int,mean:?float,newey_west_t:?float,positive_pct:?float} */
function fmStats(array $values, int $lag): array
{
    $n = count($values);

    if ($n === 0) {
        return ['n' => 0, 'mean' => null, 'newey_west_t' => null, 'positive_pct' => null];
    }

    $mean = fmMean($values);
    $residuals = array_map(static fn (float $value): float => $value - $mean, $values);
    $sumSquared = array_sum(array_map(static fn (float $value): float => $value ** 2, $residuals));
    $longRunVariance = $sumSquared / $n;
    $usableLag = min($lag, $n - 1);

    for ($offset = 1; $offset <= $usableLag; $offset++) {
        $covariance = 0.0;

        for ($index = $offset; $index < $n; $index++) {
            $covariance += $residuals[$index] * $residuals[$index - $offset];
        }

        $longRunVariance += 2.0 * (1.0 - $offset / ($usableLag + 1)) * ($covariance / $n);
    }

    $se = sqrt(max(0.0, $longRunVariance) / $n);
    $positive = count(array_filter($values, static fn (float $value): bool => $value > 0.0));

    return [
        'n' => $n,
        'mean' => round($mean, 6),
        'newey_west_t' => $se > 0.0 ? round($mean / $se, 6) : null,
        'positive_pct' => round(($positive / $n) * 100.0, 6),
    ];
}

/** @param list<float> $values */
function fmMean(array $values): float
{
    return array_sum($values) / count($values);
}

/** @param list<float> $values @return list<float> */
function fmRanks(array $values): array
{
    $indexed = [];

    foreach ($values as $index => $value) {
        $indexed[] = ['index' => $index, 'value' => $value];
    }

    usort($indexed, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);
    $ranks = array_fill(0, count($values), 0.0);

    foreach ($indexed as $rank => $row) {
        $ranks[$row['index']] = (float) ($rank + 1);
    }

    return $ranks;
}

/** @param list<float> $left @param list<float> $right */
function fmPearson(array $left, array $right): ?float
{
    if (count($left) < 3 || count($left) !== count($right)) {
        return null;
    }

    $leftMean = fmMean($left);
    $rightMean = fmMean($right);
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
