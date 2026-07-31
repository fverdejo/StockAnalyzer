<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Analyzer\NewsAnalyzer;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Repository\NewsRepository;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\RiskLevelsCalculator;
use StockAnalyzer\Utils\TickerNormalizer;

$options = getopt('', ['universe::', 'tickers::', 'horizon::']);
$universeKey = is_string($options['universe'] ?? null) ? (string) $options['universe'] : 'default';
$horizon = max(5, min(120, (int) ($options['horizon'] ?? 20)));
$universes = new UniverseConfig();
$rawTickers = is_string($options['tickers'] ?? null)
    ? (string) $options['tickers']
    : implode(' ', $universes->tickers($universeKey));
$tickers = (new TickerNormalizer())->normalize($rawTickers);

$connection = new Connection();
$weights = new ScoreWeights();
$service = new BacktestingService(
    new CachedMarketDataProvider(new YahooFinanceProvider(), new MarketDataCacheRepository($connection)),
    new TechnicalAnalyzer(),
    new ScoreCalculator($weights, new NewsAnalyzer(new NewsRepository($connection), $weights)),
    new RiskLevelsCalculator(new RiskLevelsConfig())
);

echo json_encode($service->run($tickers, $horizon), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
