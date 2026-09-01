<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\DTO\FiscalPeriod;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Providers\FmpFiscalPeriodProvider;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Services\PointInTimeFundamentalsBuilder;
use StockAnalyzer\Utils\TickerNormalizer;

/**
 * Rellena `fundamentals_history` hacia atras (ver versions.md v2.93).
 *
 * Cruza dos fuentes que ya existen por separado:
 *
 *  - los ejercicios contables del proveedor de fundamentales elegido
 *    (`--provider=fmp|eodhd`), **con su fecha de publicacion**;
 *  - el historico diario de precios que ya esta cacheado de Yahoo.
 *
 * Para cada dia de cotizacion escribe los `Fundamentals` que se conocian
 * ESE dia: el ultimo ejercicio publicado hasta entonces, con los ratios de
 * precio recalculados al cierre de la jornada. Es lo que convierte el
 * backtest fundamental de imposible en posible, y lo que hace que
 * `fundamentals_point_in_time_pct` deje de ser 0.
 *
 * Uso:
 *   php bin/backfill-fundamentals.php --universe=largecap60
 *   php bin/backfill-fundamentals.php --tickers="AAPL MSFT" --dry-run
 *   php bin/backfill-fundamentals.php --all-universes --max-tickers=80
 *   php bin/backfill-fundamentals.php --provider=eodhd --all-universes
 *
 * Opciones:
 *   --provider=fmp|eodhd  fuente de los ejercicios contables (por defecto
 *                        `fmp`, para no romper nada existente). `eodhd`
 *                        (`EodhdFiscalPeriodProvider`) es de pago, da
 *                        historico trimestral profundo (decadas) en una
 *                        sola llamada por ticker, y cubre tambien tickers
 *                        no estadounidenses (`.MC` y demas).
 *   --universe=CLAVE     universo de config/universes.php
 *   --tickers="A B C"    lista explicita (manda sobre --universe)
 *   --all-universes      todos los tickers de todos los universos
 *   --max-tickers=N      corta ahi. El plan gratuito de FMP son 250
 *                        llamadas/dia y cada ticker cuesta 3, asi que
 *                        ~80 tickers agotan la cuota diaria (con `eodhd`
 *                        cada ticker cuesta 1 llamada HTTP).
 *   --history=RANGO      rango de precios a pedir (por defecto 10y)
 *   --skip-existing      no reprocesa tickers que ya tengan historico
 *                        anterior a hoy (reanudacion entre dias)
 *   --dry-run            calcula y resume, sin escribir en la base
 *
 * Con `--provider=fmp`, los tickers no estadounidenses fallan con un
 * mensaje claro del plan gratuito y no detienen el recorrido: se cuentan y
 * se siguen.
 */
$options = getopt('', [
    'provider::',
    'universe::',
    'tickers::',
    'all-universes',
    'max-tickers::',
    'history::',
    'skip-existing',
    'dry-run',
]);

$dryRun = array_key_exists('dry-run', $options);
$skipExisting = array_key_exists('skip-existing', $options);
$maxTickers = (int) ($options['max-tickers'] ?? 0);
$historyRange = is_string($options['history'] ?? null) ? (string) $options['history'] : '10y';

$universes = new UniverseConfig();
$normalizer = new TickerNormalizer();

if (is_string($options['tickers'] ?? null) && trim((string) $options['tickers']) !== '') {
    $tickers = $normalizer->normalize((string) $options['tickers']);
} elseif (array_key_exists('all-universes', $options)) {
    $tickers = [];
    foreach ($universes->all() as $universe) {
        foreach ($universe['tickers'] as $ticker) {
            $tickers[] = $ticker;
        }
    }
    $tickers = array_values(array_unique($tickers));
} else {
    $key = is_string($options['universe'] ?? null) ? (string) $options['universe'] : 'largecap60';
    $tickers = $universes->tickers($key);

    if ($tickers === []) {
        fwrite(STDERR, "Universo desconocido: '$key'." . PHP_EOL);
        exit(1);
    }
}

if ($tickers === []) {
    fwrite(STDERR, 'No hay tickers que procesar.' . PHP_EOL);
    exit(1);
}

if ($maxTickers > 0) {
    $tickers = array_slice($tickers, 0, $maxTickers);
}

$providerName = is_string($options['provider'] ?? null) ? strtolower((string) $options['provider']) : 'fmp';

if (!in_array($providerName, ['fmp', 'eodhd'], true)) {
    fwrite(STDERR, "Proveedor desconocido: '$providerName'. Usa --provider=fmp o --provider=eodhd." . PHP_EOL);
    exit(1);
}

$providerConfig = (new ProviderConfig())->load();
$configKey = $providerName === 'eodhd' ? 'eodhd' : 'financial_modeling_prep';
$apiKey = (string) ($providerConfig['providers'][$configKey]['api_key'] ?? '');

if ($apiKey === '') {
    fwrite(STDERR, "Falta la API key de '$configKey' en config/provider.local.php" . PHP_EOL);
    fwrite(STDERR, "Añade: '$configKey' => ['api_key' => '...']" . PHP_EOL);
    exit(1);
}

$connection = new Connection();
$fiscalPeriods = $providerName === 'eodhd'
    ? new EodhdFiscalPeriodProvider($apiKey)
    : new FmpFiscalPeriodProvider($apiKey);
$callsPerTicker = $providerName === 'eodhd'
    ? EodhdFiscalPeriodProvider::CALLS_PER_TICKER
    : FmpFiscalPeriodProvider::CALLS_PER_TICKER;
$history = new FundamentalsHistoryRepository($connection);
try {
    // Argumento con nombre: el rango es el CUARTO parametro del
    // constructor, y posicionalmente se colaria como HttpClient.
    $yahoo = new YahooFinanceProvider(historyRange: $historyRange);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
$prices = new CachedMarketDataProvider(
    $yahoo,
    new MarketDataCacheRepository($connection),
    $yahoo->getHistoryRange()
);

printf(
    "Relleno historico de fundamentales (proveedor: %s)%s%s%s tickers, precios %s%s",
    $providerName,
    $dryRun ? ' (SIMULACION, no escribe)' : '',
    PHP_EOL,
    count($tickers),
    $historyRange,
    PHP_EOL . str_repeat('-', 62) . PHP_EOL
);

$okTickers = 0;
$skipped = 0;
$failed = 0;
$totalRows = 0;
$callsUsed = 0;

foreach ($tickers as $index => $ticker) {
    $prefix = sprintf('[%3d/%3d] %-10s ', $index + 1, count($tickers), $ticker);

    try {
        // Reanudacion entre dias: si ya hay historico ANTERIOR a hoy, este
        // ticker se relleno en una pasada previa. Se comprueba antes de
        // gastar llamadas, que es el recurso escaso.
        if ($skipExisting && $history->countSnapshots($ticker) > 5) {
            echo $prefix . "ya rellenado, se salta" . PHP_EOL;
            ++$skipped;

            continue;
        }

        $periods = $fiscalPeriods->fetch($ticker);
        $callsUsed += $callsPerTicker;

        if ($periods === []) {
            echo $prefix . 'sin ejercicios utilizables' . PHP_EOL;
            ++$failed;

            continue;
        }

        $builder = new PointInTimeFundamentalsBuilder($periods);
        $firstFiling = $builder->earliestFilingDate();
        $quotes = $prices->getHistoricalQuotes($ticker);
        $written = 0;

        foreach ($quotes as $quote) {
            $date = $quote->getDate();

            // Antes de la primera publicacion no hay nada que reconstruir:
            // se evita recorrer años en los que buildFor() devolveria null.
            if ($firstFiling !== null && $date < $firstFiling) {
                continue;
            }

            $fundamentals = $builder->buildFor($date, $quote->getClose());

            if ($fundamentals === null) {
                continue;
            }

            if (!$dryRun) {
                $history->recordSnapshot($ticker, $fundamentals, $date);
            }

            ++$written;
        }

        $totalRows += $written;
        ++$okTickers;

        printf(
            '%s%d ejercicios (%s -> %s), %d dias%s%s',
            $prefix,
            count($periods),
            $periods[0]->endDate->format('Y-m-d'),
            $periods[array_key_last($periods)]->endDate->format('Y-m-d'),
            $written,
            $dryRun ? ' (simulado)' : '',
            PHP_EOL
        );
    } catch (MarketDataException $exception) {
        echo $prefix . 'ERROR ' . $exception->getMessage() . PHP_EOL;
        ++$failed;
        $callsUsed += $callsPerTicker;
    } catch (Throwable $exception) {
        echo $prefix . 'ERROR inesperado: ' . $exception->getMessage() . PHP_EOL;
        ++$failed;
    }
}

echo str_repeat('-', 62) . PHP_EOL;
printf(
    "Tickers rellenados: %d | saltados: %d | con error: %d%s",
    $okTickers,
    $skipped,
    $failed,
    PHP_EOL
);
printf("Filas de historico escritas: %s%s", number_format($totalRows, 0, ',', '.'), $dryRun ? ' (simuladas)' : '');
echo PHP_EOL;
printf(
    "Llamadas a %s gastadas: ~%d%s%s",
    $providerName === 'eodhd' ? 'EODHD' : 'FMP',
    $callsUsed,
    $providerName === 'fmp' ? ' (el plan gratuito son 250/dia)' : '',
    PHP_EOL
);
