<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Services\PointInTimeFundamentalsBuilder;

/**
 * Regenera fundamentals_history con la formula corregida en v2.110
 * (PointInTimeFundamentalsBuilder ya calcula TTM real en vez de tratar un
 * trimestre como un ejercicio anual), EN PARALELO, en la tabla
 * fundamentals_history_v2110 (migracion 020) — NUNCA en fundamentals_history,
 * la tabla real. Ver roadmap.md, "Prioridad cero" punto 4.
 *
 * A diferencia de bin/backfill-fundamentals.php, esta NO golpea la red
 * para los fundamentales: lee el JSON crudo ya archivado por
 * bin/archive-eodhd-fundamentals.php (eodhd_raw_fundamentals) y lo
 * re-parsea con EodhdFiscalPeriodProvider::parse(). Solo los precios
 * historicos de Yahoo se piden (o se leen de su propia cache de 1 dia),
 * porque nunca se archivaron aparte y no forman parte de este arreglo.
 *
 * Uso:
 *   php bin/regenerate-fundamentals-history-v2110.php
 *   php bin/regenerate-fundamentals-history-v2110.php --tickers="AAPL MSFT"
 *   php bin/regenerate-fundamentals-history-v2110.php --dry-run
 *
 * Opciones:
 *   --tickers="A B C"  lista explicita en vez de todos los tickers archivados
 *   --history=RANGO     rango de precios a pedir (por defecto 10y)
 *   --dry-run            calcula y resume, sin escribir en la base
 */
$options = getopt('', ['tickers::', 'history::', 'dry-run']);

$dryRun = array_key_exists('dry-run', $options);
$historyRange = is_string($options['history'] ?? null) ? (string) $options['history'] : '10y';

$connection = new Connection();
$archive = new EodhdRawFundamentalsRepository($connection);

if (is_string($options['tickers'] ?? null) && trim((string) $options['tickers']) !== '') {
    $tickers = array_values(array_unique(array_map(
        static fn (string $t): string => strtoupper(trim($t)),
        preg_split('/\s+/', trim((string) $options['tickers'])) ?: []
    )));
} else {
    // Por defecto: TODOS los tickers de config/universes.php, no solo los
    // ya archivados, para que el informe final distinga "sin archivar"
    // (hueco en el punto 2) de "archivado pero fallo al regenerar".
    $universes = new UniverseConfig();
    $tickers = [];

    foreach ($universes->all() as $universe) {
        foreach ($universe['tickers'] as $ticker) {
            $tickers[] = $ticker;
        }
    }

    $tickers = array_values(array_unique($tickers));
}

if ($tickers === []) {
    fwrite(STDERR, 'No hay tickers que procesar.' . PHP_EOL);
    exit(1);
}

// apiKey vacia: EodhdFiscalPeriodProvider::parse() no toca la red, solo
// cruza un payload ya decodificado. El constructor la exige por contrato
// (fetch()/fetchRawJson() si la necesitarian), pero este script nunca
// llama a esos dos metodos.
$provider = new EodhdFiscalPeriodProvider('');
$history = new FundamentalsHistoryRepository($connection, 'fundamentals_history_v2110');

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
    'Regeneracion v2.110 de fundamentals_history_v2110%s%s%d tickers, precios %s%s',
    $dryRun ? ' (SIMULACION, no escribe)' : '',
    PHP_EOL,
    count($tickers),
    $historyRange,
    PHP_EOL . str_repeat('-', 62) . PHP_EOL
);

$okTickers = 0;
$notArchived = 0;
$failed = 0;
$totalRows = 0;
/** @var list<string> $failedTickers */
$failedTickers = [];
/** @var list<string> $notArchivedTickers */
$notArchivedTickers = [];

foreach ($tickers as $index => $ticker) {
    $prefix = sprintf('[%3d/%3d] %-10s ', $index + 1, count($tickers), $ticker);

    $rawJson = $archive->find($ticker);

    if ($rawJson === null) {
        echo $prefix . 'sin archivar (punto 2 pendiente para este ticker)' . PHP_EOL;
        ++$notArchived;
        $notArchivedTickers[] = $ticker;

        continue;
    }

    try {
        $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new MarketDataException($ticker . ': el JSON archivado no es un objeto.');
        }

        $periods = $provider->parse($decoded, $ticker);

        if ($periods === []) {
            echo $prefix . 'sin ejercicios utilizables en el archivo' . PHP_EOL;
            ++$failed;
            $failedTickers[] = $ticker;

            continue;
        }

        $builder = new PointInTimeFundamentalsBuilder($periods);
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
            '%s%d ejercicios (%s -> %s), %d dias%s%s',
            $prefix,
            count($periods),
            $periods[0]->endDate->format('Y-m-d'),
            $periods[array_key_last($periods)]->endDate->format('Y-m-d'),
            $written,
            $dryRun ? ' (simulado)' : '',
            PHP_EOL
        );
    } catch (\Throwable $exception) {
        echo $prefix . 'ERROR ' . $exception->getMessage() . PHP_EOL;
        ++$failed;
        $failedTickers[] = $ticker;
    }
}

echo str_repeat('-', 62) . PHP_EOL;
printf(
    'Regenerados: %d | sin archivar: %d | con error: %d%s',
    $okTickers,
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
