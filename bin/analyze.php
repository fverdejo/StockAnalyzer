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
use StockAnalyzer\Repository\ScoreHistoryRepository;
use StockAnalyzer\Services\AnalysisJsonPresenter;
use StockAnalyzer\Services\RiskLevelsCalculator;
use StockAnalyzer\Services\StockAnalysisService;
use StockAnalyzer\Utils\UniverseTickerResolver;

/**
 * Uso:
 *   php bin/analyze.php --universe=largecap60
 *   php bin/analyze.php --tickers="AAPL MSFT"
 *   php bin/analyze.php --all-universes
 *
 * --all-universes analiza cada ticker UNICO de config/universes.php una
 * sola vez (305 unicos frente a 540 entradas repetidas en 2026-08, ver
 * bin/verify-universes.php, que ya usa el mismo criterio de dedup) y
 * guarda un ranking en `daily_rankings` por cada universo al que
 * pertenezca ese ticker, en vez de repetir el analisis (y las llamadas a
 * Yahoo) del mismo ticker una vez por universo en el que aparece.
 * Ignora --universe/--tickers si se pasa junto a ellos.
 */
$options = getopt('', ['universe::', 'tickers::', 'name::', 'all-universes']);
$allUniverses = array_key_exists('all-universes', $options);
$universeKey = is_string($options['universe'] ?? null) ? (string) $options['universe'] : 'largecap60';
$name = is_string($options['name'] ?? null) ? (string) $options['name'] : $universeKey;
$explicitTickers = is_string($options['tickers'] ?? null) ? (string) $options['tickers'] : null;

$connection = new Connection();
$universes = new UniverseConfig();
$resolver = new UniverseTickerResolver($universes);

// $universesByTicker solo se rellena en modo --all-universes: es lo que
// permite guardar un daily_rankings por universo sin volver a analizar el
// mismo ticker. En el modo normal se deja vacio y no se usa.
$universesByTicker = [];

if ($allUniverses) {
    $universesByTicker = $resolver->allUniverseTickers();
    $tickers = array_keys($universesByTicker);
} else {
    $tickers = $resolver->resolve($universeKey, $explicitTickers);
}

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

// Snapshots diarios de fundamentales (v2.74) y de score (v2.63). Se
// siembran tambien desde aqui, y no solo desde la ficha de detalle, porque
// este CLI recorre un universo entero por ejecucion: es la unica via de
// acumular cobertura real en vez de depender de que alguien abra cada ficha.
//
// Las dos series son irrecuperables hacia atras (ni Yahoo ni ningun
// proveedor gratuito sirve fundamentales fechados, y el score depende de
// los pesos vigentes ese dia), asi que cada ejecucion que no las siembre es
// un hueco permanente.
$fundamentalsHistory = new FundamentalsHistoryRepository($connection);
$scoreHistory = new ScoreHistoryRepository($connection);

foreach ($tickers as $ticker) {
    try {
        $analysis = $service->analyze($ticker);
        $results[] = $analysis;

        try {
            $fundamentalsHistory->recordSnapshot($ticker, $analysis->getStock()->getFundamentals());
            $scoreHistory->recordSnapshot($ticker, $analysis->getScore());
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

$dailyRankings = new DailyRankingRepository($connection);

if ($allUniverses) {
    // Un daily_rankings por universo, reutilizando el analisis unico de
    // cada ticker: $results ya viene ordenado por score de mayor a menor,
    // asi que filtrar a un subconjunto conserva ese orden sin reordenar.
    foreach ($universes->all() as $key => $data) {
        $universeTickers = $data['tickers'];
        $tickerSet = array_flip($universeTickers);

        $universeResults = array_values(array_filter(
            $results,
            static fn ($analysis): bool => isset($tickerSet[$analysis->getStock()->getCompany()->getTicker()])
        ));
        $universeErrors = array_intersect_key($errors, $tickerSet);

        $payload = $presenter->ranking($universeResults, $universeErrors, $key, implode(' ', $universeTickers));
        $dailyRankings->save($key, $universeTickers, $payload);

        echo sprintf(
            "Saved ranking '%s' with %d results and %d errors.\n",
            $key,
            count($universeResults),
            count($universeErrors)
        );
    }

    echo sprintf(
        "Analyzed %d unique tickers across %d universes (%d OK, %d errors).\n",
        count($tickers),
        count($universes->all()),
        count($results),
        count($errors)
    );
} else {
    $payload = $presenter->ranking($results, $errors, $universeKey, implode(' ', $tickers));
    $dailyRankings->save($name, $tickers, $payload);

    echo sprintf("Saved ranking '%s' with %d results and %d errors.\n", $name, count($results), count($errors));
}
