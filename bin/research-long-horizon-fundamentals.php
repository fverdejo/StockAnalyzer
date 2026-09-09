<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Services\FundamentalsQualityAuditor;
use StockAnalyzer\Services\HistoricalTrendSnapshotCalculator;
use StockAnalyzer\Services\RelativeFundamentalScorer;

/**
 * Repite el score fundamental relativo actual a horizontes coherentes con
 * una tesis de inversion (126/252 sesiones), sin cambiar factores ni pesos.
 * Discovery termina en 2020 para que sus retornos a 252 sesiones no se
 * solapen con el holdout que empieza en 2022.
 */

const LH_HORIZONS = [60, 126, 252];
const LH_DISCOVERY_END = '2020-12-31';
const LH_HOLDOUT_START = '2022-01-01';
const LH_TOP_N = 10;
const LH_MIN_CROSS_SECTION = 100;
const LH_CANDIDATE_FIELDS = [
    'fcf_yield' => 'factor_free_cash_flow_yield_score',
    'ev_to_ebitda' => 'factor_ev_to_ebitda_score',
    'roic' => 'factor_roic_score',
    'operating_margin' => 'factor_operating_margin_score',
    'debt_to_equity' => 'factor_debt_to_equity_score',
    'fundamental' => 'fundamental_score',
    'momentum' => 'momentum_score',
    'ensemble' => 'ensemble_score',
];
const LH_FAMILIES = [
    'value' => [
        ['field' => 'free_cash_flow_yield', 'higher' => true],
        ['field' => 'ev_to_ebitda', 'higher' => false],
    ],
    'quality' => [
        ['field' => 'roic', 'higher' => true],
        ['field' => 'operating_margin', 'higher' => true],
    ],
    'soundness' => [
        ['field' => 'debt_to_equity', 'higher' => false],
    ],
];

$options = getopt('', ['phase::', 'candidate::', 'horizon::', 'output::']);
$phase = is_string($options['phase'] ?? null) ? (string) $options['phase'] : 'discovery';
$selectedCandidate = is_string($options['candidate'] ?? null) ? (string) $options['candidate'] : null;
$selectedHorizon = is_string($options['horizon'] ?? null) ? (int) $options['horizon'] : null;

if (!in_array($phase, ['discovery', 'validation'], true)) {
    throw new InvalidArgumentException("--phase debe ser 'discovery' o 'validation'.");
}

if ($phase === 'validation' && (!array_key_exists((string) $selectedCandidate, LH_CANDIDATE_FIELDS) || !in_array($selectedHorizon, LH_HORIZONS, true))) {
    throw new InvalidArgumentException('Validation exige un --candidate descubierto y --horizon=60|126|252.');
}

$root = dirname(__DIR__);
$defaultOutput = $phase === 'discovery'
    ? 'storage/scratch/long_horizon_fundamentals_discovery.json'
    : sprintf('storage/scratch/long_horizon_fundamentals_validation_%s_%d.json', $selectedCandidate, $selectedHorizon);
$outputOption = is_string($options['output'] ?? null) ? (string) $options['output'] : $defaultOutput;
$outputPath = str_starts_with($outputOption, '/') ? $outputOption : $root . '/' . $outputOption;
$universeTickers = lhTickers($root . '/storage/scratch/point_in_time_universe.txt');
$connection = new Connection();
$qualityFilter = lhQualityFilter($universeTickers, $connection);
$tickers = $qualityFilter['eligible_tickers'];
$pdo = $connection->getPdo();
$cache = new MarketDataCacheRepository($connection);
$ttl = new DateInterval('P100Y');
$spy = $cache->findHistory('SPY', $ttl, '10y');

if ($spy === null || $spy === []) {
    throw new RuntimeException('Falta SPY 10y en cache.');
}

lhSort($spy);
$spyByDate = lhIndex($spy);
$signals = array_values(array_filter(
    lhMonthlyDates($spy),
    static fn (string $date): bool => $phase === 'discovery'
        ? $date <= LH_DISCOVERY_END
        : $date >= LH_HOLDOUT_START
));
$placeholders = implode(',', array_fill(0, count($signals), '?'));
$fundamentalStatement = $pdo->prepare(
    "SELECT snapshot_date, fundamentals_payload FROM fundamentals_history
      WHERE ticker = ? AND snapshot_date IN ($placeholders)"
);
$sectors = lhSectors($pdo);
$memberships = lhMemberships($pdo);
$trendCalculator = new HistoricalTrendSnapshotCalculator();
$horizons = $phase === 'discovery' ? LH_HORIZONS : [$selectedHorizon];
$audit = [
    'phase' => $phase,
    'universe_tickers' => count($universeTickers),
    'quality_filter' => $qualityFilter['audit'],
    'signal_months_considered' => count($signals),
    'price_histories_found' => 0,
    'fundamental_snapshots_found' => 0,
    'momentum_snapshots_found' => 0,
    'outside_membership_or_no_entry' => 0,
    'observations' => 0,
    'returns_available' => array_fill_keys($horizons, 0),
    'returns_purged_crossing_holdout' => array_fill_keys($horizons, 0),
    'latest_outcome_exit_date' => array_fill_keys($horizons, null),
];

/** @var array<string,list<array<string,mixed>>> $byDate */
$byDate = [];

foreach ($tickers as $position => $ticker) {
    $history = $cache->findHistory($ticker, $ttl, '10y');

    if ($history === null || $history === []) {
        continue;
    }

    $audit['price_histories_found']++;
    lhSort($history);
    $fundamentalStatement->execute(array_merge([$ticker], $signals));
    $payloadByDate = [];

    while (($row = $fundamentalStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $payload = json_decode((string) $row['fundamentals_payload'], true);

        if (is_array($payload)) {
            $payloadByDate[(string) $row['snapshot_date']] = $payload;
            $audit['fundamental_snapshots_found']++;
        }
    }

    foreach ($signals as $signalDate) {
        $payload = $payloadByDate[$signalDate] ?? null;

        if (!is_array($payload)) {
            continue;
        }

        $probe = lhReturn($signalDate, $history, $spyByDate, 1);

        if ($probe === null || !lhCovers($memberships[$ticker] ?? null, $probe['entry_date'])) {
            $audit['outside_membership_or_no_entry']++;
            continue;
        }

        $returns = [];
        $trendSnapshot = $trendCalculator->calculate(new DateTimeImmutable($signalDate), $history);
        $audit['momentum_snapshots_found'] += $trendSnapshot !== null ? 1 : 0;

        foreach ($horizons as $horizon) {
            $return = lhReturn($signalDate, $history, $spyByDate, $horizon);

            if ($return === null) {
                continue;
            }

            if ($phase === 'discovery' && $return['exit_date'] >= LH_HOLDOUT_START) {
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

        $byDate[$signalDate][] = [
            'ticker' => $ticker,
            'sector' => $sectors[$ticker] ?? 'Other',
            'factors' => lhFactorValues($payload),
            'momentum_12_1' => $trendSnapshot['momentum_12_1_pct'] ?? null,
            'returns' => $returns,
        ];
        $audit['observations']++;
    }

    if (($position + 1) % 50 === 0) {
        fwrite(STDERR, sprintf("Procesados %d/%d tickers\n", $position + 1, count($tickers)));
    }
}

$scoredByDate = lhScores($byDate, new RelativeFundamentalScorer());
$results = [];
$candidates = $phase === 'discovery' ? array_keys(LH_CANDIDATE_FIELDS) : [$selectedCandidate];

foreach ($candidates as $candidate) {
    foreach ($horizons as $horizon) {
        $results[$candidate][(string) $horizon] = lhAnalyze($scoredByDate, $horizon, $candidate);
    }
}

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'study' => 'existing_relative_fundamentals_at_long_horizons',
    'phase' => $phase,
    'status' => 'research_only_not_used_by_production_score',
    'date_boundary' => $phase === 'discovery'
        ? [
            'signals_through' => LH_DISCOVERY_END,
            'outcomes_strictly_before' => LH_HOLDOUT_START,
            'holdout_observations_not_loaded' => true,
        ]
        : ['signals_from' => LH_HOLDOUT_START, 'locked_candidate' => $selectedCandidate, 'locked_horizon' => $selectedHorizon],
    'rules' => [
        'score' => 'Formula actual: cinco factores, percentil sectorial, media por Valor/Calidad/Solidez y media equiponderada de familias.',
        'ensemble' => 'Media 50/50 del score fundamental y del percentil sectorial de momentum 12-1; exige ambos.',
        'selection' => 'Top 10 mensual frente a la media de todos los elegibles.',
        'entry' => 'Apertura de la sesion posterior al ultimo cierre mensual.',
        'benchmark' => 'SPY entre las mismas fechas open-to-close.',
        'quality_filter' => 'Misma lista dinamica del backtest fundamental: excluye el ticker completo si la auditoria raw o parseada detecta filing_before_period_end.',
        'temporal_purge' => 'En discovery se descarta individualmente cualquier outcome cuya fecha de salida sea 2022-01-01 o posterior.',
        'newey_west_lag' => 'ceil(horizon/21) sobre cohortes mensuales.',
    ],
    'audit' => $audit,
    'results' => $results,
    'limitations' => [
        'Sector actual, no point-in-time.',
        'Posibles revisiones historicas del payload EODHD.',
        'Universo historico incompleto y retornos sin costes ni dividendos.',
    ],
];
$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

if (file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
    throw new RuntimeException('No se pudo escribir el resultado.');
}

$summary = [];

foreach ($results as $candidate => $byHorizon) {
    foreach ($byHorizon as $horizon => $metrics) {
        $summary[$candidate][$horizon] = [
            'months' => $metrics['primary_top10_vs_universe']['n'],
            'alpha_pct' => $metrics['primary_top10_vs_universe']['mean'],
            'newey_west_t' => $metrics['primary_top10_vs_universe']['newey_west_t'],
            'positive_months_pct' => $metrics['primary_top10_vs_universe']['positive_pct'],
            'early_half_mean' => $metrics['early_half']['mean'],
            'late_half_mean' => $metrics['late_half']['mean'],
            'top10_vs_bottom10' => $metrics['top10_vs_bottom10']['mean'],
            'rank_ic' => $metrics['rank_ic']['mean'],
            'max_adverse_excursion_reduction_pct' => $metrics['risk']['max_adverse_excursion_reduction']['mean'],
            'max_drawdown_reduction_pct' => $metrics['risk']['max_drawdown_reduction']['mean'],
            'severe_final_loss_reduction_pp' => $metrics['risk']['severe_final_loss_reduction']['mean'],
            'risk_early_half_mean' => $metrics['risk']['max_adverse_excursion_halves']['first']['mean'],
            'risk_late_half_mean' => $metrics['risk']['max_adverse_excursion_halves']['second']['mean'],
        ];
    }
}

echo json_encode(['output' => str_replace($root . '/', '', $outputPath), 'audit' => $audit, 'summary' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

/** @return list<string> */
function lhTickers(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $set = [];

    foreach ($lines === false ? [] : $lines as $line) {
        $ticker = strtoupper(trim($line));
        if ($ticker !== '') {
            $set[$ticker] = true;
        }
    }

    return array_keys($set);
}

/**
 * Misma puerta P3.1 que usa el backtest fundamental oficial. La lista no se
 * copia de un artefacto anterior: se recalcula contra los JSON legacy crudos
 * y contra los periodos parseados en cada ejecucion.
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
function lhQualityFilter(array $universeTickers, Connection $connection): array
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
function lhSort(array &$history): void
{
    usort($history, static fn (HistoricalQuote $a, HistoricalQuote $b): int => $a->getDate() <=> $b->getDate());
}

/** @param list<HistoricalQuote> $history @return array<string,HistoricalQuote> */
function lhIndex(array $history): array
{
    $result = [];
    foreach ($history as $quote) {
        $result[$quote->getDate()->format('Y-m-d')] = $quote;
    }
    return $result;
}

/** @param list<HistoricalQuote> $history @return list<string> */
function lhMonthlyDates(array $history): array
{
    $dates = [];
    foreach ($history as $quote) {
        $dates[$quote->getDate()->format('Y-m')] = $quote->getDate()->format('Y-m-d');
    }
    return array_values($dates);
}

/** @return array<string,string> */
function lhSectors(PDO $pdo): array
{
    $rows = $pdo->query("SELECT ticker, JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.General.Sector')) sector FROM eodhd_raw_fundamentals")->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $sector = is_string($row['sector'] ?? null) && !in_array($row['sector'], ['', 'null'], true) ? $row['sector'] : 'Other';
        $result[(string) $row['ticker']] = $sector;
    }
    return $result;
}

/** @return array<string,array{start_date:?string,end_date:?string}> */
function lhMemberships(PDO $pdo): array
{
    $rows = $pdo->query("SELECT ticker, start_date, end_date FROM index_membership WHERE index_code='GSPC'")->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $result[(string) $row['ticker']] = [
            'start_date' => is_string($row['start_date'] ?? null) ? $row['start_date'] : null,
            'end_date' => is_string($row['end_date'] ?? null) ? $row['end_date'] : null,
        ];
    }
    return $result;
}

/** @param array{start_date:?string,end_date:?string}|null $membership */
function lhCovers(?array $membership, string $date): bool
{
    return $membership !== null
        && ($membership['start_date'] === null || $membership['start_date'] <= $date)
        && ($membership['end_date'] === null || $membership['end_date'] >= $date);
}

/** @param array<string,mixed> $payload @return array<string,?float> */
function lhFactorValues(array $payload): array
{
    $number = static function (string $key) use ($payload): ?float {
        $value = $payload[$key] ?? null;
        return is_int($value) || is_float($value) ? (float) $value : null;
    };
    $fcf = $number('freeCashFlow');
    $marketCap = $number('marketCap');

    return [
        'free_cash_flow_yield' => $fcf !== null && $marketCap !== null && $marketCap > 0.0 ? ($fcf / $marketCap) * 100.0 : null,
        'ev_to_ebitda' => $number('evToEbitda'),
        'roic' => $number('roic'),
        'operating_margin' => $number('operatingMargin'),
        'debt_to_equity' => $number('debtToEquity'),
    ];
}

/**
 * @param list<HistoricalQuote> $history
 * @param array<string,HistoricalQuote> $benchmark
 * @return array{entry_date:string,exit_date:string,alpha:float,stock_return:float,max_adverse_excursion:float,max_drawdown:float,severe_final_loss:float}|null
 */
function lhReturn(string $signalDate, array $history, array $benchmark, int $horizon): ?array
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
    $minimumClose = $entry->getOpen();
    $peak = $entry->getOpen();
    $maxDrawdown = 0.0;
    foreach (array_slice($history, $low, $horizon + 1) as $pathQuote) {
        $close = $pathQuote->getClose();
        if ($close <= 0.0) {
            continue;
        }
        $minimumClose = min($minimumClose, $close);
        $peak = max($peak, $close);
        $maxDrawdown = max($maxDrawdown, (($peak - $close) / $peak) * 100.0);
    }
    $maxAdverseExcursion = max(0.0, (($entry->getOpen() - $minimumClose) / $entry->getOpen()) * 100.0);

    return [
        'entry_date' => $entryDate,
        'exit_date' => $exitDate,
        'alpha' => $stockReturn - $benchmarkReturn,
        'stock_return' => $stockReturn,
        'max_adverse_excursion' => $maxAdverseExcursion,
        'max_drawdown' => $maxDrawdown,
        'severe_final_loss' => $stockReturn <= -20.0 ? 1.0 : 0.0,
    ];
}

/**
 * @param array<string,list<array<string,mixed>>> $byDate
 * @return array<string,list<array<string,mixed>>>
 */
function lhScores(array $byDate, RelativeFundamentalScorer $scorer): array
{
    $result = [];
    foreach ($byDate as $date => $rows) {
        $bySector = [];
        foreach ($rows as $row) {
            $bySector[(string) $row['sector']][] = $row;
        }
        foreach ($rows as $row) {
            $families = [];
            $factorScores = [];
            foreach (LH_FAMILIES as $family => $factors) {
                $points = [];
                foreach ($factors as $factor) {
                    $field = $factor['field'];
                    $value = $row['factors'][$field] ?? null;
                    if (!is_float($value) && !is_int($value)) {
                        continue;
                    }
                    $peers = [];
                    foreach ($bySector[(string) $row['sector']] as $peer) {
                        $peerValue = $peer['factors'][$field] ?? null;
                        if ($peer['ticker'] !== $row['ticker'] && (is_float($peerValue) || is_int($peerValue))) {
                            $peers[] = (float) $peerValue;
                        }
                    }
                    $percentile = $scorer->percentileRank((float) $value, $peers, (bool) $factor['higher']);
                    if ($percentile !== null) {
                        $factorScore = $scorer->pointsFor($percentile, 100.0);
                        $points[] = $factorScore;
                        $factorScores[$field] = $factorScore;
                    }
                }
                if ($points !== []) {
                    $families[$family] = array_sum($points) / count($points);
                }
            }
            if ($families !== []) {
                $row['fundamental_score'] = array_sum($families) / count($families);
                foreach ($factorScores as $field => $factorScore) {
                    $row['factor_' . $field . '_score'] = $factorScore;
                }
                $result[$date][] = $row;
            }
        }

        $scoredBySector = [];
        foreach ($result[$date] ?? [] as $index => $row) {
            $scoredBySector[(string) $row['sector']][] = $index;
        }

        foreach ($result[$date] ?? [] as $index => $row) {
            $momentum = $row['momentum_12_1'] ?? null;
            if (!is_float($momentum) && !is_int($momentum)) {
                continue;
            }
            $peers = [];
            foreach ($scoredBySector[(string) $row['sector']] as $peerIndex) {
                $peer = $result[$date][$peerIndex];
                $peerMomentum = $peer['momentum_12_1'] ?? null;
                if ($peer['ticker'] !== $row['ticker'] && (is_float($peerMomentum) || is_int($peerMomentum))) {
                    $peers[] = (float) $peerMomentum;
                }
            }
            $momentumScore = $scorer->percentileRank((float) $momentum, $peers, true);
            if ($momentumScore !== null) {
                $result[$date][$index]['momentum_score'] = $momentumScore;
                $result[$date][$index]['ensemble_score'] = ((float) $row['fundamental_score'] + $momentumScore) / 2.0;
            }
        }
    }
    return $result;
}

/** @param array<string,list<array<string,mixed>>> $byDate @return array<string,mixed> */
function lhAnalyze(array $byDate, int $horizon, string $candidate): array
{
    $cohorts = [];
    $scoreField = LH_CANDIDATE_FIELDS[$candidate] ?? 'fundamental_score';
    foreach ($byDate as $date => $rows) {
        $eligible = array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($row['returns'][$horizon]) && isset($row[$scoreField])
        ));
        if (count($eligible) < LH_MIN_CROSS_SECTION) {
            continue;
        }
        usort($eligible, static fn (array $a, array $b): int => [$b[$scoreField], $a['ticker']] <=> [$a[$scoreField], $b['ticker']]);
        $top = array_slice($eligible, 0, LH_TOP_N);
        $bottom = array_slice($eligible, -LH_TOP_N);
        $allAlpha = array_map(static fn (array $row): float => (float) $row['returns'][$horizon]['alpha'], $eligible);
        $topAlpha = array_map(static fn (array $row): float => (float) $row['returns'][$horizon]['alpha'], $top);
        $bottomAlpha = array_map(static fn (array $row): float => (float) $row['returns'][$horizon]['alpha'], $bottom);
        $allAdverse = array_map(static fn (array $row): float => (float) $row['returns'][$horizon]['max_adverse_excursion'], $eligible);
        $topAdverse = array_map(static fn (array $row): float => (float) $row['returns'][$horizon]['max_adverse_excursion'], $top);
        $allDrawdown = array_map(static fn (array $row): float => (float) $row['returns'][$horizon]['max_drawdown'], $eligible);
        $topDrawdown = array_map(static fn (array $row): float => (float) $row['returns'][$horizon]['max_drawdown'], $top);
        $allSevereLoss = array_map(static fn (array $row): float => (float) $row['returns'][$horizon]['severe_final_loss'], $eligible);
        $topSevereLoss = array_map(static fn (array $row): float => (float) $row['returns'][$horizon]['severe_final_loss'], $top);
        $scores = array_map(static fn (array $row): float => (float) $row[$scoreField], $eligible);
        $cohorts[] = [
            'date' => $date,
            'eligible' => count($eligible),
            'top10_vs_universe_alpha_pct' => round(lhMean($topAlpha) - lhMean($allAlpha), 6),
            'top10_vs_bottom10_alpha_pct' => round(lhMean($topAlpha) - lhMean($bottomAlpha), 6),
            'rank_ic' => round(lhPearson(lhRanks($scores), lhRanks($allAlpha)) ?? 0.0, 6),
            'top10_max_adverse_excursion_pct' => round(lhMean($topAdverse), 6),
            'universe_max_adverse_excursion_pct' => round(lhMean($allAdverse), 6),
            'max_adverse_excursion_reduction_pct' => round(lhMean($allAdverse) - lhMean($topAdverse), 6),
            'max_drawdown_reduction_pct' => round(lhMean($allDrawdown) - lhMean($topDrawdown), 6),
            'severe_final_loss_reduction_pp' => round((lhMean($allSevereLoss) - lhMean($topSevereLoss)) * 100.0, 6),
        ];
    }
    $lag = max(1, (int) ceil($horizon / 21));
    $primary = array_column($cohorts, 'top10_vs_universe_alpha_pct');
    $middle = intdiv(count($primary), 2);
    $adverseReduction = array_column($cohorts, 'max_adverse_excursion_reduction_pct');
    $riskMiddle = intdiv(count($adverseReduction), 2);
    return [
        'candidate' => $candidate,
        'horizon' => $horizon,
        'primary_top10_vs_universe' => lhStats($primary, $lag),
        'top10_vs_bottom10' => lhStats(array_column($cohorts, 'top10_vs_bottom10_alpha_pct'), $lag),
        'rank_ic' => lhStats(array_column($cohorts, 'rank_ic'), $lag),
        'early_half' => lhStats(array_slice($primary, 0, $middle), $lag),
        'late_half' => lhStats(array_slice($primary, $middle), $lag),
        'risk' => [
            'max_adverse_excursion_reduction' => lhStats($adverseReduction, $lag),
            'max_adverse_excursion_halves' => [
                'first' => lhStats(array_slice($adverseReduction, 0, $riskMiddle), $lag),
                'second' => lhStats(array_slice($adverseReduction, $riskMiddle), $lag),
            ],
            'max_drawdown_reduction' => lhStats(array_column($cohorts, 'max_drawdown_reduction_pct'), $lag),
            'severe_final_loss_reduction' => lhStats(array_column($cohorts, 'severe_final_loss_reduction_pp'), $lag),
        ],
        'cohorts' => $cohorts,
    ];
}

/** @param list<float> $values @return array{n:int,mean:?float,newey_west_t:?float,positive_pct:?float} */
function lhStats(array $values, int $lag): array
{
    $n = count($values);
    if ($n === 0) {
        return ['n' => 0, 'mean' => null, 'newey_west_t' => null, 'positive_pct' => null];
    }
    $mean = lhMean($values);
    $residuals = array_map(static fn (float $value): float => $value - $mean, $values);
    $variance = array_sum(array_map(static fn (float $value): float => $value ** 2, $residuals)) / $n;
    $usableLag = min($lag, $n - 1);
    for ($offset = 1; $offset <= $usableLag; $offset++) {
        $covariance = 0.0;
        for ($index = $offset; $index < $n; $index++) {
            $covariance += $residuals[$index] * $residuals[$index - $offset];
        }
        $variance += 2.0 * (1.0 - $offset / ($usableLag + 1)) * ($covariance / $n);
    }
    $se = sqrt(max(0.0, $variance) / $n);
    $positive = count(array_filter($values, static fn (float $value): bool => $value > 0.0));
    return [
        'n' => $n,
        'mean' => round($mean, 6),
        'newey_west_t' => $se > 0.0 ? round($mean / $se, 6) : null,
        'positive_pct' => round($positive / $n * 100.0, 6),
    ];
}

/** @param list<float> $values */
function lhMean(array $values): float
{
    return array_sum($values) / count($values);
}

/** @param list<float> $values @return list<float> */
function lhRanks(array $values): array
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
function lhPearson(array $left, array $right): ?float
{
    if (count($left) < 3 || count($left) !== count($right)) {
        return null;
    }
    $leftMean = lhMean($left);
    $rightMean = lhMean($right);
    $numerator = 0.0;
    $leftSquared = 0.0;
    $rightSquared = 0.0;
    foreach ($left as $index => $value) {
        $a = $value - $leftMean;
        $b = $right[$index] - $rightMean;
        $numerator += $a * $b;
        $leftSquared += $a ** 2;
        $rightSquared += $b ** 2;
    }
    $denominator = sqrt($leftSquared * $rightSquared);
    return $denominator > 0.0 ? $numerator / $denominator : null;
}
