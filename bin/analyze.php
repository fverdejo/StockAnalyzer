<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Analyzer\NewsAnalyzer;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Repository\DailyRankingRepository;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Repository\NewsRepository;
use StockAnalyzer\Services\AnalysisJsonPresenter;
use StockAnalyzer\Services\RiskLevelsCalculator;
use StockAnalyzer\Services\StockAnalysisService;
use StockAnalyzer\Utils\TickerNormalizer;

$options = getopt('', ['universe::', 'tickers::', 'name::']);
$universeKey = is_string($options['universe'] ?? null) ? (string) $options['universe'] : 'largecap60';
$name = is_string($options['name'] ?? null) ? (string) $options['name'] : $universeKey;

$connection = new Connection();
$universes = new UniverseConfig();
$normalizer = new TickerNormalizer();
$rawTickers = is_string($options['tickers'] ?? null)
    ? (string) $options['tickers']
    : implode(' ', $universes->tickers($universeKey));

$tickers = $normalizer->normalize($rawTickers);

if ($tickers === []) {
    fwrite(STDERR, "No tickers to analyze.\n");
    exit(1);
}

$providerConfig = new ProviderConfig();
$provider = new CachedMarketDataProvider(new YahooFinanceProvider(), new MarketDataCacheRepository($connection));
$weights = new ScoreWeights();
$scoreCalculator = new ScoreCalculator($weights, new NewsAnalyzer(new NewsRepository($connection), $weights));
$service = new StockAnalysisService(
    $provider,
    $scoreCalculator,
    new TechnicalAnalyzer(),
    new RiskLevelsCalculator(new RiskLevelsConfig())
);
$presenter = new AnalysisJsonPresenter();
$results = [];
$errors = [];

// Snapshot diario de fundamentales (ver versions.md v2.74). Se siembra
// tambien desde aqui, y no solo desde la ficha de detalle, porque este CLI
// recorre un universo entero por ejecucion: es la unica via de acumular
// cobertura real en vez de depender de que alguien abra cada ficha.
$fundamentalsHistory = new FundamentalsHistoryRepository($connection);

foreach ($tickers as $ticker) {
    try {
        $analysis = $service->analyze($ticker);
        $results[] = $analysis;

        try {
            $fundamentalsHistory->recordSnapshot($ticker, $analysis->getStock()->getFundamentals());
        } catch (Throwable $snapshotError) {
            // Captura de historial "best effort", mismo criterio que en
            // Application::renderDetail(): que falle no debe tumbar el
            // ranking, que es lo que este comando viene a producir.
            echo "WARN snapshot {$ticker}: {$snapshotError->getMessage()}\n";
        }

        echo "OK {$ticker}\n";
    } catch (Throwable $exception) {
        $errors[$ticker] = $exception->getMessage();
        echo "ERROR {$ticker}: {$exception->getMessage()}\n";
    }
}

usort(
    $results,
    static fn ($left, $right): int => $right->getScore()->getPercentage() <=> $left->getScore()->getPercentage()
);

$payload = $presenter->ranking($results, $errors, $universeKey, implode(' ', $tickers));
(new DailyRankingRepository($connection))->save($name, $tickers, $payload);

echo sprintf("Saved ranking '%s' with %d results and %d errors.\n", $name, count($results), count($errors));
