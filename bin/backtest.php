<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Analyzer\NewsAnalyzer;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Repository\NewsRepository;
use StockAnalyzer\Repository\TickerBacktestCacheRepository;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\DividendGrowthCalculator;
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
//
// --cross-sectional: en vez del backtest por umbrales absolutos ticker a
// ticker, mide la alpha del top-N frente a la media del universo en cada
// fecha (BacktestingService::runCrossSectional()), que es la pregunta que
// responde de verdad un producto que publica un ranking. Acepta --top=N
// (10 por defecto) y exige --step >= --horizon; si no se indica --step,
// usa el propio horizonte para que las fechas sean independientes.
//
// --history: rango de historico que se pide al proveedor ('2y' por
// defecto, el mismo que usa la web). Con '10y' hay del orden de 120 fechas
// independientes por universo con horizonte 20 en vez de ~21, que es la
// diferencia entre poder o no leer un t-stat. El historico de cada rango se
// cachea por separado (market_history_cache), asi que una ejecucion con
// rango largo no altera lo que sirve la web.
$options = getopt('', [
    'universe::',
    'tickers::',
    'horizon::',
    'step::',
    'mode::',
    'history::',
    'top::',
    'cross-sectional',
    'persist',
]);
$universeKey = is_string($options['universe'] ?? null) ? (string) $options['universe'] : 'default';
$horizon = max(5, min(120, (int) ($options['horizon'] ?? 20)));
$mode = is_string($options['mode'] ?? null) ? (string) $options['mode'] : 'full';
$historyRange = is_string($options['history'] ?? null) ? (string) $options['history'] : '2y';
$topN = max(1, min(100, (int) ($options['top'] ?? 10)));
$crossSectional = array_key_exists('cross-sectional', $options);
$persist = array_key_exists('persist', $options);
$defaultStep = $crossSectional ? $horizon : 5;
$step = max(1, min(120, (int) ($options['step'] ?? $defaultStep)));

if (!in_array($mode, ['full', 'technical'], true)) {
    fwrite(STDERR, "Modo desconocido: '$mode'. Valores validos: 'full', 'technical'." . PHP_EOL);
    exit(1);
}

// ticker_backtest_cache es la cache que lee la aplicacion web, y su clave
// (ticker, horizonte, paso) no incluye el rango de historico. Persistir ahi
// resultados calculados con un rango distinto al de la web dejaria en
// produccion cifras que no se corresponden con lo que la web recalcularia:
// mismo motivo por el que runForTickerCached() nunca cachea --mode=technical.
if ($persist && $historyRange !== '2y') {
    fwrite(STDERR, '--persist solo admite --history=2y: es la cache que sirve la aplicacion web.' . PHP_EOL);
    exit(1);
}

$universes = new UniverseConfig();
$rawTickers = is_string($options['tickers'] ?? null)
    ? (string) $options['tickers']
    : implode(' ', $universes->tickers($universeKey));
$tickers = (new TickerNormalizer())->normalize($rawTickers);

$connection = new Connection();
$weights = new ScoreWeights();

try {
    $yahoo = new YahooFinanceProvider(historyRange: $historyRange);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

$service = new BacktestingService(
    // El rango se toma del propio proveedor para que la etiqueta de la cache
    // no pueda desincronizarse de lo que se pide realmente a Yahoo.
    new CachedMarketDataProvider(
        $yahoo,
        new MarketDataCacheRepository($connection),
        $yahoo->getHistoryRange()
    ),
    new TechnicalAnalyzer(),
    new ScoreCalculator($weights, new NewsAnalyzer(new NewsRepository($connection), $weights)),
    new RiskLevelsCalculator(new RiskLevelsConfig()),
    new DividendGrowthCalculator(),
    new BacktestingConfig(),
    // Fundamentales point-in-time (v2.91). Mirar siempre
    // `fundamentals_point_in_time_pct` en la salida antes de sacar
    // conclusiones: con la serie recien empezada la mayoria de las muestras
    // siguen usando los fundamentales de hoy, igual que antes.
    new FundamentalsHistoryRepository($connection)
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

if ($crossSectional) {
    try {
        $crossSectionalResult = $service->runCrossSectional($tickers, $horizon, $step, $topN, $mode);
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    echo json_encode($crossSectionalResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo json_encode($service->run($tickers, $horizon, $step, $mode), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
