<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Repository\MarketDataCacheRepository;

/** Calibracion mensual y totalmente local de las etiquetas actuales. */

const RC_HORIZONS = [20, 60];
const RC_DISCOVERY_END = '2020-12-31';
const RC_VALIDATION_START = '2022-01-01';
const RC_MIN_GROUP_PER_MONTH = 3;
const RC_TOP_N = 10;
const RC_ROUND_TRIP_COST_PCT = 0.20;
const RC_MAX_ENTRY_DELAY_CALENDAR_DAYS = 7;
const RC_RISK_HORIZON = 60;
const RC_RISK_MIN_AUDIT_MONTHS = 24;
const RC_RISK_ONE_SIDED_ALPHA = 0.05;
const RC_INCREMENTAL_MIN_MONTHS = 12;
const RC_INCREMENTAL_MIN_POSITIVE_MONTHS_PCT = 60.0;
const RC_INCREMENTAL_MIN_NORMALIZED_EFFECT = 0.10;
const RC_COMPONENT_BONFERRONI_T = 2.807;
const RC_COMPONENT_FACTORS = [
    'trend_20',
    'trend_50',
    'ma_spread_20_50',
    'macd_histogram_pct',
    'bollinger_lower_reversion',
    'volume_ratio',
    'momentum_12_1',
    'rsi_health_60',
    'low_volatility_20',
    'low_atr_14',
];

$options = getopt('', ['component-candidate::']);
$componentCandidate = is_string($options['component-candidate'] ?? null)
    ? (string) $options['component-candidate']
    : null;

if ($componentCandidate !== null && !in_array($componentCandidate, RC_COMPONENT_FACTORS, true)) {
    throw new InvalidArgumentException('Componente desconocido: ' . $componentCandidate);
}

$root = dirname(__DIR__);
$outputPath = $root . '/storage/scratch/recommendation_calibration.json';
$tickers = rcTickers($root . '/storage/scratch/point_in_time_universe.txt');
$connection = new Connection();
$pdo = $connection->getPdo();
$cache = new MarketDataCacheRepository($connection);
$ttl = new DateInterval('P100Y');
$technicalAnalyzer = new TechnicalAnalyzer();
$weights = new ScoreWeights();

foreach ([ScoreCategory::FUNDAMENTAL, ScoreCategory::VALUATION, ScoreCategory::QUALITY, ScoreCategory::DIVIDEND, ScoreCategory::NEWS] as $inactiveCategory) {
    if ($weights->getMax($inactiveCategory) !== 0.0) {
        throw new RuntimeException(sprintf(
            'El calibrador tecnico aborta: %s tiene peso %.2f y usaria datos no point-in-time.',
            $inactiveCategory->value,
            $weights->getMax($inactiveCategory)
        ));
    }
}

$scoreCalculator = new ScoreCalculator($weights);
$riskRunManifest = [
    'script_sha256' => hash_file('sha256', __FILE__) ?: null,
    'universe_sha256' => hash_file('sha256', $root . '/storage/scratch/point_in_time_universe.txt') ?: null,
    'weights_config_sha256' => is_file($root . '/config/weights.php')
        ? (hash_file('sha256', $root . '/config/weights.php') ?: null)
        : null,
    'score_weights' => $weights->toArray(),
];
foreach (['script_sha256', 'universe_sha256', 'weights_config_sha256'] as $requiredHash) {
    if ($riskRunManifest[$requiredHash] === null) {
        throw new RuntimeException('No se pudo congelar el manifiesto de riesgo: ' . $requiredHash);
    }
}
$spy = $cache->findHistory('SPY', $ttl, '10y');

if ($spy === null || $spy === []) {
    throw new RuntimeException('Falta SPY 10y en cache.');
}

rcSort($spy);
$spyByDate = rcIndexQuotes($spy);
$signals = rcMonthlyDates($spy);
$memberships = rcMemberships($pdo);
$audit = [
    'universe_tickers' => count($tickers),
    'signal_months' => count($signals),
    'stocks_found' => 0,
    'histories_found' => 0,
    'technical_snapshots_calculated' => 0,
    'outside_membership_or_missing_price' => 0,
    'observations_by_horizon' => array_fill_keys(RC_HORIZONS, 0),
    'outcome_unavailable_by_horizon_and_recommendation' => array_fill_keys(RC_HORIZONS, []),
    'recommendation_counts' => [],
    'score_min' => null,
    'score_max' => null,
];

/** @var array<int,array<string,list<array{ticker:string,recommendation:string,score:float,alpha:float,stock_return:float,max_adverse_excursion_close_pct:float,final_loss_10_close_rate_pct:float,final_loss_20_close_rate_pct:float,factors:array<string,float>}>>> $byHorizonDate */
$byHorizonDate = array_fill_keys(RC_HORIZONS, []);

foreach ($tickers as $position => $ticker) {
    $stock = $cache->findStock($ticker, $ttl);
    $history = $cache->findHistory($ticker, $ttl, '10y');

    if ($stock === null || $history === null || $history === []) {
        continue;
    }

    $audit['stocks_found']++;
    $audit['histories_found']++;
    rcSort($history);
    $indexByDate = [];

    foreach ($history as $index => $quote) {
        $indexByDate[$quote->getDate()->format('Y-m-d')] = $index;
    }

    foreach ($signals as $signalDate) {
        $signalIndex = $indexByDate[$signalDate] ?? null;

        if (!is_int($signalIndex) || $signalIndex < 251 || !isset($history[$signalIndex + 1])) {
            continue;
        }

        $entry = $history[$signalIndex + 1];

        if (!rcCovers($memberships[$ticker] ?? null, $entry->getDate()->format('Y-m-d'))) {
            $audit['outside_membership_or_missing_price']++;
            continue;
        }

        $technical = $technicalAnalyzer->analyze(array_slice($history, 0, $signalIndex + 1));
        $historicalStock = rcHistoricalStock($stock, $history[$signalIndex]);
        $score = $scoreCalculator->calculate($historicalStock, $technical)->getScore();
        $percentage = $score->getPercentage();
        $recommendation = $score->getRecommendation();
        $audit['technical_snapshots_calculated']++;
        $audit['recommendation_counts'][$recommendation] = ($audit['recommendation_counts'][$recommendation] ?? 0) + 1;
        $audit['score_min'] = $audit['score_min'] === null ? $percentage : min($audit['score_min'], $percentage);
        $audit['score_max'] = $audit['score_max'] === null ? $percentage : max($audit['score_max'], $percentage);

        foreach (RC_HORIZONS as $horizon) {
            $outcome = rcOutcome($history, $signalIndex, $spyByDate, $horizon);

            if ($outcome === null) {
                $audit['outcome_unavailable_by_horizon_and_recommendation'][$horizon][$recommendation] =
                    ($audit['outcome_unavailable_by_horizon_and_recommendation'][$horizon][$recommendation] ?? 0) + 1;
                continue;
            }

            $byHorizonDate[$horizon][$signalDate][] = [
                'ticker' => $ticker,
                'recommendation' => $recommendation,
                'score' => $percentage,
                'alpha' => $outcome['alpha'],
                'stock_return' => $outcome['stock_return'],
                'entry_date' => $outcome['entry_date'],
                'exit_date' => $outcome['exit_date'],
                'max_adverse_excursion_close_pct' => $outcome['max_adverse_excursion_close_pct'],
                'final_loss_10_close_rate_pct' => $outcome['final_loss_10_close_rate_pct'],
                'final_loss_20_close_rate_pct' => $outcome['final_loss_20_close_rate_pct'],
                'factors' => rcTechnicalFactors($technical, $history[$signalIndex]->getClose()),
            ];
            $audit['observations_by_horizon'][$horizon]++;
        }
    }

    if (($position + 1) % 50 === 0) {
        fwrite(STDERR, sprintf("Procesados %d/%d tickers\n", $position + 1, count($tickers)));
    }
}

ksort($audit['recommendation_counts']);
foreach ($audit['outcome_unavailable_by_horizon_and_recommendation'] as &$unavailableByRecommendation) {
    ksort($unavailableByRecommendation);
}
unset($unavailableByRecommendation);
$results = [];

foreach (RC_HORIZONS as $horizon) {
    $results[(string) $horizon] = [
        'discovery' => rcAnalyzePeriod($byHorizonDate[$horizon], $horizon, 'discovery'),
        'validation' => rcAnalyzePeriod($byHorizonDate[$horizon], $horizon, 'validation'),
    ];
}

$riskResults = [
    'discovery' => rcAnalyzeRiskPeriod($byHorizonDate[RC_RISK_HORIZON], RC_RISK_HORIZON, 'discovery'),
    'audit' => rcAnalyzeRiskPeriod($byHorizonDate[RC_RISK_HORIZON], RC_RISK_HORIZON, 'audit'),
];
$riskDecision = rcAssessRiskEvidence($riskResults);
$incrementalRiskResults = [
    'discovery' => rcAnalyzeIncrementalRiskPeriod($byHorizonDate[RC_RISK_HORIZON], RC_RISK_HORIZON, 'discovery'),
    'audit_descriptive_only' => rcAnalyzeIncrementalRiskPeriod($byHorizonDate[RC_RISK_HORIZON], RC_RISK_HORIZON, 'audit'),
];
$incrementalRiskDecision = rcAssessIncrementalRiskEvidence($incrementalRiskResults);
$incrementalRiskManifest = $riskRunManifest;
$incrementalRiskManifest['protocol_id'] = 'P4_INCREMENTAL_RISK_VOL20_ATR14_V1_2026-09-05';
$incrementalRiskManifest['protocol_frozen_before_execution'] = true;
$incrementalRiskManifest['technical_analyzer_sha256'] = hash_file(
    'sha256',
    $root . '/src/Analyzer/TechnicalAnalyzer.php'
) ?: null;
$incrementalRiskManifest['technical_score_analyzer_sha256'] = hash_file(
    'sha256',
    $root . '/src/Analyzer/TechnicalScoreAnalyzer.php'
) ?: null;
$incrementalRiskManifest['score_calculator_sha256'] = hash_file(
    'sha256',
    $root . '/src/Analyzer/ScoreCalculator.php'
) ?: null;
$incrementalRiskManifest['score_model_sha256'] = hash_file(
    'sha256',
    $root . '/src/Models/Score.php'
) ?: null;
foreach (['technical_analyzer_sha256', 'technical_score_analyzer_sha256', 'score_calculator_sha256', 'score_model_sha256'] as $requiredHash) {
    if ($incrementalRiskManifest[$requiredHash] === null) {
        throw new RuntimeException('No se pudo congelar el manifiesto incremental: ' . $requiredHash);
    }
}

$componentResults = [];
foreach (RC_HORIZONS as $horizon) {
    $componentResults[(string) $horizon] = [
        'discovery' => rcAnalyzeComponents($byHorizonDate[$horizon], $horizon, RC_COMPONENT_FACTORS, 'discovery'),
        'validation' => $componentCandidate === null
            ? null
            : rcAnalyzeComponents($byHorizonDate[$horizon], $horizon, [$componentCandidate], 'validation'),
    ];
}

$selectedComponents = [];
foreach ($componentResults['60']['discovery'] as $factor => $metrics) {
    $edge = $metrics['top_quintile_edge_vs_universe'];
    $halves = $metrics['edge_halves'];
    $spread = $metrics['top_minus_bottom'];
    if (($edge['mean'] ?? -INF) > 0.0
        && ($edge['newey_west_t'] ?? -INF) >= RC_COMPONENT_BONFERRONI_T
        && ($halves['first']['mean'] ?? -INF) > 0.0
        && ($halves['second']['mean'] ?? -INF) > 0.0
        && ($spread['mean'] ?? -INF) > 0.0
    ) {
        $selectedComponents[] = $factor;
    }
}

$validationBuy = $results['20']['validation']['groups']['BUY']['edge'] ?? null;
$validationSell = $results['20']['validation']['groups']['SELL_ANY']['edge'] ?? null;
$activeLabelsSupported = is_array($validationBuy)
    && is_array($validationSell)
    && ($validationBuy['newey_west_t'] ?? -INF) >= 2.0
    && ($validationBuy['mean'] ?? -INF) > 0.0
    && ($validationSell['newey_west_t'] ?? -INF) >= 2.0
    && ($validationSell['mean'] ?? -INF) > 0.0;
$validationTop10 = $results['20']['validation']['fixed_top_10'] ?? null;
$top10Supported = is_array($validationTop10)
    && ($validationTop10['edge_vs_universe']['newey_west_t'] ?? -INF) >= 2.0
    && ($validationTop10['edge_vs_universe']['mean'] ?? -INF) > 0.0
    && ($validationTop10['net_alpha_vs_spy']['newey_west_t'] ?? -INF) >= 2.0
    && ($validationTop10['net_alpha_vs_spy']['mean'] ?? -INF) > 0.0
    && ($validationTop10['edge_vs_universe_halves']['first']['mean'] ?? -INF) > 0.0
    && ($validationTop10['edge_vs_universe_halves']['second']['mean'] ?? -INF) > 0.0;
$result = [
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'study' => 'current_recommendation_calibration',
    'status' => 'research_only_no_production_change',
    'current_score_scope' => 'Technical 30 + Momentum 10 + Risk 10; fundamental categories are currently zero.',
    'score_weights' => $weights->toArray(),
    'manifest' => [
        'script_sha256' => $riskRunManifest['script_sha256'],
        'universe_sha256' => $riskRunManifest['universe_sha256'],
        'weights_config_sha256' => $riskRunManifest['weights_config_sha256'],
        'history_range' => '10y',
        'benchmark' => 'SPY',
    ],
    'periods' => [
        'discovery' => ['through' => RC_DISCOVERY_END],
        'validation' => ['from' => RC_VALIDATION_START],
        'buffer_excluded' => '2021',
    ],
    'edge_definition' => [
        'BUY_and_HOLD' => 'group mean alpha minus monthly universe mean alpha.',
        'SELL_and_STRONG_SELL' => 'monthly universe mean alpha minus group mean alpha; positive means avoiding the group helps.',
        'fixed_top_10' => 'The ten highest current scores each month. Net alpha versus SPY subtracts 0.20 percentage points for a full entry/exit.',
    ],
    'audit' => $audit,
    'results' => $results,
    'technical_component_study' => [
        'ranking' => 'Monthly top quintile by raw component; point-in-time index membership. Higher always means preferred.',
        'multiple_testing_rule' => 'A discovery candidate needs h60 edge t >= 2.807 (Bonferroni for ten predeclared components), positive edge in both halves and positive top-minus-bottom spread.',
        'validation_opened_only_for' => $componentCandidate,
        'selected_in_discovery' => $selectedComponents,
        'results' => $componentResults,
    ],
    'risk_utility_test' => [
        'status' => 'descriptive_research_only_no_label_or_weight_change',
        'horizon_sessions' => RC_RISK_HORIZON,
        'entry_rule' => 'Next available session open, no more than 7 calendar days after the monthly signal.',
        'path_rule' => 'Close-only path from the entry session close through the horizon exit close, relative to the entry open.',
        'metric_definitions' => [
            'max_adverse_excursion_close_pct' => 'Non-negative loss magnitude: max(0, -(minimum close / entry open - 1) * 100). This is explicitly a cierre, not intraday MAE.',
            'final_loss_10_close_rate_pct' => 'Monthly percentage of observations whose final close return from entry open is <= -10%.',
            'final_loss_20_close_rate_pct' => 'Monthly percentage of observations whose final close return from entry open is <= -20%.',
        ],
        'advantage_definition' => [
            'BUY' => 'Monthly universe risk minus monthly BUY risk; positive means BUY has lower risk.',
            'SELL_ANY' => 'Monthly SELL or STRONG SELL risk minus monthly universe risk; positive means avoiding SELL_ANY has lower risk.',
        ],
        'cohort_estimator' => 'Equal-weighted monthly cohort differences with Newey-West/HAC lag 3; every comparison uses the same complete universe for that calendar month.',
        'frozen_run_manifest' => $riskRunManifest,
        'periods' => [
            'discovery' => ['through' => RC_DISCOVERY_END],
            'audit' => ['from' => RC_VALIDATION_START],
            'buffer_excluded' => '2021',
        ],
        'selection_policy' => 'The 10% and 20% final-loss thresholds and 60-session horizon were predeclared. No variants are selected after seeing audit; audit cannot tune thresholds, labels or weights.',
        'interpretation_policy' => [
            'primary' => 'MAE a cierre for BUY and SELL_ANY.',
            'secondary' => 'Final-loss rates at the predeclared 10% and 20% thresholds; secondary metrics cannot rescue a failed primary result.',
            'provisional_materiality' => 'Before execution: at least +0.50 percentage points MAE advantage; +1.00 pp for the 10% loss rate; +0.50 pp for the 20% loss rate.',
            'replication' => 'A useful indication should have a positive discovery effect, a positive temporal-audit effect and no negative secondary audit effect. Raw HAC estimates are reported; no production gate is triggered by this study.',
            'multiplicity' => 'The two primary audit MAE contrasts use one-sided Holm at 5%. The four secondary audit final-loss contrasts use a separate one-sided Holm family and cannot rescue primary failure.',
            'minimum_audit_months_per_group' => RC_RISK_MIN_AUDIT_MONTHS,
        ],
        'results' => $riskResults,
        'predeclared_evidence_assessment' => $riskDecision,
        'cost_treatment' => 'No transaction cost is mixed into these downside-risk metrics; they describe close-only price paths, not net portfolio returns.',
        'limitations' => [
            'Close-only MAE omits intraday lows and adverse opening gaps after entry; it must not be described as full intraday drawdown.',
            'The universe comparator includes the evaluated subgroup, which makes the difference conservative but preserves one identical monthly reference universe.',
            'Only observations with an available 60-session exit can be evaluated, so missing future histories and omitted delisted constituents can understate downside risk.',
            'Returns exclude dividends, taxes, transaction costs and currency effects.',
            'The 2022+ audit partition is temporally reserved here but is not pristine project-wide because earlier studies already inspected it.',
        ],
    ],
    'incremental_risk_vs_volatility_atr_test' => [
        'status' => 'exploratory_research_only_no_label_or_weight_change',
        'question' => 'Do BUY and SELL_ANY add close-only h60 MAE separation beyond a simple monthly volatility20 plus ATR14/price risk rank?',
        'baseline_definition' => [
            'raw_inputs' => 'volatility20 = -low_volatility_20; ATR14/price = -low_atr_14. Both are known at the signal close.',
            'monthly_ranks' => 'Ascending percentile ranks, with average ranks for exact ties; lower is lower observed risk.',
            'composite' => 'Arithmetic mean of the two monthly percentile ranks.',
            'same_size_selector' => 'For BUY select the n lowest-risk rows; for SELL_ANY select the n highest-risk rows, where n equals that label count in the month.',
        ],
        'conditional_control' => [
            'strata' => 'Five deterministic same-month buckets of the composite risk rank.',
            'matching' => 'Without replacement, label versus non-label inside a bucket; greedily take the globally nearest available pair by Manhattan distance in the two component ranks, then ticker for ties.',
            'favorable_sign' => 'control MAE minus BUY MAE for BUY; SELL_ANY MAE minus control MAE for SELL_ANY. Positive means the label adds the expected separation at similar baseline risk.',
            'minimum_pairs_per_month' => RC_MIN_GROUP_PER_MONTH,
        ],
        'estimator' => 'Equal weight per calendar month, close-only h60 MAE, HAC/Newey-West lag 3 preserving calendar gaps; temporal halves are reported.',
        'classification_rule' => [
            'partition_used' => 'Discovery outcomes with exit_date <= 2020-12-31 only.',
            'minimum_months' => RC_INCREMENTAL_MIN_MONTHS,
            'minimum_positive_months_pct' => RC_INCREMENTAL_MIN_POSITIVE_MONTHS_PCT,
            'minimum_mean_effect_divided_by_global_mae_iqr' => RC_INCREMENTAL_MIN_NORMALIZED_EFFECT,
            'label' => 'incremental_promising_exploratory only; never a production gate.',
            'audit_policy' => '2022+ is descriptive only and cannot select the method or thresholds.',
        ],
        'frozen_run_manifest' => $incrementalRiskManifest,
        'results' => $incrementalRiskResults,
        'exploratory_assessment' => $incrementalRiskDecision,
        'limitations' => [
            'The deterministic greedy matcher is transparent but does not guarantee the globally minimum total matching distance.',
            'Matching controls only the two observed ranks; it does not establish a causal incremental effect or balance other technical inputs.',
            'The same-size baseline selector may overlap the label cohort; it is an attribution benchmark, not an independent portfolio.',
            'Complete-case ranking can change the compared universe when either volatility or ATR is missing; coverage and exclusions are reported.',
            'Close-only MAE omits intraday lows and some gaps; requiring an available h60 exit censors missing and delisted outcomes.',
            'The 2022+ partition has already been inspected elsewhere in the project, so it is descriptive and not a fresh holdout.',
            'The manifest hashes code, weights, protocol and universe, but not the mutable OHLC cache or index-membership database rows.',
        ],
    ],
    'decision' => [
        'requires_both_buy_and_sell_t_at_least_2_in_validation' => true,
        'active_labels_supported' => $activeLabelsSupported,
        'fixed_top_10_supported' => $top10Supported,
        'fallback_if_false' => 'INSUFFICIENT_EVIDENCE by default; show observations and confidence instead of directional certainty.',
    ],
    'limitations' => [
        'The 2022+ partition is not pristine project-wide because prior ten-year studies already observed it.',
        'Price returns exclude dividends, taxes and currency effects.',
        'The historical member set lacks many delisted constituents and observations without a future exit price are unavailable.',
        'The incremental-risk study is exploratory attribution, not evidence that BUY or SELL improves return.',
    ],
];
$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

if (file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
    throw new RuntimeException('No se pudo escribir el resultado.');
}

$summary = [];
foreach ($results as $horizon => $periods) {
    foreach ($periods as $period => $analysis) {
        $summary[$horizon][$period] = [
            'months' => $analysis['months'],
            'BUY' => $analysis['groups']['BUY'] ?? null,
            'HOLD' => $analysis['groups']['HOLD'] ?? null,
            'SELL_ANY' => $analysis['groups']['SELL_ANY'] ?? null,
            'STRONG_SELL' => $analysis['groups']['STRONG SELL'] ?? null,
            'buy_minus_sell_edge' => $analysis['buy_minus_sell_edge'],
            'fixed_top_10' => $analysis['fixed_top_10'],
        ];
    }
}
echo json_encode([
    'output' => 'storage/scratch/recommendation_calibration.json',
    'audit' => $audit,
    'active_labels_supported' => $activeLabelsSupported,
    'fixed_top_10_supported' => $top10Supported,
    'summary' => $summary,
    'technical_components' => [
        'bonferroni_t_required' => RC_COMPONENT_BONFERRONI_T,
        'selected_in_discovery' => $selectedComponents,
        'discovery' => $componentResults,
    ],
    'risk_utility_test' => [
        'results' => $riskResults,
        'predeclared_evidence_assessment' => $riskDecision,
    ],
    'incremental_risk_vs_volatility_atr_test' => [
        'results' => $incrementalRiskResults,
        'exploratory_assessment' => $incrementalRiskDecision,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

/** @return list<string> */
function rcTickers(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_values(array_unique(array_map(static fn (string $line): string => strtoupper(trim($line)), $lines === false ? [] : $lines)));
}

/** @param list<HistoricalQuote> $history */
function rcSort(array &$history): void
{
    usort($history, static fn (HistoricalQuote $a, HistoricalQuote $b): int => $a->getDate() <=> $b->getDate());
}

/** @param list<HistoricalQuote> $history @return array<string,HistoricalQuote> */
function rcIndexQuotes(array $history): array
{
    $result = [];
    foreach ($history as $quote) {
        $result[$quote->getDate()->format('Y-m-d')] = $quote;
    }
    return $result;
}

/** @param list<HistoricalQuote> $history @return list<string> */
function rcMonthlyDates(array $history): array
{
    $dates = [];
    foreach ($history as $quote) {
        $dates[$quote->getDate()->format('Y-m')] = $quote->getDate()->format('Y-m-d');
    }
    return array_values($dates);
}

/** @return array<string,array{start_date:?string,end_date:?string}> */
function rcMemberships(PDO $pdo): array
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
function rcCovers(?array $membership, string $date): bool
{
    return $membership !== null
        && ($membership['start_date'] === null || $membership['start_date'] <= $date)
        && ($membership['end_date'] === null || $membership['end_date'] >= $date);
}

function rcHistoricalStock(Stock $stock, HistoricalQuote $quote): Stock
{
    return new Stock(
        $stock->getCompany(),
        new Quote(
            $quote->getClose(),
            $quote->getOpen(),
            $quote->getHigh(),
            $quote->getLow(),
            $quote->getClose(),
            $quote->getVolume(),
            $quote->getDate()
        ),
        $stock->getFundamentals()
    );
}

/** @return array<string,float> */
function rcTechnicalFactors(\StockAnalyzer\DTO\TechnicalSnapshot $snapshot, float $price): array
{
    $factors = [];
    $sma20 = $snapshot->getSma20();
    $sma50 = $snapshot->getSma50();
    $macdHistogram = $snapshot->getMacdHistogram();
    $bollingerUpper = $snapshot->getBollingerUpper();
    $bollingerLower = $snapshot->getBollingerLower();
    $volumeRatio = $snapshot->getVolumeRatio();
    $momentum12m1 = $snapshot->getMomentum12m1();
    $rsi = $snapshot->getRsi14();
    $volatility = $snapshot->getVolatility20();
    $atr = $snapshot->getAtr14();

    if ($price > 0.0 && $sma20 !== null && $sma20 > 0.0) {
        $factors['trend_20'] = ($price / $sma20) - 1.0;
    }
    if ($price > 0.0 && $sma50 !== null && $sma50 > 0.0) {
        $factors['trend_50'] = ($price / $sma50) - 1.0;
    }
    if ($sma20 !== null && $sma50 !== null && $sma50 > 0.0) {
        $factors['ma_spread_20_50'] = ($sma20 / $sma50) - 1.0;
    }
    if ($price > 0.0 && $macdHistogram !== null) {
        $factors['macd_histogram_pct'] = $macdHistogram / $price;
    }
    if ($bollingerUpper !== null && $bollingerLower !== null && $bollingerUpper > $bollingerLower) {
        $factors['bollinger_lower_reversion'] = -(($price - $bollingerLower) / ($bollingerUpper - $bollingerLower));
    }
    if ($volumeRatio !== null) {
        $factors['volume_ratio'] = $volumeRatio;
    }
    if ($momentum12m1 !== null) {
        $factors['momentum_12_1'] = $momentum12m1;
    }
    if ($rsi !== null) {
        $factors['rsi_health_60'] = -abs($rsi - 60.0);
    }
    if ($volatility !== null) {
        $factors['low_volatility_20'] = -$volatility;
    }
    if ($price > 0.0 && $atr !== null) {
        $factors['low_atr_14'] = -($atr / $price);
    }

    return array_filter($factors, static fn (float $value): bool => is_finite($value));
}

/**
 * @param list<HistoricalQuote> $history
 * @param array<string,HistoricalQuote> $benchmark
 * @return array{alpha:float,stock_return:float,entry_date:string,exit_date:string,max_adverse_excursion_close_pct:float,final_loss_10_close_rate_pct:float,final_loss_20_close_rate_pct:float}|null
 */
function rcOutcome(array $history, int $signalIndex, array $benchmark, int $horizon): ?array
{
    $entry = $history[$signalIndex + 1] ?? null;
    $exit = $history[$signalIndex + 1 + $horizon] ?? null;
    if (!$entry instanceof HistoricalQuote || !$exit instanceof HistoricalQuote || $entry->getOpen() <= 0.0 || $exit->getClose() <= 0.0) {
        return null;
    }
    if ((new DateTimeImmutable($history[$signalIndex]->getDate()->format('Y-m-d')))->diff($entry->getDate())->days > RC_MAX_ENTRY_DELAY_CALENDAR_DAYS) {
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
    $minimumCloseReturn = INF;
    for ($index = $signalIndex + 1; $index <= $signalIndex + 1 + $horizon; $index++) {
        $pathQuote = $history[$index] ?? null;
        if (!$pathQuote instanceof HistoricalQuote || $pathQuote->getClose() <= 0.0) {
            return null;
        }
        $minimumCloseReturn = min(
            $minimumCloseReturn,
            (($pathQuote->getClose() / $entry->getOpen()) - 1.0) * 100.0
        );
    }

    return [
        'alpha' => $stockReturn - $benchmarkReturn,
        'stock_return' => $stockReturn,
        'entry_date' => $entryDate,
        'exit_date' => $exitDate,
        'max_adverse_excursion_close_pct' => max(0.0, -$minimumCloseReturn),
        'final_loss_10_close_rate_pct' => $stockReturn <= -10.0 ? 100.0 : 0.0,
        'final_loss_20_close_rate_pct' => $stockReturn <= -20.0 ? 100.0 : 0.0,
    ];
}

/**
 * Tests whether the existing labels separate downside risk without changing or tuning them.
 *
 * @param array<string,list<array<string,mixed>>> $byDate
 * @return array<string,mixed>
 */
function rcAnalyzeRiskPeriod(array $byDate, int $horizon, string $period): array
{
    $metrics = [
        'max_adverse_excursion_close_pct',
        'final_loss_10_close_rate_pct',
        'final_loss_20_close_rate_pct',
    ];
    $groups = ['BUY', 'SELL_ANY'];
    $series = [];
    foreach ($groups as $group) {
        $series[$group] = [
            'group_events' => 0,
            'universe_events' => 0,
            'metrics' => [],
        ];
        foreach ($metrics as $metric) {
            $series[$group]['metrics'][$metric] = [
                'advantage' => [],
                'group_risk' => [],
                'universe_risk' => [],
            ];
        }
    }

    ksort($byDate);
    $monthly = [];
    $purgedCrossingDiscoveryBoundary = 0;
    $earliestEntryDate = null;
    $latestExitDate = null;
    foreach ($byDate as $date => $rows) {
        if ($period === 'discovery') {
            if ($date > RC_DISCOVERY_END) {
                continue;
            }
            $eligibleRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['exit_date'] <= RC_DISCOVERY_END
            ));
            $purgedCrossingDiscoveryBoundary += count($rows) - count($eligibleRows);
        } else {
            if ($date < RC_VALIDATION_START) {
                continue;
            }
            $eligibleRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['entry_date'] >= RC_VALIDATION_START
            ));
        }
        if (count($eligibleRows) < 50) {
            continue;
        }
        $rows = $eligibleRows;
        foreach ($rows as $row) {
            $entryDate = (string) $row['entry_date'];
            $exitDate = (string) $row['exit_date'];
            $earliestEntryDate = $earliestEntryDate === null ? $entryDate : min($earliestEntryDate, $entryDate);
            $latestExitDate = $latestExitDate === null ? $exitDate : max($latestExitDate, $exitDate);
        }

        $groupRows = ['BUY' => [], 'SELL_ANY' => []];
        foreach ($rows as $row) {
            if ($row['recommendation'] === 'BUY') {
                $groupRows['BUY'][] = $row;
            }
            if (in_array($row['recommendation'], ['SELL', 'STRONG SELL'], true)) {
                $groupRows['SELL_ANY'][] = $row;
            }
        }

        $month = [
            'date' => $date,
            'universe_events' => count($rows),
            'groups' => [],
        ];
        foreach ($groups as $group) {
            if (count($groupRows[$group]) < RC_MIN_GROUP_PER_MONTH) {
                continue;
            }

            $groupMetrics = [];
            foreach ($metrics as $metric) {
                $universeRisk = rcMean(array_map(static fn (array $row): float => (float) $row[$metric], $rows));
                $groupRisk = rcMean(array_map(static fn (array $row): float => (float) $row[$metric], $groupRows[$group]));
                $advantage = $group === 'BUY'
                    ? $universeRisk - $groupRisk
                    : $groupRisk - $universeRisk;
                $series[$group]['metrics'][$metric]['advantage'][] = ['date' => $date, 'value' => $advantage];
                $series[$group]['metrics'][$metric]['group_risk'][] = ['date' => $date, 'value' => $groupRisk];
                $series[$group]['metrics'][$metric]['universe_risk'][] = ['date' => $date, 'value' => $universeRisk];
                $groupMetrics[$metric] = [
                    'group_risk' => round($groupRisk, 6),
                    'universe_risk' => round($universeRisk, 6),
                    'risk_advantage' => round($advantage, 6),
                ];
            }
            $series[$group]['group_events'] += count($groupRows[$group]);
            $series[$group]['universe_events'] += count($rows);
            $month['groups'][$group] = [
                'events' => count($groupRows[$group]),
                'metrics' => $groupMetrics,
            ];
        }

        if ($month['groups'] !== []) {
            $monthly[] = $month;
        }
    }

    $lag = max(1, (int) ceil($horizon / 21));
    $results = [];
    foreach ($groups as $group) {
        $metricResults = [];
        foreach ($metrics as $metric) {
            $values = $series[$group]['metrics'][$metric];
            $metricResults[$metric] = [
                'risk_advantage' => rcDatedStats($values['advantage'], $lag),
                'risk_advantage_halves' => rcDatedHalves($values['advantage'], $lag),
                'group_risk_monthly' => rcDatedStats($values['group_risk'], $lag),
                'universe_risk_monthly' => rcDatedStats($values['universe_risk'], $lag),
            ];
        }
        $results[$group] = [
            'monthly_cohorts' => count($series[$group]['metrics']['max_adverse_excursion_close_pct']['advantage']),
            'group_events' => $series[$group]['group_events'],
            'universe_events_in_compared_months' => $series[$group]['universe_events'],
            'metrics' => $metricResults,
        ];
    }

    return [
        'calendar_months_with_at_least_one_eligible_group' => count($monthly),
        'hac_lag' => $lag,
        'purged_outcomes_crossing_discovery_end' => $purgedCrossingDiscoveryBoundary,
        'earliest_entry_date' => $earliestEntryDate,
        'latest_exit_date' => $latestExitDate,
        'groups' => $results,
        'monthly' => $monthly,
    ];
}

/**
 * Attributes the existing label-level MAE separation against a simple
 * volatility/ATR baseline. The audit period is calculated but never used to
 * choose a method or threshold.
 *
 * @param array<string,list<array<string,mixed>>> $byDate
 * @return array<string,mixed>
 */
function rcAnalyzeIncrementalRiskPeriod(array $byDate, int $horizon, string $period): array
{
    $groups = ['BUY', 'SELL_ANY'];
    $series = [];
    foreach ($groups as $group) {
        $series[$group] = [
            'label_events' => 0,
            'baseline_events' => 0,
            'labels_available_for_matching' => 0,
            'matched_pairs' => 0,
            'pairs_excluded_below_monthly_minimum' => 0,
            'selector' => [
                'label_risk' => [],
                'baseline_risk' => [],
                'universe_risk' => [],
                'label_advantage' => [],
                'baseline_advantage' => [],
                'label_minus_baseline_advantage' => [],
            ],
            'matching' => [
                'favorable_difference' => [],
                'label_risk' => [],
                'control_risk' => [],
                'signed_volatility_rank_difference' => [],
                'signed_atr_rank_difference' => [],
                'signed_baseline_rank_difference' => [],
                'absolute_volatility_rank_difference' => [],
                'absolute_atr_rank_difference' => [],
                'absolute_baseline_rank_difference' => [],
                'manhattan_component_rank_distance' => [],
            ],
        ];
    }

    $coverage = [
        'signal_months_inside_period' => 0,
        'rows_before_temporal_outcome_filter' => 0,
        'rows_after_temporal_outcome_filter' => 0,
        'rows_missing_volatility_or_atr' => 0,
        'complete_case_rows' => 0,
        'months_with_at_least_50_complete_cases' => 0,
        'purged_outcomes_crossing_discovery_end' => 0,
        'earliest_entry_date' => null,
        'latest_exit_date' => null,
    ];
    $globalMae = [];
    $monthly = [];
    ksort($byDate);

    foreach ($byDate as $date => $rows) {
        $inside = $period === 'discovery' ? $date <= RC_DISCOVERY_END : $date >= RC_VALIDATION_START;
        if (!$inside) {
            continue;
        }
        $coverage['signal_months_inside_period']++;
        $coverage['rows_before_temporal_outcome_filter'] += count($rows);

        if ($period === 'discovery') {
            $periodRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['exit_date'] <= RC_DISCOVERY_END
            ));
            $coverage['purged_outcomes_crossing_discovery_end'] += count($rows) - count($periodRows);
        } else {
            $periodRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['entry_date'] >= RC_VALIDATION_START
            ));
        }
        $coverage['rows_after_temporal_outcome_filter'] += count($periodRows);

        $completeRows = array_values(array_filter(
            $periodRows,
            static function (array $row): bool {
                $volatility = $row['factors']['low_volatility_20'] ?? null;
                $atr = $row['factors']['low_atr_14'] ?? null;

                return is_numeric($volatility)
                    && is_finite((float) $volatility)
                    && is_numeric($atr)
                    && is_finite((float) $atr);
            }
        ));
        $coverage['rows_missing_volatility_or_atr'] += count($periodRows) - count($completeRows);
        $coverage['complete_case_rows'] += count($completeRows);
        if (count($completeRows) < 50) {
            continue;
        }
        $coverage['months_with_at_least_50_complete_cases']++;

        $rankedRows = rcAddMonthlyRiskRanks($completeRows);
        foreach ($rankedRows as $row) {
            $globalMae[] = (float) $row['max_adverse_excursion_close_pct'];
            $entryDate = (string) $row['entry_date'];
            $exitDate = (string) $row['exit_date'];
            $coverage['earliest_entry_date'] = $coverage['earliest_entry_date'] === null
                ? $entryDate
                : min((string) $coverage['earliest_entry_date'], $entryDate);
            $coverage['latest_exit_date'] = $coverage['latest_exit_date'] === null
                ? $exitDate
                : max((string) $coverage['latest_exit_date'], $exitDate);
        }
        $universeRisk = rcMean(array_map(
            static fn (array $row): float => (float) $row['max_adverse_excursion_close_pct'],
            $rankedRows
        ));
        $month = [
            'date' => $date,
            'complete_case_events' => count($rankedRows),
            'universe_mae_close_pct' => round($universeRisk, 6),
            'groups' => [],
        ];

        foreach ($groups as $group) {
            $labelRows = array_values(array_filter(
                $rankedRows,
                static fn (array $row): bool => rcRowBelongsToRiskGroup($row, $group)
            ));
            $labelCount = count($labelRows);
            if ($labelCount < RC_MIN_GROUP_PER_MONTH) {
                continue;
            }

            $baselineRows = $rankedRows;
            usort($baselineRows, static function (array $left, array $right) use ($group): int {
                $comparison = ((float) $left['_baseline_risk_rank']) <=> ((float) $right['_baseline_risk_rank']);
                if ($group === 'SELL_ANY') {
                    $comparison *= -1;
                }

                return $comparison !== 0
                    ? $comparison
                    : strcmp((string) $left['ticker'], (string) $right['ticker']);
            });
            $baselineRows = array_slice($baselineRows, 0, $labelCount);
            $labelRisk = rcMean(array_map(
                static fn (array $row): float => (float) $row['max_adverse_excursion_close_pct'],
                $labelRows
            ));
            $baselineRisk = rcMean(array_map(
                static fn (array $row): float => (float) $row['max_adverse_excursion_close_pct'],
                $baselineRows
            ));
            $labelAdvantage = $group === 'BUY' ? $universeRisk - $labelRisk : $labelRisk - $universeRisk;
            $baselineAdvantage = $group === 'BUY' ? $universeRisk - $baselineRisk : $baselineRisk - $universeRisk;
            $incrementalAdvantage = $labelAdvantage - $baselineAdvantage;
            foreach ([
                'label_risk' => $labelRisk,
                'baseline_risk' => $baselineRisk,
                'universe_risk' => $universeRisk,
                'label_advantage' => $labelAdvantage,
                'baseline_advantage' => $baselineAdvantage,
                'label_minus_baseline_advantage' => $incrementalAdvantage,
            ] as $metric => $value) {
                $series[$group]['selector'][$metric][] = ['date' => $date, 'value' => $value];
            }
            $series[$group]['label_events'] += $labelCount;
            $series[$group]['baseline_events'] += count($baselineRows);
            $series[$group]['labels_available_for_matching'] += $labelCount;

            $groupMonth = [
                'label_events' => $labelCount,
                'same_size_baseline_events' => count($baselineRows),
                'same_size_selector' => [
                    'label_mae_close_pct' => round($labelRisk, 6),
                    'baseline_mae_close_pct' => round($baselineRisk, 6),
                    'label_risk_advantage' => round($labelAdvantage, 6),
                    'baseline_risk_advantage' => round($baselineAdvantage, 6),
                    'label_minus_baseline_advantage' => round($incrementalAdvantage, 6),
                ],
            ];

            $pairs = rcMatchRiskRowsWithinQuintiles($rankedRows, $group);
            if (count($pairs) < RC_MIN_GROUP_PER_MONTH) {
                $series[$group]['pairs_excluded_below_monthly_minimum'] += count($pairs);
                $groupMonth['conditional_matching'] = [
                    'included' => false,
                    'reason' => 'fewer_than_minimum_pairs',
                    'pairs_available' => count($pairs),
                ];
                $month['groups'][$group] = $groupMonth;
                continue;
            }

            $pairEffects = [];
            $labelPairRisks = [];
            $controlPairRisks = [];
            $monthBalance = [
                'signed_volatility_rank_difference' => [],
                'signed_atr_rank_difference' => [],
                'signed_baseline_rank_difference' => [],
                'absolute_volatility_rank_difference' => [],
                'absolute_atr_rank_difference' => [],
                'absolute_baseline_rank_difference' => [],
                'manhattan_component_rank_distance' => [],
            ];
            foreach ($pairs as $pair) {
                $labelMae = (float) $pair['label']['max_adverse_excursion_close_pct'];
                $controlMae = (float) $pair['control']['max_adverse_excursion_close_pct'];
                $pairEffects[] = $group === 'BUY' ? $controlMae - $labelMae : $labelMae - $controlMae;
                $labelPairRisks[] = $labelMae;
                $controlPairRisks[] = $controlMae;
                foreach (array_keys($monthBalance) as $balanceMetric) {
                    $balanceValue = (float) $pair[$balanceMetric];
                    $monthBalance[$balanceMetric][] = $balanceValue;
                    $series[$group]['matching'][$balanceMetric][] = $balanceValue;
                }
            }
            $favorableDifference = rcMean($pairEffects);
            $matchedLabelRisk = rcMean($labelPairRisks);
            $matchedControlRisk = rcMean($controlPairRisks);
            foreach ([
                'favorable_difference' => $favorableDifference,
                'label_risk' => $matchedLabelRisk,
                'control_risk' => $matchedControlRisk,
            ] as $metric => $value) {
                $series[$group]['matching'][$metric][] = ['date' => $date, 'value' => $value];
            }
            $series[$group]['matched_pairs'] += count($pairs);
            $groupMonth['conditional_matching'] = [
                'included' => true,
                'pairs' => count($pairs),
                'retained_label_pct' => round(count($pairs) / $labelCount * 100.0, 6),
                'label_mae_close_pct' => round($matchedLabelRisk, 6),
                'control_mae_close_pct' => round($matchedControlRisk, 6),
                'favorable_mae_difference_pct' => round($favorableDifference, 6),
                'balance' => array_map(
                    static fn (array $values): float => round(rcMean($values), 6),
                    $monthBalance
                ),
            ];
            $month['groups'][$group] = $groupMonth;
        }

        if ($month['groups'] !== []) {
            $monthly[] = $month;
        }
    }

    $lag = max(1, (int) ceil($horizon / 21));
    $globalMaeIqr = rcIqr($globalMae);
    $results = [];
    foreach ($groups as $group) {
        $selector = $series[$group]['selector'];
        $matching = $series[$group]['matching'];
        $labelsAvailable = (int) $series[$group]['labels_available_for_matching'];
        $matchedPairs = (int) $series[$group]['matched_pairs'];
        $results[$group] = [
            'same_size_selector' => [
                'monthly_cohorts' => count($selector['label_minus_baseline_advantage']),
                'label_events' => $series[$group]['label_events'],
                'baseline_events' => $series[$group]['baseline_events'],
                'label_mae_close_pct' => rcDatedStats($selector['label_risk'], $lag),
                'baseline_mae_close_pct' => rcDatedStats($selector['baseline_risk'], $lag),
                'universe_mae_close_pct' => rcDatedStats($selector['universe_risk'], $lag),
                'label_risk_advantage' => rcDatedStats($selector['label_advantage'], $lag),
                'baseline_risk_advantage' => rcDatedStats($selector['baseline_advantage'], $lag),
                'label_minus_baseline_advantage' => rcDatedStats($selector['label_minus_baseline_advantage'], $lag),
                'label_minus_baseline_advantage_halves' => rcDatedHalves($selector['label_minus_baseline_advantage'], $lag),
            ],
            'conditional_matching' => [
                'monthly_cohorts' => count($matching['favorable_difference']),
                'labels_available' => $labelsAvailable,
                'matched_pairs_in_included_months' => $matchedPairs,
                'pairs_excluded_in_months_below_minimum' => $series[$group]['pairs_excluded_below_monthly_minimum'],
                'retained_label_pct' => $labelsAvailable > 0
                    ? round($matchedPairs / $labelsAvailable * 100.0, 6)
                    : null,
                'favorable_mae_difference' => rcDatedStats($matching['favorable_difference'], $lag),
                'favorable_mae_difference_halves' => rcDatedHalves($matching['favorable_difference'], $lag),
                'matched_label_mae_close_pct' => rcDatedStats($matching['label_risk'], $lag),
                'matched_control_mae_close_pct' => rcDatedStats($matching['control_risk'], $lag),
                'aggregate_pair_weighted_balance' => [
                    'signed_volatility_rank_difference' => rcPlainMean($matching['signed_volatility_rank_difference']),
                    'signed_atr_rank_difference' => rcPlainMean($matching['signed_atr_rank_difference']),
                    'signed_baseline_rank_difference' => rcPlainMean($matching['signed_baseline_rank_difference']),
                    'absolute_volatility_rank_difference' => rcPlainMean($matching['absolute_volatility_rank_difference']),
                    'absolute_atr_rank_difference' => rcPlainMean($matching['absolute_atr_rank_difference']),
                    'absolute_baseline_rank_difference' => rcPlainMean($matching['absolute_baseline_rank_difference']),
                    'manhattan_component_rank_distance' => rcPlainMean($matching['manhattan_component_rank_distance']),
                ],
            ],
        ];
    }

    $coverage['complete_case_pct_after_temporal_filter'] = $coverage['rows_after_temporal_outcome_filter'] > 0
        ? round($coverage['complete_case_rows'] / $coverage['rows_after_temporal_outcome_filter'] * 100.0, 6)
        : null;

    return [
        'period' => $period,
        'hac_lag' => $lag,
        'global_complete_case_mae_iqr_pct' => $globalMaeIqr === null ? null : round($globalMaeIqr, 6),
        'coverage' => $coverage,
        'groups' => $results,
        'monthly' => $monthly,
    ];
}

/** @param array<string,mixed> $row */
function rcRowBelongsToRiskGroup(array $row, string $group): bool
{
    $recommendation = (string) $row['recommendation'];

    return $group === 'BUY'
        ? $recommendation === 'BUY'
        : in_array($recommendation, ['SELL', 'STRONG SELL'], true);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function rcAddMonthlyRiskRanks(array $rows): array
{
    foreach ($rows as &$row) {
        $row['_volatility_raw'] = -(float) $row['factors']['low_volatility_20'];
        $row['_atr_pct_raw'] = -(float) $row['factors']['low_atr_14'];
    }
    unset($row);
    $volatilityRanks = rcPercentileRanksForRows($rows, '_volatility_raw');
    $atrRanks = rcPercentileRanksForRows($rows, '_atr_pct_raw');
    foreach ($rows as $index => &$row) {
        $row['_volatility_risk_rank'] = $volatilityRanks[$index];
        $row['_atr_risk_rank'] = $atrRanks[$index];
        $row['_baseline_risk_rank'] = ($volatilityRanks[$index] + $atrRanks[$index]) / 2.0;
    }
    unset($row);

    $order = array_keys($rows);
    usort($order, static function (int $left, int $right) use ($rows): int {
        $comparison = ((float) $rows[$left]['_baseline_risk_rank']) <=> ((float) $rows[$right]['_baseline_risk_rank']);

        return $comparison !== 0
            ? $comparison
            : strcmp((string) $rows[$left]['ticker'], (string) $rows[$right]['ticker']);
    });
    $count = count($rows);
    foreach ($order as $position => $index) {
        $rows[$index]['_baseline_risk_quintile'] = min(5, (int) floor($position * 5 / $count) + 1);
    }

    return $rows;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<float>
 */
function rcPercentileRanksForRows(array $rows, string $valueKey): array
{
    $ordered = [];
    foreach ($rows as $index => $row) {
        $ordered[] = [
            'index' => $index,
            'value' => (float) $row[$valueKey],
            'ticker' => (string) $row['ticker'],
        ];
    }
    usort($ordered, static function (array $left, array $right): int {
        $comparison = ((float) $left['value']) <=> ((float) $right['value']);

        return $comparison !== 0
            ? $comparison
            : strcmp((string) $left['ticker'], (string) $right['ticker']);
    });
    $count = count($ordered);
    $ranks = [];
    $start = 0;
    while ($start < $count) {
        $end = $start;
        while ($end + 1 < $count && (float) $ordered[$end + 1]['value'] === (float) $ordered[$start]['value']) {
            $end++;
        }
        $averagePosition = ($start + $end) / 2.0;
        $percentile = $count > 1 ? $averagePosition / ($count - 1) : 0.5;
        for ($position = $start; $position <= $end; $position++) {
            $ranks[(int) $ordered[$position]['index']] = $percentile;
        }
        $start = $end + 1;
    }
    ksort($ranks);

    return array_values($ranks);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{label:array<string,mixed>,control:array<string,mixed>,signed_volatility_rank_difference:float,signed_atr_rank_difference:float,signed_baseline_rank_difference:float,absolute_volatility_rank_difference:float,absolute_atr_rank_difference:float,absolute_baseline_rank_difference:float,manhattan_component_rank_distance:float}>
 */
function rcMatchRiskRowsWithinQuintiles(array $rows, string $group): array
{
    $strata = [];
    for ($quintile = 1; $quintile <= 5; $quintile++) {
        $strata[$quintile] = ['label' => [], 'control' => []];
    }
    foreach ($rows as $row) {
        $quintile = (int) $row['_baseline_risk_quintile'];
        $side = rcRowBelongsToRiskGroup($row, $group) ? 'label' : 'control';
        $strata[$quintile][$side][] = $row;
    }

    $matches = [];
    foreach ($strata as $stratum) {
        $candidates = [];
        foreach ($stratum['label'] as $label) {
            foreach ($stratum['control'] as $control) {
                $volatilityDifference = (float) $label['_volatility_risk_rank'] - (float) $control['_volatility_risk_rank'];
                $atrDifference = (float) $label['_atr_risk_rank'] - (float) $control['_atr_risk_rank'];
                $baselineDifference = (float) $label['_baseline_risk_rank'] - (float) $control['_baseline_risk_rank'];
                $candidates[] = [
                    'label' => $label,
                    'control' => $control,
                    'signed_volatility_rank_difference' => $volatilityDifference,
                    'signed_atr_rank_difference' => $atrDifference,
                    'signed_baseline_rank_difference' => $baselineDifference,
                    'absolute_volatility_rank_difference' => abs($volatilityDifference),
                    'absolute_atr_rank_difference' => abs($atrDifference),
                    'absolute_baseline_rank_difference' => abs($baselineDifference),
                    'manhattan_component_rank_distance' => abs($volatilityDifference) + abs($atrDifference),
                ];
            }
        }
        usort($candidates, static function (array $left, array $right): int {
            foreach (['manhattan_component_rank_distance', 'absolute_baseline_rank_difference'] as $metric) {
                $comparison = ((float) $left[$metric]) <=> ((float) $right[$metric]);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            $comparison = strcmp((string) $left['label']['ticker'], (string) $right['label']['ticker']);

            return $comparison !== 0
                ? $comparison
                : strcmp((string) $left['control']['ticker'], (string) $right['control']['ticker']);
        });
        $usedLabels = [];
        $usedControls = [];
        foreach ($candidates as $candidate) {
            $labelTicker = (string) $candidate['label']['ticker'];
            $controlTicker = (string) $candidate['control']['ticker'];
            if (isset($usedLabels[$labelTicker]) || isset($usedControls[$controlTicker])) {
                continue;
            }
            $usedLabels[$labelTicker] = true;
            $usedControls[$controlTicker] = true;
            $matches[] = $candidate;
        }
    }

    return $matches;
}

/** @param list<float> $values */
function rcPlainMean(array $values): ?float
{
    return $values === [] ? null : round(rcMean($values), 6);
}

/** @param list<float> $values */
function rcIqr(array $values): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values, SORT_NUMERIC);

    return rcQuantile($values, 0.75) - rcQuantile($values, 0.25);
}

/** @param list<float> $sortedValues */
function rcQuantile(array $sortedValues, float $probability): float
{
    $position = $probability * (count($sortedValues) - 1);
    $lower = (int) floor($position);
    $upper = (int) ceil($position);
    if ($lower === $upper) {
        return $sortedValues[$lower];
    }
    $weight = $position - $lower;

    return $sortedValues[$lower] * (1.0 - $weight) + $sortedValues[$upper] * $weight;
}

/**
 * Applies only the exploratory classification frozen in P4. Audit results are
 * copied for context but cannot alter the classification.
 *
 * @param array<string,mixed> $periodResults
 * @return array<string,mixed>
 */
function rcAssessIncrementalRiskEvidence(array $periodResults): array
{
    $assessment = [];
    foreach (['BUY', 'SELL_ANY'] as $group) {
        $discovery = $periodResults['discovery']['groups'][$group];
        $audit = $periodResults['audit_descriptive_only']['groups'][$group];
        $discoveryEffect = $discovery['conditional_matching']['favorable_mae_difference'];
        $auditEffect = $audit['conditional_matching']['favorable_mae_difference'];
        $iqr = $periodResults['discovery']['global_complete_case_mae_iqr_pct'];
        $mean = $discoveryEffect['mean'];
        $normalized = is_numeric($mean) && is_numeric($iqr) && (float) $iqr > 0.0
            ? (float) $mean / (float) $iqr
            : null;
        $checks = [
            'at_least_12_discovery_months' => ($discoveryEffect['n'] ?? 0) >= RC_INCREMENTAL_MIN_MONTHS,
            'at_least_60_pct_discovery_months_favorable' => ($discoveryEffect['positive_pct'] ?? -INF) >= RC_INCREMENTAL_MIN_POSITIVE_MONTHS_PCT,
            'discovery_mean_divided_by_global_mae_iqr_at_least_0_10' => $normalized !== null
                && $normalized >= RC_INCREMENTAL_MIN_NORMALIZED_EFFECT,
        ];
        $selectorMean = $discovery['same_size_selector']['label_minus_baseline_advantage']['mean'];
        $assessment[$group] = [
            'classification_partition' => 'discovery_only_exit_through_2020_12_31',
            'global_discovery_mae_iqr_pct' => $iqr,
            'discovery_conditional_mean_effect_pct' => $mean,
            'discovery_normalized_effect_over_global_mae_iqr' => $normalized === null ? null : round($normalized, 6),
            'checks' => $checks,
            'classification' => !in_array(false, $checks, true)
                ? 'incremental_promising_exploratory'
                : 'not_incremental_promising',
            'same_size_selector_discovery_winner' => !is_numeric($selectorMean)
                ? 'unavailable'
                : ((float) $selectorMean > 0.0 ? 'label' : ((float) $selectorMean < 0.0 ? 'volatility_atr_baseline' : 'tie')),
            'same_size_selector_label_minus_baseline_advantage_pct' => $selectorMean,
            'audit_descriptive_only' => [
                'conditional_mean_effect_pct' => $auditEffect['mean'],
                'conditional_positive_months_pct' => $auditEffect['positive_pct'],
                'same_size_selector_label_minus_baseline_advantage_pct' => $audit['same_size_selector']['label_minus_baseline_advantage']['mean'],
            ],
            'production_action' => 'NONE; no label or weight change.',
        ];
    }

    return [
        'groups' => $assessment,
        'classification_uses_audit' => false,
        'audit_is_descriptive_only' => true,
        'production_readiness_established' => false,
    ];
}

/**
 * Newey-West summary that preserves missing calendar months instead of compressing
 * the remaining observations into an artificial consecutive series.
 *
 * @param list<array{date:string,value:float}> $observations
 * @return array{n:int,mean:?float,newey_west_t:?float,one_sided_p_positive_normal_approx:?float,positive_pct:?float,date_start:?string,date_end:?string}
 */
function rcDatedStats(array $observations, int $lag): array
{
    usort($observations, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
    $n = count($observations);
    if ($n === 0) {
        return [
            'n' => 0,
            'mean' => null,
            'newey_west_t' => null,
            'one_sided_p_positive_normal_approx' => null,
            'positive_pct' => null,
            'date_start' => null,
            'date_end' => null,
        ];
    }

    $values = array_map(static fn (array $observation): float => $observation['value'], $observations);
    $mean = rcMean($values);
    $residuals = array_map(static fn (float $value): float => $value - $mean, $values);
    $longRunVariance = array_sum(array_map(
        static fn (float $residual): float => $residual ** 2,
        $residuals
    )) / $n;

    for ($offset = 1; $offset <= $lag; $offset++) {
        $covarianceSum = 0.0;
        for ($later = 1; $later < $n; $later++) {
            for ($earlier = 0; $earlier < $later; $earlier++) {
                if (rcCalendarMonthDistance($observations[$earlier]['date'], $observations[$later]['date']) === $offset) {
                    $covarianceSum += $residuals[$later] * $residuals[$earlier];
                }
            }
        }
        $longRunVariance += 2.0 * (1.0 - $offset / ($lag + 1)) * ($covarianceSum / $n);
    }

    $standardError = sqrt(max(0.0, $longRunVariance) / $n);
    $statistic = $standardError > 0.0 ? $mean / $standardError : null;
    $positive = count(array_filter($values, static fn (float $value): bool => $value > 0.0));

    return [
        'n' => $n,
        'mean' => round($mean, 6),
        'newey_west_t' => $statistic === null ? null : round($statistic, 6),
        'one_sided_p_positive_normal_approx' => $statistic === null
            ? null
            : round(rcNormalSurvival($statistic), 12),
        'positive_pct' => round($positive / $n * 100.0, 6),
        'date_start' => $observations[0]['date'],
        'date_end' => $observations[$n - 1]['date'],
    ];
}

/**
 * @param list<array{date:string,value:float}> $observations
 * @return array{first:array<string,mixed>,second:array<string,mixed>}
 */
function rcDatedHalves(array $observations, int $lag): array
{
    usort($observations, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
    $middle = (int) floor(count($observations) / 2);

    return [
        'first' => rcDatedStats(array_slice($observations, 0, $middle), $lag),
        'second' => rcDatedStats(array_slice($observations, $middle), $lag),
    ];
}

function rcCalendarMonthDistance(string $earlier, string $later): int
{
    $earlierIndex = ((int) substr($earlier, 0, 4)) * 12 + (int) substr($earlier, 5, 2);
    $laterIndex = ((int) substr($later, 0, 4)) * 12 + (int) substr($later, 5, 2);

    return $laterIndex - $earlierIndex;
}

function rcNormalSurvival(float $statistic): float
{
    $absolute = abs($statistic);
    $tailScale = 1.0 / (1.0 + 0.2316419 * $absolute);
    $density = 0.3989422804014327 * exp(-0.5 * $absolute * $absolute);
    $tail = $density * $tailScale * (
        0.319381530
        + $tailScale * (
            -0.356563782
            + $tailScale * (
                1.781477937
                + $tailScale * (-1.821255978 + $tailScale * 1.330274429)
            )
        )
    );
    $survival = $statistic >= 0.0 ? $tail : 1.0 - $tail;

    return max(0.0, min(1.0, $survival));
}

/**
 * @param array<string,float|null> $pValues
 * @return array<string,float>
 */
function rcHolmAdjusted(array $pValues): array
{
    $normalized = [];
    foreach ($pValues as $key => $value) {
        $normalized[$key] = is_float($value) && is_finite($value) ? $value : 1.0;
    }
    asort($normalized, SORT_NUMERIC);
    $count = count($normalized);
    $previous = 0.0;
    $adjusted = [];
    $rank = 0;
    foreach ($normalized as $key => $value) {
        $candidate = min(1.0, ($count - $rank) * $value);
        $previous = max($previous, $candidate);
        $adjusted[$key] = $previous;
        $rank++;
    }
    ksort($adjusted);

    return $adjusted;
}

/**
 * Applies only the protocol frozen before execution. It does not alter labels or weights.
 *
 * @param array<string,mixed> $riskResults
 * @return array<string,mixed>
 */
function rcAssessRiskEvidence(array $riskResults): array
{
    $groups = ['BUY', 'SELL_ANY'];
    $primaryMetric = 'max_adverse_excursion_close_pct';
    $secondaryMinimums = [
        'final_loss_10_close_rate_pct' => 1.0,
        'final_loss_20_close_rate_pct' => 0.5,
    ];
    $primaryPValues = [];
    $secondaryPValues = [];
    foreach ($groups as $group) {
        $primaryPValues[$group] = $riskResults['audit']['groups'][$group]['metrics'][$primaryMetric]['risk_advantage']['one_sided_p_positive_normal_approx'] ?? null;
        foreach ($secondaryMinimums as $metric => $_minimum) {
            $key = $group . ':' . $metric;
            $secondaryPValues[$key] = $riskResults['audit']['groups'][$group]['metrics'][$metric]['risk_advantage']['one_sided_p_positive_normal_approx'] ?? null;
        }
    }
    $primaryAdjusted = rcHolmAdjusted($primaryPValues);
    $secondaryAdjusted = rcHolmAdjusted($secondaryPValues);

    $primary = [];
    foreach ($groups as $group) {
        $discovery = $riskResults['discovery']['groups'][$group]['metrics'][$primaryMetric];
        $audit = $riskResults['audit']['groups'][$group]['metrics'][$primaryMetric];
        $checks = [
            'minimum_audit_months' => ($audit['risk_advantage']['n'] ?? 0) >= RC_RISK_MIN_AUDIT_MONTHS,
            'discovery_mean_at_least_0_50pp' => ($discovery['risk_advantage']['mean'] ?? -INF) >= 0.5,
            'audit_mean_at_least_0_50pp' => ($audit['risk_advantage']['mean'] ?? -INF) >= 0.5,
            'discovery_first_half_positive' => ($discovery['risk_advantage_halves']['first']['mean'] ?? -INF) > 0.0,
            'discovery_second_half_positive' => ($discovery['risk_advantage_halves']['second']['mean'] ?? -INF) > 0.0,
            'audit_first_half_positive' => ($audit['risk_advantage_halves']['first']['mean'] ?? -INF) > 0.0,
            'audit_second_half_positive' => ($audit['risk_advantage_halves']['second']['mean'] ?? -INF) > 0.0,
            'audit_holm_one_sided_p_at_most_0_05' => ($primaryAdjusted[$group] ?? 1.0) <= RC_RISK_ONE_SIDED_ALPHA,
        ];
        $primary[$group] = [
            'raw_audit_one_sided_p_normal_approx' => $primaryPValues[$group],
            'holm_adjusted_audit_p' => round($primaryAdjusted[$group] ?? 1.0, 8),
            'checks' => $checks,
            'passes_predeclared_primary_rule' => !in_array(false, $checks, true),
        ];
    }

    $secondary = [];
    foreach ($groups as $group) {
        foreach ($secondaryMinimums as $metric => $minimum) {
            $key = $group . ':' . $metric;
            $discovery = $riskResults['discovery']['groups'][$group]['metrics'][$metric];
            $audit = $riskResults['audit']['groups'][$group]['metrics'][$metric];
            $checks = [
                'minimum_audit_months' => ($audit['risk_advantage']['n'] ?? 0) >= RC_RISK_MIN_AUDIT_MONTHS,
                'discovery_mean_at_least_materiality' => ($discovery['risk_advantage']['mean'] ?? -INF) >= $minimum,
                'audit_mean_at_least_materiality' => ($audit['risk_advantage']['mean'] ?? -INF) >= $minimum,
                'discovery_halves_positive' => ($discovery['risk_advantage_halves']['first']['mean'] ?? -INF) > 0.0
                    && ($discovery['risk_advantage_halves']['second']['mean'] ?? -INF) > 0.0,
                'audit_halves_positive' => ($audit['risk_advantage_halves']['first']['mean'] ?? -INF) > 0.0
                    && ($audit['risk_advantage_halves']['second']['mean'] ?? -INF) > 0.0,
                'audit_holm_one_sided_p_at_most_0_05' => ($secondaryAdjusted[$key] ?? 1.0) <= RC_RISK_ONE_SIDED_ALPHA,
            ];
            $secondary[$key] = [
                'materiality_pp' => $minimum,
                'raw_audit_one_sided_p_normal_approx' => $secondaryPValues[$key],
                'holm_adjusted_audit_p' => round($secondaryAdjusted[$key] ?? 1.0, 8),
                'checks' => $checks,
                'passes_predeclared_secondary_rule' => !in_array(false, $checks, true),
            ];
        }
    }

    $primaryPasses = array_map(
        static fn (array $assessment): bool => $assessment['passes_predeclared_primary_rule'],
        $primary
    );
    $secondaryAuditNonNegative = true;
    foreach ($groups as $group) {
        foreach (array_keys($secondaryMinimums) as $metric) {
            if (($riskResults['audit']['groups'][$group]['metrics'][$metric]['risk_advantage']['mean'] ?? -INF) < 0.0) {
                $secondaryAuditNonNegative = false;
            }
        }
    }

    return [
        'primary' => $primary,
        'secondary' => $secondary,
        'both_primary_contrasts_pass' => !in_array(false, $primaryPasses, true),
        'all_secondary_audit_effects_non_negative' => $secondaryAuditNonNegative,
        'passes_predeclared_statistical_protocol' => !in_array(false, $primaryPasses, true) && $secondaryAuditNonNegative,
        'production_readiness_established' => false,
        'production_action' => 'NONE; this research result never changes current labels or weights automatically.',
    ];
}

/**
 * @param array<string,list<array{ticker:string,recommendation:string,score:float,alpha:float,stock_return:float}>> $byDate
 * @return array<string,mixed>
 */
function rcAnalyzePeriod(array $byDate, int $horizon, string $period): array
{
    $monthly = [];
    $events = [];
    $top10Edge = [];
    $top10GrossAlpha = [];
    $top10NetAlpha = [];
    $bottom10Avoidance = [];
    $topBottomSeparation = [];
    foreach ($byDate as $date => $rows) {
        $inside = $period === 'discovery' ? $date <= RC_DISCOVERY_END : $date >= RC_VALIDATION_START;
        if (!$inside || count($rows) < 50) {
            continue;
        }
        $groups = ['BUY' => [], 'HOLD' => [], 'SELL' => [], 'STRONG SELL' => [], 'SELL_ANY' => []];
        foreach ($rows as $row) {
            $groups[$row['recommendation']][] = $row;
            if (in_array($row['recommendation'], ['SELL', 'STRONG SELL'], true)) {
                $groups['SELL_ANY'][] = $row;
            }
        }
        $universeMean = rcMean(array_column($rows, 'alpha'));
        $month = ['date' => $date, 'universe_events' => count($rows), 'groups' => []];

        usort($rows, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $topRows = array_slice($rows, 0, RC_TOP_N);
        $bottomRows = array_slice($rows, -RC_TOP_N);
        $topMean = rcMean(array_column($topRows, 'alpha'));
        $bottomMean = rcMean(array_column($bottomRows, 'alpha'));
        $rankMetrics = [
            'top_tickers' => array_column($topRows, 'ticker'),
            'top_mean_score' => round(rcMean(array_column($topRows, 'score')), 6),
            'edge_vs_universe' => $topMean - $universeMean,
            'gross_alpha_vs_spy' => $topMean,
            'net_alpha_vs_spy' => $topMean - RC_ROUND_TRIP_COST_PCT,
            'bottom_10_avoidance' => $universeMean - $bottomMean,
            'top_minus_bottom' => $topMean - $bottomMean,
        ];
        $month['fixed_top_10'] = array_map(
            static fn (mixed $value): mixed => is_float($value) ? round($value, 6) : $value,
            $rankMetrics
        );
        $top10Edge[] = $rankMetrics['edge_vs_universe'];
        $top10GrossAlpha[] = $rankMetrics['gross_alpha_vs_spy'];
        $top10NetAlpha[] = $rankMetrics['net_alpha_vs_spy'];
        $bottom10Avoidance[] = $rankMetrics['bottom_10_avoidance'];
        $topBottomSeparation[] = $rankMetrics['top_minus_bottom'];
        foreach ($groups as $group => $groupRows) {
            if (count($groupRows) < RC_MIN_GROUP_PER_MONTH) {
                continue;
            }
            $groupMean = rcMean(array_column($groupRows, 'alpha'));
            $edge = in_array($group, ['SELL', 'STRONG SELL', 'SELL_ANY'], true)
                ? $universeMean - $groupMean
                : $groupMean - $universeMean;
            $month['groups'][$group] = ['events' => count($groupRows), 'edge' => round($edge, 6)];
            foreach ($groupRows as $row) {
                $events[$group][] = $row;
            }
        }
        $monthly[] = $month;
    }
    $lag = max(1, (int) ceil($horizon / 21));
    $groupResults = [];
    foreach (['BUY', 'HOLD', 'SELL', 'STRONG SELL', 'SELL_ANY'] as $group) {
        $series = [];
        foreach ($monthly as $month) {
            if (isset($month['groups'][$group])) {
                $series[] = (float) $month['groups'][$group]['edge'];
            }
        }
        $groupResults[$group] = [
            'edge' => rcStats($series, $lag),
            'events' => rcEventCalibration($events[$group] ?? [], $group),
        ];
    }
    $separation = [];
    foreach ($monthly as $month) {
        if (isset($month['groups']['BUY'], $month['groups']['SELL_ANY'])) {
            $separation[] = (float) $month['groups']['BUY']['edge'] + (float) $month['groups']['SELL_ANY']['edge'];
        }
    }
    return [
        'months' => count($monthly),
        'groups' => $groupResults,
        'buy_minus_sell_edge' => rcStats($separation, $lag),
        'fixed_top_10' => [
            'top_n' => RC_TOP_N,
            'round_trip_cost_pct' => RC_ROUND_TRIP_COST_PCT,
            'edge_vs_universe' => rcStats($top10Edge, $lag),
            'edge_vs_universe_halves' => rcHalves($top10Edge, $lag),
            'gross_alpha_vs_spy' => rcStats($top10GrossAlpha, $lag),
            'net_alpha_vs_spy' => rcStats($top10NetAlpha, $lag),
            'bottom_10_avoidance' => rcStats($bottom10Avoidance, $lag),
            'top_minus_bottom' => rcStats($topBottomSeparation, $lag),
        ],
        'monthly' => $monthly,
    ];
}

/**
 * @param array<string,list<array<string,mixed>>> $byDate
 * @param list<string> $factors
 * @return array<string,array<string,mixed>>
 */
function rcAnalyzeComponents(array $byDate, int $horizon, array $factors, string $period): array
{
    $series = [];
    foreach ($factors as $factor) {
        $series[$factor] = ['edge' => [], 'spread' => [], 'gross_alpha' => [], 'months' => 0, 'events' => 0];
    }

    foreach ($byDate as $date => $rows) {
        $inside = $period === 'discovery' ? $date <= RC_DISCOVERY_END : $date >= RC_VALIDATION_START;
        if (!$inside || count($rows) < 50) {
            continue;
        }

        foreach ($factors as $factor) {
            $eligible = array_values(array_filter(
                $rows,
                static fn (array $row): bool => isset($row['factors'][$factor]) && is_numeric($row['factors'][$factor])
            ));
            if (count($eligible) < 50) {
                continue;
            }
            usort($eligible, static function (array $a, array $b) use ($factor): int {
                $comparison = ((float) $b['factors'][$factor]) <=> ((float) $a['factors'][$factor]);
                return $comparison !== 0 ? $comparison : strcmp((string) $a['ticker'], (string) $b['ticker']);
            });
            $quintileSize = max(10, (int) floor(count($eligible) * 0.20));
            $top = array_slice($eligible, 0, $quintileSize);
            $bottom = array_slice($eligible, -$quintileSize);
            $universeMean = rcMean(array_map(static fn (array $row): float => (float) $row['alpha'], $eligible));
            $topMean = rcMean(array_map(static fn (array $row): float => (float) $row['alpha'], $top));
            $bottomMean = rcMean(array_map(static fn (array $row): float => (float) $row['alpha'], $bottom));
            $series[$factor]['edge'][] = $topMean - $universeMean;
            $series[$factor]['spread'][] = $topMean - $bottomMean;
            $series[$factor]['gross_alpha'][] = $topMean;
            $series[$factor]['months']++;
            $series[$factor]['events'] += count($top);
        }
    }

    $lag = max(1, (int) ceil($horizon / 21));
    $results = [];
    foreach ($series as $factor => $values) {
        $results[$factor] = [
            'months' => $values['months'],
            'top_quintile_events' => $values['events'],
            'top_quintile_edge_vs_universe' => rcStats($values['edge'], $lag),
            'edge_halves' => rcHalves($values['edge'], $lag),
            'top_minus_bottom' => rcStats($values['spread'], $lag),
            'gross_alpha_vs_spy' => rcStats($values['gross_alpha'], $lag),
        ];
    }

    return $results;
}

/** @param list<float> $values @return array{first:array<string,mixed>,second:array<string,mixed>} */
function rcHalves(array $values, int $lag): array
{
    $middle = (int) floor(count($values) / 2);

    return [
        'first' => rcStats(array_slice($values, 0, $middle), $lag),
        'second' => rcStats(array_slice($values, $middle), $lag),
    ];
}

/** @param list<array{ticker:string,recommendation:string,score:float,alpha:float,stock_return:float}> $rows @return array<string,mixed> */
function rcEventCalibration(array $rows, string $group): array
{
    if ($rows === []) {
        return ['events' => 0];
    }
    $correct = null;
    if ($group !== 'HOLD') {
        $correct = 0;
        foreach ($rows as $row) {
            $correct += in_array($group, ['SELL', 'STRONG SELL', 'SELL_ANY'], true)
                ? ($row['alpha'] < 0.0 ? 1 : 0)
                : ($row['alpha'] > 0.0 ? 1 : 0);
        }
    }
    return [
        'events' => count($rows),
        'mean_score' => round(rcMean(array_column($rows, 'score')), 6),
        'mean_alpha_pct' => round(rcMean(array_column($rows, 'alpha')), 6),
        'mean_stock_return_pct' => round(rcMean(array_column($rows, 'stock_return')), 6),
        'direction_correct_pct' => $correct === null ? null : round($correct / count($rows) * 100.0, 6),
    ];
}

/** @param list<float> $values @return array{n:int,mean:?float,newey_west_t:?float,positive_pct:?float} */
function rcStats(array $values, int $lag): array
{
    $n = count($values);
    if ($n === 0) {
        return ['n' => 0, 'mean' => null, 'newey_west_t' => null, 'positive_pct' => null];
    }
    $mean = rcMean($values);
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
function rcMean(array $values): float
{
    return array_sum($values) / count($values);
}
