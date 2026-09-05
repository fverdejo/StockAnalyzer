<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Repository\EarningsEventsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;
use StockAnalyzer\Services\EodhdEarningsEventsNormalizer;

/**
 * Normaliza el calendario de resultados de EODHD YA ARCHIVADO
 * (`eodhd_raw_fundamental_versions`, `api_version='calendar'`,
 * `section='earnings'`, migracion 025) en la tabla consultable
 * `earnings_events` (migracion 026) -- Bloque C del plan de Codex del
 * 2026-09-04. SIN NINGUNA llamada de red: todo el trabajo es sobre lo que
 * `bin/archive-eodhd-calendar-earnings.php` archivo el 2026-09-05.
 *
 * Universo = los mismos tickers ya archivados en `eodhd_raw_fundamentals`
 * (938, mismo criterio que el resto de scripts `bin/archive-eodhd-*` y
 * `bin/normalize-*` de esta tarea), no `config/universes.php`.
 *
 * Reanudable: se salta un ticker si `earnings_events` ya tiene filas
 * escritas con el `payload_hash` de la version `calendar/earnings` mas
 * reciente de ese ticker (`EarningsEventsRepository::isNormalizedFromSource()`).
 * `--force` ignora esa comprobacion y renormaliza igualmente.
 *
 * Uso:
 *   php bin/normalize-eodhd-earnings-events.php
 *   php bin/normalize-eodhd-earnings-events.php --tickers="AAPL MSFT"
 *   php bin/normalize-eodhd-earnings-events.php --force
 *   php bin/normalize-eodhd-earnings-events.php --max-tickers=50
 */
$options = getopt('', ['tickers::', 'max-tickers::', 'force']);

$force = array_key_exists('force', $options);
$maxTickers = (int) ($options['max-tickers'] ?? 0);

$connection = new Connection();
$legacyArchive = new EodhdRawFundamentalsRepository($connection);

if (is_string($options['tickers'] ?? null) && trim((string) $options['tickers']) !== '') {
    $tickers = array_values(array_unique(array_map(
        static fn (string $t): string => strtoupper(trim($t)),
        preg_split('/\s+/', trim((string) $options['tickers'])) ?: []
    )));
} else {
    $tickers = $legacyArchive->archivedTickers();
    sort($tickers);
}

if ($maxTickers > 0) {
    $tickers = array_slice($tickers, 0, $maxTickers);
}

if ($tickers === []) {
    fwrite(STDERR, 'No hay tickers que procesar.' . PHP_EOL);
    exit(1);
}

$versions = new EodhdRawFundamentalVersionsRepository($connection);
$repository = new EarningsEventsRepository($connection);
$normalizer = new EodhdEarningsEventsNormalizer();

printf(
    'Normalizacion de EODHD calendar/earnings -> earnings_events: %d tickers%s%s',
    count($tickers),
    PHP_EOL,
    str_repeat('-', 62) . PHP_EOL
);

$normalized = 0;
$skippedUpToDate = 0;
$skippedNoVersion = 0;
$withoutEvents = 0;
$totalEventsWritten = 0;
$failed = 0;
/** @var list<string> $failedTickers */
$failedTickers = [];

foreach ($tickers as $index => $ticker) {
    $ticker = (string) $ticker;
    $prefix = sprintf('[%3d/%3d] %-12s ', $index + 1, count($tickers), $ticker);

    // `allVersionsFor()` trae metadatos (sin el payload) de TODAS las
    // versiones archivadas del ticker, de cualquier api_version/section;
    // se filtra aqui a calendar/earnings y se toma la primera (ya viene
    // ordenada de mas reciente a mas antigua).
    $calendarVersions = array_values(array_filter(
        $versions->allVersionsFor($ticker),
        static fn (array $v): bool => $v['api_version'] === 'calendar' && $v['section'] === 'earnings'
    ));

    if ($calendarVersions === []) {
        echo $prefix . 'sin version calendar/earnings archivada, se salta' . PHP_EOL;
        ++$skippedNoVersion;

        continue;
    }

    $latest = $calendarVersions[0];
    $sourceHash = $latest['payload_hash'];
    $capturedAt = new DateTimeImmutable($latest['fetched_at']);

    if (!$force && $repository->isNormalizedFromSource($ticker, $sourceHash)) {
        echo $prefix . 'ya normalizado desde esta captura, se salta' . PHP_EOL;
        ++$skippedUpToDate;

        continue;
    }

    try {
        $json = $versions->latestFor($ticker, 'calendar', 'earnings');

        if ($json === null) {
            // No deberia ocurrir tras confirmar $calendarVersions !== [],
            // salvo una condicion de carrera; se trata como error legible
            // en vez de romper el lote completo.
            throw new RuntimeException('latestFor() no devolvio payload pese a haber una version archivada.');
        }

        $events = $normalizer->parse($ticker, $json);
        $written = $repository->replaceForTicker($ticker, $events, $sourceHash, $capturedAt);

        if ($written === 0) {
            echo $prefix . '0 eventos (calendario archivado vacio para este ticker)' . PHP_EOL;
            ++$withoutEvents;
        } else {
            printf('%s%d eventos normalizados%s', $prefix, $written, PHP_EOL);
        }

        ++$normalized;
        $totalEventsWritten += $written;
    } catch (\Throwable $exception) {
        echo $prefix . 'ERROR ' . $exception->getMessage() . PHP_EOL;
        ++$failed;
        $failedTickers[] = $ticker;
    }
}

echo str_repeat('-', 62) . PHP_EOL;
printf(
    'Normalizados ahora: %d (%d sin eventos) | ya al dia (saltados): %d | sin version archivada: %d | con error: %d%s',
    $normalized,
    $withoutEvents,
    $skippedUpToDate,
    $skippedNoVersion,
    $failed,
    PHP_EOL
);
printf('Total de filas escritas en earnings_events en este lote: %d%s', $totalEventsWritten, PHP_EOL);
printf(
    'earnings_events ahora: %d filas, %d tickers distintos%s',
    $repository->countTotal(),
    $repository->countDistinctTickers(),
    PHP_EOL
);

if ($failedTickers !== []) {
    printf('Tickers con error: %s%s', implode(', ', $failedTickers), PHP_EOL);
}
