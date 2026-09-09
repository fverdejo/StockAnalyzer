<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Services\HistoricalTrendSnapshotCalculator;

const TP_HORIZONS = [60, 126];
const TP_DISCOVERY_END = '2020-12-31';
const TP_HOLDOUT_START = '2022-01-01';
const TP_MIN_MONTH_ROWS = 100;
const TP_MIN_SIGNAL_ROWS = 10;

$options = getopt('', ['phase::', 'signal::', 'horizon::', 'output::']);
$phase = is_string($options['phase'] ?? null) ? (string) $options['phase'] : 'discovery';
$selectedSignal = is_string($options['signal'] ?? null) ? (string) $options['signal'] : null;
$selectedHorizon = is_string($options['horizon'] ?? null) ? (int) $options['horizon'] : null;

if (!in_array($phase, ['discovery', 'validation'], true)) {
    throw new InvalidArgumentException("--phase debe ser 'discovery' o 'validation'.");
}

if ($phase === 'validation' && (!in_array($selectedSignal, ['buy', 'sell'], true) || !in_array($selectedHorizon, TP_HORIZONS, true))) {
    throw new InvalidArgumentException('Validation exige --signal=buy|sell y --horizon=60|126.');
}

$root = dirname(__DIR__);
$defaultOutput = $phase === 'discovery'
    ? 'storage/scratch/technical_trend_policy_discovery.json'
    : sprintf('storage/scratch/technical_trend_policy_validation_%s_%d.json', $selectedSignal, $selectedHorizon);
$outputOption = is_string($options['output'] ?? null) ? (string) $options['output'] : $defaultOutput;
$outputPath = str_starts_with($outputOption, '/') ? $outputOption : $root . '/' . $outputOption;
$tickers = tpTickers($root . '/storage/scratch/point_in_time_universe.txt');
$connection = new Connection();
$pdo = $connection->getPdo();
$cache = new MarketDataCacheRepository($connection);
$ttl = new DateInterval('P100Y');
$trend = new HistoricalTrendSnapshotCalculator();
$spy = $cache->findHistory('SPY', $ttl, '10y');

if ($spy === null || $spy === []) {
    throw new RuntimeException('Falta SPY 10y en cache.');
}

tpSort($spy);
$spyByDate = tpIndex($spy);
$signals = array_values(array_filter(
    tpMonthlyDates($spy),
    static fn (string $date): bool => $phase === 'discovery'
        ? $date <= TP_DISCOVERY_END
        : $date >= TP_HOLDOUT_START
));
$spyTrendBySignal = [];

foreach ($signals as $signalDate) {
    $spyTrendBySignal[$signalDate] = $trend->calculate(new DateTimeImmutable($signalDate), $spy);
}

$memberships = tpMemberships($pdo);
$horizons = $phase === 'discovery' ? TP_HORIZONS : [$selectedHorizon];
$audit = [
    'phase' => $phase,
    'universe_tickers' => count($tickers),
    'signal_months_considered' => count($signals),
    'price_histories_found' => 0,
    'trend_snapshots_available' => 0,
    'outside_membership_or_no_entry' => 0,
    'observations' => 0,
    'buy_flags' => 0,
    'sell_flags' => 0,
    'returns_available' => array_fill_keys($horizons, 0),
];

/** @var array<string,list<array<string,mixed>>> $byDate */
$byDate = [];

foreach ($tickers as $position => $ticker) {
    $history = $cache->findHistory($ticker, $ttl, '10y');

    if ($history === null || $history === []) {
        continue;
    }

    $audit['price_histories_found']++;
    tpSort($history);

    foreach ($signals as $signalDate) {
        $stockTrend = $trend->calculate(new DateTimeImmutable($signalDate), $history);
        $marketTrend = $spyTrendBySignal[$signalDate] ?? null;

        if ($stockTrend === null || $marketTrend === null) {
            continue;
        }

        $audit['trend_snapshots_available']++;
        $probe = tpOutcome($signalDate, $history, $spyByDate, 1);

        if ($probe === null || !tpCovers($memberships[$ticker] ?? null, $probe['entry_date'])) {
            $audit['outside_membership_or_no_entry']++;
            continue;
        }

        $outcomes = [];

        foreach ($horizons as $horizon) {
            $outcome = tpOutcome($signalDate, $history, $spyByDate, $horizon);
            if ($outcome !== null) {
                $outcomes[$horizon] = $outcome;
                $audit['returns_available'][$horizon]++;
            }
        }

        if ($outcomes === []) {
            continue;
        }

        $buy = $stockTrend['above_sma200']
            && $stockTrend['momentum_12_1_positive']
            && $marketTrend['above_sma200'];
        $sell = !$stockTrend['above_sma200'] && !$stockTrend['momentum_12_1_positive'];
        $audit['buy_flags'] += $buy ? 1 : 0;
        $audit['sell_flags'] += $sell ? 1 : 0;
        $byDate[$signalDate][] = [
            'ticker' => $ticker,
            'buy' => $buy,
            'sell' => $sell,
            'outcomes' => $outcomes,
        ];
        $audit['observations']++;
    }

    if (($position + 1) % 50 === 0) {
        fwrite(STDERR, sprintf("Procesados %d/%d tickers\n", $position + 1, count($tickers)));
    }
}

$results = [];

foreach ($horizons as $horizon) {
    $analysis = tpAnalyze($byDate, $horizon);
    $results[(string) $horizon] = $phase === 'discovery'
        ? $analysis
        : [
            'horizon' => $horizon,
            'selected_signal' => $selectedSignal,
            'metrics' => $analysis[$selectedSignal],
            'monthly_eligibility' => $analysis['monthly_eligibility'],
            'event_diagnostics' => $analysis['event_diagnostics'][$selectedSignal],
            'cohorts' => $analysis['cohorts'][$selectedSignal],
            'month_screening' => $analysis['month_screening'],
        ];
}

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'study' => 'technical_dual_trend_policy',
    'phase' => $phase,
    'status' => 'research_only_not_used_by_production_score',
    'date_boundary' => $phase === 'discovery'
        ? ['signals_through' => TP_DISCOVERY_END, 'holdout_not_loaded' => true]
        : ['signals_from' => TP_HOLDOUT_START, 'signal' => $selectedSignal, 'horizon' => $selectedHorizon],
    'rules' => [
        'buy' => 'Cierre>SMA200, momentum 12-1 positivo y SPY>SMA200.',
        'sell' => 'Cierre<SMA200 y momentum 12-1 negativo.',
        'signal_time' => 'Cierre de la ultima sesion mensual; entrada en la apertura siguiente.',
        'monthly_requirements' => sprintf(
            'Cada senal se evalua de forma independiente: universo >=%d y su propia cohorte >=%d; BUY no exige SELL y SELL no exige BUY.',
            TP_MIN_MONTH_ROWS,
            TP_MIN_SIGNAL_ROWS
        ),
        'buy_metric' => 'Alpha mensual BUY menos universo.',
        'sell_metric' => 'Alpha mensual universo menos SELL; positivo favorece evitar/vender.',
        'event_diagnostics' => 'Cada senal se compara solo con el universo de sus mismos meses admitidos; el estimador primario mantiene igual ponderacion mensual.',
        'risk' => 'Perdida >=10% y drawdown >=20% durante el horizonte.',
        'newey_west_lag' => 'ceil(horizon/21).',
    ],
    'audit' => $audit,
    'results' => $results,
    'limitations' => [
        'Universo historico capturado incompleto.',
        'Retornos sin costes, impuestos ni dividendos.',
        'Una alerta tecnica no evalua cambios en la tesis fundamental.',
    ],
];
$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

if (file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
    throw new RuntimeException('No se pudo escribir el resultado.');
}

$summary = [];
foreach ($results as $horizon => $analysis) {
    if ($phase === 'discovery') {
        $summary[$horizon] = [
            'buy' => $analysis['buy'],
            'sell' => $analysis['sell'],
            'monthly_eligibility' => $analysis['monthly_eligibility'],
            'cohort_counts' => [
                'buy' => count($analysis['cohorts']['buy']),
                'sell' => count($analysis['cohorts']['sell']),
            ],
            'event_diagnostics' => $analysis['event_diagnostics'],
        ];
    } else {
        $summary[$horizon] = $analysis;
    }
}
echo json_encode(['output' => str_replace($root . '/', '', $outputPath), 'audit' => $audit, 'summary' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

/** @return list<string> */
function tpTickers(string $path): array
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

/** @param list<HistoricalQuote> $history */
function tpSort(array &$history): void
{
    usort($history, static fn (HistoricalQuote $a, HistoricalQuote $b): int => $a->getDate() <=> $b->getDate());
}

/** @param list<HistoricalQuote> $history @return array<string,HistoricalQuote> */
function tpIndex(array $history): array
{
    $result = [];
    foreach ($history as $quote) {
        $result[$quote->getDate()->format('Y-m-d')] = $quote;
    }
    return $result;
}

/** @param list<HistoricalQuote> $history @return list<string> */
function tpMonthlyDates(array $history): array
{
    $dates = [];
    foreach ($history as $quote) {
        $dates[$quote->getDate()->format('Y-m')] = $quote->getDate()->format('Y-m-d');
    }
    return array_values($dates);
}

/** @return array<string,array{start_date:?string,end_date:?string}> */
function tpMemberships(PDO $pdo): array
{
    $rows = $pdo->query("SELECT ticker,start_date,end_date FROM index_membership WHERE index_code='GSPC'")->fetchAll(PDO::FETCH_ASSOC);
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
function tpCovers(?array $membership, string $date): bool
{
    return $membership !== null
        && ($membership['start_date'] === null || $membership['start_date'] <= $date)
        && ($membership['end_date'] === null || $membership['end_date'] >= $date);
}

/**
 * @param list<HistoricalQuote> $history
 * @param array<string,HistoricalQuote> $benchmark
 * @return array{entry_date:string,alpha:float,stock_return:float,max_drawdown_pct:float}|null
 */
function tpOutcome(string $signalDate, array $history, array $benchmark, int $horizon): ?array
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
    if (!$entry instanceof HistoricalQuote || !$exit instanceof HistoricalQuote || $entry->getOpen() <= 0.0 || $exit->getClose() <= 0.0) {
        return null;
    }
    if ((new DateTimeImmutable($signalDate))->diff($entry->getDate())->days > 7) {
        return null;
    }
    $entryDate = $entry->getDate()->format('Y-m-d');
    $exitDate = $exit->getDate()->format('Y-m-d');
    $benchmarkEntry = $benchmark[$entryDate] ?? null;
    $benchmarkExit = $benchmark[$exitDate] ?? null;
    if (!$benchmarkEntry instanceof HistoricalQuote || !$benchmarkExit instanceof HistoricalQuote || $benchmarkEntry->getOpen() <= 0.0 || $benchmarkExit->getClose() <= 0.0) {
        return null;
    }
    $stockReturn = (($exit->getClose() / $entry->getOpen()) - 1.0) * 100.0;
    $benchmarkReturn = (($benchmarkExit->getClose() / $benchmarkEntry->getOpen()) - 1.0) * 100.0;
    $peak = $entry->getOpen();
    $maximumDrawdown = 0.0;
    for ($index = $low; $index <= $low + $horizon; $index++) {
        $close = $history[$index]->getClose();
        $peak = max($peak, $close);
        $maximumDrawdown = min($maximumDrawdown, (($close / $peak) - 1.0) * 100.0);
    }
    return ['entry_date' => $entryDate, 'alpha' => $stockReturn - $benchmarkReturn, 'stock_return' => $stockReturn, 'max_drawdown_pct' => $maximumDrawdown];
}

/** @param array<string,list<array<string,mixed>>> $byDate @return array<string,mixed> */
function tpAnalyze(array $byDate, int $horizon): array
{
    $cohorts = ['buy' => [], 'sell' => []];
    $events = [
        'buy' => ['universe' => [], 'signal' => []],
        'sell' => ['universe' => [], 'signal' => []],
    ];
    $monthlyEligibility = [
        'months_with_rows' => 0,
        'months_passing_universe_min' => 0,
        'months_below_universe_min' => 0,
        'buy' => ['months_passing' => 0, 'months_below_signal_min' => 0],
        'sell' => ['months_passing' => 0, 'months_below_signal_min' => 0],
        'overlap_among_universe_qualified' => [
            'both_pass' => 0,
            'buy_only' => 0,
            'sell_only' => 0,
            'neither' => 0,
        ],
    ];
    $monthScreening = [];
    ksort($byDate);

    foreach ($byDate as $date => $rows) {
        $eligible = array_values(array_filter($rows, static fn (array $row): bool => isset($row['outcomes'][$horizon])));
        $buy = array_values(array_filter($eligible, static fn (array $row): bool => $row['buy'] === true));
        $sell = array_values(array_filter($eligible, static fn (array $row): bool => $row['sell'] === true));
        $eligibleCount = count($eligible);
        $buyCount = count($buy);
        $sellCount = count($sell);
        $universePasses = $eligibleCount >= TP_MIN_MONTH_ROWS;
        $buyPasses = $universePasses && $buyCount >= TP_MIN_SIGNAL_ROWS;
        $sellPasses = $universePasses && $sellCount >= TP_MIN_SIGNAL_ROWS;
        $monthlyEligibility['months_with_rows']++;

        $screening = [
            'date' => $date,
            'universe_events' => $eligibleCount,
            'buy_events' => $buyCount,
            'sell_events' => $sellCount,
            'universe_passes_minimum' => $universePasses,
            'buy_passes_own_minimum' => $buyPasses,
            'sell_passes_own_minimum' => $sellPasses,
            'buy_minus_universe_alpha_pct' => null,
            'universe_minus_sell_alpha_pct' => null,
        ];

        if (!$universePasses) {
            $monthlyEligibility['months_below_universe_min']++;
            $monthScreening[] = $screening;
            continue;
        }

        $monthlyEligibility['months_passing_universe_min']++;
        $monthlyEligibility['buy'][$buyPasses ? 'months_passing' : 'months_below_signal_min']++;
        $monthlyEligibility['sell'][$sellPasses ? 'months_passing' : 'months_below_signal_min']++;

        $overlapKey = match (true) {
            $buyPasses && $sellPasses => 'both_pass',
            $buyPasses => 'buy_only',
            $sellPasses => 'sell_only',
            default => 'neither',
        };
        $monthlyEligibility['overlap_among_universe_qualified'][$overlapKey]++;

        $allMean = tpMean(tpAlphas($eligible, $horizon));

        if ($buyPasses) {
            $buyEdge = round(tpMean(tpAlphas($buy, $horizon)) - $allMean, 6);
            $screening['buy_minus_universe_alpha_pct'] = $buyEdge;
            $cohorts['buy'][] = [
                'date' => $date,
                'universe_events' => $eligibleCount,
                'buy_events' => $buyCount,
                'buy_minus_universe_alpha_pct' => $buyEdge,
            ];
            foreach ($eligible as $row) {
                $events['buy']['universe'][] = $row['outcomes'][$horizon];
            }
            foreach ($buy as $row) {
                $events['buy']['signal'][] = $row['outcomes'][$horizon];
            }
        }

        if ($sellPasses) {
            $sellEdge = round($allMean - tpMean(tpAlphas($sell, $horizon)), 6);
            $screening['universe_minus_sell_alpha_pct'] = $sellEdge;
            $cohorts['sell'][] = [
                'date' => $date,
                'universe_events' => $eligibleCount,
                'sell_events' => $sellCount,
                'universe_minus_sell_alpha_pct' => $sellEdge,
            ];
            foreach ($eligible as $row) {
                $events['sell']['universe'][] = $row['outcomes'][$horizon];
            }
            foreach ($sell as $row) {
                $events['sell']['signal'][] = $row['outcomes'][$horizon];
            }
        }

        $monthScreening[] = $screening;
    }

    $lag = max(1, (int) ceil($horizon / 21));
    $buySeries = array_column($cohorts['buy'], 'buy_minus_universe_alpha_pct');
    $sellSeries = array_column($cohorts['sell'], 'universe_minus_sell_alpha_pct');
    $buyMiddle = intdiv(count($buySeries), 2);
    $sellMiddle = intdiv(count($sellSeries), 2);

    return [
        'horizon' => $horizon,
        'buy' => [
            'primary' => tpStats($buySeries, $lag),
            'early_half' => tpStats(array_slice($buySeries, 0, $buyMiddle), $lag),
            'late_half' => tpStats(array_slice($buySeries, $buyMiddle), $lag),
        ],
        'sell' => [
            'primary' => tpStats($sellSeries, $lag),
            'early_half' => tpStats(array_slice($sellSeries, 0, $sellMiddle), $lag),
            'late_half' => tpStats(array_slice($sellSeries, $sellMiddle), $lag),
        ],
        'event_diagnostics' => [
            'buy' => [
                'comparison_universe_same_months' => tpEventStats($events['buy']['universe']),
                'signal' => tpEventStats($events['buy']['signal']),
            ],
            'sell' => [
                'comparison_universe_same_months' => tpEventStats($events['sell']['universe']),
                'signal' => tpEventStats($events['sell']['signal']),
            ],
        ],
        'monthly_eligibility' => $monthlyEligibility,
        'cohorts' => $cohorts,
        'month_screening' => $monthScreening,
    ];
}

/** @param list<array<string,mixed>> $rows @return list<float> */
function tpAlphas(array $rows, int $horizon): array
{
    return array_map(static fn (array $row): float => (float) $row['outcomes'][$horizon]['alpha'], $rows);
}

/** @param list<array<string,mixed>> $outcomes @return array<string,mixed> */
function tpEventStats(array $outcomes): array
{
    if ($outcomes === []) {
        return ['events' => 0];
    }
    $losses = count(array_filter($outcomes, static fn (array $row): bool => $row['stock_return'] <= -10.0));
    $drawdowns = count(array_filter($outcomes, static fn (array $row): bool => $row['max_drawdown_pct'] <= -20.0));
    return [
        'events' => count($outcomes),
        'mean_alpha_pct' => round(tpMean(array_column($outcomes, 'alpha')), 6),
        'mean_stock_return_pct' => round(tpMean(array_column($outcomes, 'stock_return')), 6),
        'mean_max_drawdown_pct' => round(tpMean(array_column($outcomes, 'max_drawdown_pct')), 6),
        'loss_10pct_or_worse_pct' => round($losses / count($outcomes) * 100.0, 6),
        'drawdown_20pct_or_worse_pct' => round($drawdowns / count($outcomes) * 100.0, 6),
    ];
}

/** @param list<float> $values @return array{n:int,mean:?float,newey_west_t:?float,positive_pct:?float} */
function tpStats(array $values, int $lag): array
{
    $n = count($values);
    if ($n === 0) {
        return ['n' => 0, 'mean' => null, 'newey_west_t' => null, 'positive_pct' => null];
    }
    $mean = tpMean($values);
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
function tpMean(array $values): float
{
    return array_sum($values) / count($values);
}
