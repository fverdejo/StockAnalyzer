<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Repository\EarningsEventsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;
use StockAnalyzer\Services\EarningsEventsProjector;
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
 * Corregido el 2026-09-16 (hallazgo real de Astra,
 * `AUDITORIA_Y_TAREAS_EODHD_ASTRA_2026-09-16.md`, tarea A2): antes se
 * tomaba el hash/fecha de `allVersionsFor()` (ordenado por el `fetched_at`
 * del BLOB) y el contenido de `latestFor()` (ordenado por OBSERVACION) --
 * en una secuencia A->B->A eso escribia el contenido real de A junto al
 * hash y la fecha de B. Ahora todo sale de una unica llamada a
 * `latestObservationFor()`, que resuelve contenido, hash y fecha SIEMPRE
 * por la misma observacion.
 *
 * Corregido el 2026-09-18 (hallazgo real de Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B1): la
 * comprobacion de "ya normalizado" ahora compara contra el ESTADO
 * VIGENTE (`earnings_events_current_state`), no contra "este hash se vio
 * alguna vez" -- ver el docblock de
 * `EarningsEventsRepository::isNormalizedFromSource()`. Tambien incluye
 * `EodhdEarningsEventsNormalizer::VERSION`, para forzar renormalizacion
 * si el parseo cambia sin que el JSON crudo lo haga, y pasa el simbolo
 * real de EODHD (`source_symbol`) al normalizador para la comprobacion
 * de identidad de la tarea B2.
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
$projector = new EarningsEventsProjector(new EodhdEarningsEventsNormalizer(), $repository);

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

    $observation = $versions->latestObservationFor($ticker, 'calendar', 'earnings');

    if ($observation === null) {
        echo $prefix . 'sin version calendar/earnings archivada, se salta' . PHP_EOL;
        ++$skippedNoVersion;

        continue;
    }

    $sourceHash = $observation['payload_hash'];
    $normalizerVersion = EodhdEarningsEventsNormalizer::VERSION;

    if (!$force && $repository->isNormalizedFromSource($ticker, $sourceHash, $normalizerVersion)) {
        echo $prefix . 'ya normalizado desde esta captura, se salta' . PHP_EOL;
        ++$skippedUpToDate;

        continue;
    }

    try {
        // C5 (2026-09-22): capturas invalidas o de ventana parcial lanzan ANTES
        // de tocar `earnings_events` (ver `Services\EarningsEventsProjector`); el
        // ticker cuenta como error y conserva su proyeccion anterior.
        $projection = $projector->project($ticker, $observation);
        $written = $projection['written'];

        if ($projection['rejected_total'] > 0) {
            echo $prefix . sprintf(
                'AVISO %d filas descartadas (simbolo ajeno: %d, fecha invalida: %d, no objeto: %d)%s',
                $projection['rejected_total'],
                $projection['rejected']['simbolo_ajeno'],
                $projection['rejected']['fecha_invalida'],
                $projection['rejected']['fila_no_objeto'],
                PHP_EOL
            );
        }

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
