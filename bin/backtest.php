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
use StockAnalyzer\Repository\TickerBacktestCacheRepository;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\RiskLevelsCalculator;
use StockAnalyzer\Utils\TickerNormalizer;

// Para validar hallazgos con muestras estadisticamente independientes
// (no solapadas), ejecutar con --step=<valor igual a --horizon>: p.ej.
// --horizon=20 --step=20. Con el --step por defecto (5) y horizontes
// tipicos de 20 dias, cada muestra comparte hasta 15 de sus 20 dias de
// retorno futuro con la siguiente (autocorrelacion): ver
// 'effective_independent_samples' en la salida de cada ticker.
//
// --persist: en vez de imprimir el JSON de run() a stdout, recorre los
// tickers uno a uno via BacktestingService::runForTickerCached() para
// poblar/refrescar ticker_backtest_cache (ver versions.md v2.34). Pensado
// como "cron de calentamiento" ejecutado a mano o via cron sobre universos
// sectoriales completos, para que el historial de señal en produccion
// (Application::renderSignalHistory()) encuentre casi siempre cache en
// vez de recalcular en vivo. Solo tiene sentido con --mode=full (el que
// cachea runForTickerCached()); --persist ignora --mode=technical.
$options = getopt('', ['universe::', 'tickers::', 'horizon::', 'step::', 'mode::', 'persist']);
$universeKey = is_string($options['universe'] ?? null) ? (string) $options['universe'] : 'default';
$horizon = max(5, min(120, (int) ($options['horizon'] ?? 20)));
$step = max(1, min(120, (int) ($options['step'] ?? 5)));
$mode = is_string($options['mode'] ?? null) ? (string) $options['mode'] : 'full';
$persist = array_key_exists('persist', $options);

if (!in_array($mode, ['full', 'technical'], true)) {
    fwrite(STDERR, "Modo desconocido: '$mode'. Valores validos: 'full', 'technical'." . PHP_EOL);
    exit(1);
}

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

if ($persist) {
    $cache = new TickerBacktestCacheRepository($connection);
    $warmed = [];
    $failed = [];

    foreach ($tickers as $ticker) {
        $result = $service->runForTickerCached($ticker, $cache, $horizon, $step);

        if ($result === null) {
            $failed[] = $ticker;
            continue;
        }

        $warmed[] = $ticker;
    }

    echo json_encode([
        'horizon_days' => $horizon,
        'step' => $step,
        'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        'warmed' => $warmed,
        'failed' => $failed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo json_encode($service->run($tickers, $horizon, $step, $mode), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
