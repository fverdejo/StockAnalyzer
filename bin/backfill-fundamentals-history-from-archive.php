<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Services\PointInTimeFundamentalsBuilder;

/**
 * Rellena `fundamentals_history` (la tabla REAL, a diferencia de
 * `bin/regenerate-fundamentals-history-v2110.php` que escribe en la
 * paralela `_v2110` usada para aquella comparacion puntual) para tickers
 * que YA tienen el JSON crudo archivado. Mismo patron de reconstruccion
 * sin red que el script `_v2110`: lee el archivo, `parse()` local, cruza
 * con el precio historico de Yahoo (cacheado o pedido si falta) via
 * `PointInTimeFundamentalsBuilder`.
 *
 * Pensado para el caso recurrente de "se añadio un universo nuevo, ya se
 * archivaron sus fundamentales crudos, ahora hace falta la serie diaria
 * point-in-time para poder backtestearlo" (ver versions.md, universo
 * `sp400` del 2026-09-07) -- no reprocesa tickers ya cubiertos salvo que
 * se pida `--force`, para no volver a escribir sobre datos ya correctos
 * sin necesidad.
 *
 * **Ampliado el 2026-09-18** (hallazgo real de Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B4): antes solo
 * leia `eodhd_raw_fundamentals` (legacy, 938/2.184 tickers). Los 159
 * simbolos internacionales archivados durante la campaña del
 * `2026-09-16` SOLO tienen version `v1.1` (nunca tuvieron fila legacy) --
 * tenian archivo pero CERO snapshots reconstruibles. Ahora, si no hay
 * legacy, se intenta `eodhd_raw_fundamental_versions` (`v1.1`/`full`)
 * como fuente alternativa -- mismo `parse()`, EODHD no cambio la forma
 * de `Financials` entre versiones (verificado con AZN.L/ULVR.L/BHP.AX
 * reales antes de escribir esto). Tambien extrae la moneda de cotizacion
 * (`EodhdFiscalPeriodProvider::extractPriceCurrencyCode()`, tarea B3) y
 * la pasa a `PointInTimeFundamentalsBuilder` para que los ratios que
 * mezclan precio con estados en otra moneda no se calculen mal.
 *
 * Uso:
 *   php bin/backfill-fundamentals-history-from-archive.php --universe=sp400
 *   php bin/backfill-fundamentals-history-from-archive.php --tickers="AAON ACI"
 *   php bin/backfill-fundamentals-history-from-archive.php --universe=sp400 --dry-run
 *
 * Opciones:
 *   --universe=CLAVE     tickers de config/universes.php
 *   --tickers="A B C"    lista explicita (manda sobre --universe)
 *   --history=RANGO      rango de precios a pedir si no esta cacheado (por defecto 10y)
 *   --force              reprocesa aunque ya tenga historico (por defecto se salta)
 *   --dry-run            calcula y resume, sin escribir en la base
 */
$options = getopt('', ['universe::', 'tickers::', 'history::', 'force', 'dry-run']);

$dryRun = array_key_exists('dry-run', $options);
$force = array_key_exists('force', $options);
$historyRange = is_string($options['history'] ?? null) ? (string) $options['history'] : '10y';

if (is_string($options['tickers'] ?? null) && trim((string) $options['tickers']) !== '') {
    $tickers = array_values(array_unique(array_map(
        static fn (string $t): string => strtoupper(trim($t)),
        preg_split('/\s+/', trim((string) $options['tickers'])) ?: []
    )));
} elseif (is_string($options['universe'] ?? null) && trim((string) $options['universe']) !== '') {
    $universes = require __DIR__ . '/../config/universes.php';
    $key = trim((string) $options['universe']);

    if (!isset($universes[$key]['tickers']) || !is_array($universes[$key]['tickers'])) {
        fwrite(STDERR, "Universo desconocido: '$key'." . PHP_EOL);
        exit(1);
    }

    $tickers = $universes[$key]['tickers'];
} else {
    fwrite(STDERR, 'Falta --universe=CLAVE o --tickers="A B C".' . PHP_EOL);
    exit(1);
}

if ($tickers === []) {
    fwrite(STDERR, 'No hay tickers que procesar.' . PHP_EOL);
    exit(1);
}

$connection = new Connection();
$archive = new EodhdRawFundamentalsRepository($connection);
$versionsArchive = new EodhdRawFundamentalVersionsRepository($connection);
// apiKey vacia: parse() no toca la red, solo cruza un payload ya
// decodificado (mismo criterio que bin/regenerate-fundamentals-history-v2110.php).
$provider = new EodhdFiscalPeriodProvider('');
$history = new FundamentalsHistoryRepository($connection);

try {
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
    'Relleno de fundamentals_history desde archivo (sin red)%s%s%d tickers, precios %s%s',
    $dryRun ? ' (SIMULACION, no escribe)' : '',
    PHP_EOL,
    count($tickers),
    $historyRange,
    PHP_EOL . str_repeat('-', 62) . PHP_EOL
);

$okTickers = 0;
$skipped = 0;
$notArchived = 0;
$failed = 0;
$totalRows = 0;
/** @var list<string> $notArchivedTickers */
$notArchivedTickers = [];
/** @var list<string> $failedTickers */
$failedTickers = [];

foreach ($tickers as $index => $ticker) {
    $prefix = sprintf('[%3d/%3d] %-10s ', $index + 1, count($tickers), $ticker);

    if (!$force && $history->countSnapshots($ticker) > 5) {
        echo $prefix . 'ya tiene historico, se salta' . PHP_EOL;
        ++$skipped;

        continue;
    }

    $rawJson = $archive->find($ticker);
    $source = 'legacy';

    if ($rawJson === null) {
        // Sin legacy: intentar v1.1 (tarea B4) -- mismos 159 simbolos
        // internacionales que la campaña del 2026-09-16 archivo SOLO ahi.
        $rawJson = $versionsArchive->latestFor($ticker, 'v1.1', 'full');
        $source = 'v1.1';
    }

    if ($rawJson === null) {
        echo $prefix . 'sin archivar (ni legacy ni v1.1)' . PHP_EOL;
        ++$notArchived;
        $notArchivedTickers[] = $ticker;

        continue;
    }

    try {
        $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException($ticker . ': el JSON archivado (' . $source . ') no es un objeto.');
        }

        $periods = $provider->parse($decoded, $ticker);

        if ($periods === []) {
            echo $prefix . "sin ejercicios utilizables en el archivo ($source)" . PHP_EOL;
            ++$failed;
            $failedTickers[] = $ticker;

            continue;
        }

        $priceCurrencyCode = EodhdFiscalPeriodProvider::extractPriceCurrencyCode($decoded);
        $builder = new PointInTimeFundamentalsBuilder($periods, $priceCurrencyCode);
        $firstFiling = $builder->earliestFilingDate();
        $quotes = $prices->getHistoricalQuotes($ticker);
        $written = 0;

        foreach ($quotes as $quote) {
            $date = $quote->getDate();

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
            '%s[%s] %d ejercicios (%s -> %s), %d dias%s%s%s',
            $prefix,
            $source,
            count($periods),
            $periods[0]->endDate->format('Y-m-d'),
            $periods[array_key_last($periods)]->endDate->format('Y-m-d'),
            $written,
            $priceCurrencyCode !== null ? " (precio en $priceCurrencyCode)" : '',
            $dryRun ? ' (simulado)' : '',
            PHP_EOL
        );
    } catch (Throwable $exception) {
        echo $prefix . 'ERROR ' . $exception->getMessage() . PHP_EOL;
        ++$failed;
        $failedTickers[] = $ticker;
    }
}

echo str_repeat('-', 62) . PHP_EOL;
printf(
    'Rellenados: %d | ya cubiertos (saltados): %d | sin archivar: %d | con error: %d%s',
    $okTickers,
    $skipped,
    $notArchived,
    $failed,
    PHP_EOL
);
printf('Filas de historico escritas: %s%s', number_format($totalRows, 0, ',', '.'), $dryRun ? ' (simuladas)' : '');
echo PHP_EOL;

if ($notArchivedTickers !== []) {
    printf('Sin archivar: %s%s', implode(', ', $notArchivedTickers), PHP_EOL);
}

if ($failedTickers !== []) {
    printf('Con error: %s%s', implode(', ', $failedTickers), PHP_EOL);
}
