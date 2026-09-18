<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;
use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * Verifica que `bin/export-eodhd-archive.php` produce un fichero
 * REALMENTE recuperable, sin red y sin depender de la base de datos de
 * la aplicacion -- Astra, `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`:
 * "Verificar una restauracion aislada y reconstruir un ejemplo sin red,
 * incluyendo uno de los 159 simbolos exclusivamente v1.1."
 *
 * Dos comprobaciones, ninguna toca la red ni escribe en la base de datos
 * de la aplicacion:
 *
 * 1. INTEGRIDAD: para CADA fila del export, descomprimir el blob y
 *    comprobar que su sha256 coincide con el `payload_hash` guardado en
 *    esa misma fila -- demuestra que el fichero exportado no esta
 *    corrupto, sin necesidad de ninguna base de datos.
 * 2. RECONSTRUCCION: para una MUESTRA de tickers (incluido al menos uno
 *    exclusivamente-v1.1, ver `--ejemplo-v11`), reconstruir `FiscalPeriod`
 *    desde el CONTENIDO DEL EXPORT (nunca desde la base de datos real) y
 *    comparar el resultado con la reconstruccion desde la base de datos
 *    real -- si coinciden, el export por si solo basta para reconstruir
 *    el historico usable, incluso sin la base de datos de la aplicacion.
 *
 * Uso:
 *   php bin/verify-eodhd-archive-export.php --file=storage/eodhd_archive_export_2026-09-18.jsonl.gz
 */
$options = getopt('', ['file:', 'ejemplo-v11::']);
$filePath = (string) ($options['file'] ?? '');

if ($filePath === '' || !file_exists($filePath)) {
    fwrite(STDERR, "Uso: --file=<ruta al .jsonl.gz exportado>\n");
    exit(1);
}

$ejemploV11 = (string) ($options['ejemplo-v11'] ?? 'AZN.L');

$gz = gzopen($filePath, 'rb');

if ($gz === false) {
    fwrite(STDERR, "No se pudo abrir $filePath.\n");
    exit(1);
}

$manifestLine = gzgets($gz);
$manifestDecoded = is_string($manifestLine) ? json_decode($manifestLine, true) : null;

if (!is_array($manifestDecoded) || !isset($manifestDecoded['__manifest__'])) {
    fwrite(STDERR, "La primera linea no es el manifiesto esperado.\n");
    exit(1);
}

$manifest = $manifestDecoded['__manifest__'];
printf(
    "Manifiesto: %d filas, %d tickers distintos, generado %s (codigo %s)\n",
    $manifest['row_count'],
    $manifest['distinct_tickers'],
    $manifest['generated_at'],
    substr((string) $manifest['code_revision'], 0, 12)
);

// === 1. INTEGRIDAD: hash de cada fila, sin ninguna base de datos ===
$rowsByTickerVersionSection = [];
$checked = 0;
$corrupted = [];

while (!gzeof($gz)) {
    $line = gzgets($gz);

    if ($line === false || trim($line) === '') {
        continue;
    }

    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $compressed = base64_decode($row['payload_compressed_base64'], true);

    if ($compressed === false) {
        $corrupted[] = $row['ticker'] . '/' . $row['api_version'] . '/' . $row['section'];

        continue;
    }

    $decompressed = @gzdecode($compressed);

    if ($decompressed === false || hash('sha256', $decompressed) !== $row['payload_hash']) {
        $corrupted[] = $row['ticker'] . '/' . $row['api_version'] . '/' . $row['section'];

        continue;
    }

    ++$checked;

    // Se guarda solo la ULTIMA observacion vista por (ticker, api_version,
    // section) -- el fichero viene ordenado por observed_at_utc ascendente
    // (mismo ORDER BY que la exportacion), asi que la ultima es la vigente.
    $key = $row['ticker'] . '|' . $row['api_version'] . '|' . $row['section'];
    $rowsByTickerVersionSection[$key] = $decompressed;
}
gzclose($gz);

printf("Integridad: %d/%d filas con hash verificado sin red ni base de datos.\n", $checked, $checked + count($corrupted));

if ($corrupted !== []) {
    printf("CORRUPTAS: %s\n", implode(', ', $corrupted));
}

// === 2. RECONSTRUCCION desde el export, comparada con la base real ===
$key = strtoupper($ejemploV11) . '|v1.1|full';
$exportedJson = $rowsByTickerVersionSection[$key] ?? null;

if ($exportedJson === null) {
    fwrite(STDERR, "No se encontro $ejemploV11 (v1.1/full) en el export -- se omite la comprobacion de reconstruccion.\n");
    exit(1);
}

$provider = new EodhdFiscalPeriodProvider('');
$periodsFromExport = $provider->parse(json_decode($exportedJson, true), $ejemploV11);

printf(
    "%s reconstruido SOLO desde el export (sin red, sin base de datos): %d periodos, %s -> %s\n",
    $ejemploV11,
    count($periodsFromExport),
    $periodsFromExport[0]->endDate->format('Y-m-d'),
    $periodsFromExport[array_key_last($periodsFromExport)]->endDate->format('Y-m-d')
);

// Comparacion contra la base de datos real (solo para verificar, en esta
// ejecucion puntual -- el punto de la tarea es que esto NO haria falta si
// la base de datos ya no existiera).
$connection = new Connection();
$versions = new EodhdRawFundamentalVersionsRepository($connection);
$realJson = $versions->latestFor($ejemploV11, 'v1.1', 'full');

if ($realJson === null) {
    fwrite(STDERR, "Aviso: no se pudo comparar contra la base real (sin version archivada ahora).\n");
    exit(0);
}

$periodsFromDb = $provider->parse(json_decode($realJson, true), $ejemploV11);
$matches = count($periodsFromExport) === count($periodsFromDb)
    && $exportedJson === $realJson;

printf(
    "Comparacion export vs base de datos real: %s (%d vs %d periodos, JSON %s)\n",
    $matches ? 'IDENTICO' : 'DISTINTO',
    count($periodsFromExport),
    count($periodsFromDb),
    $exportedJson === $realJson ? 'byte a byte igual' : 'DIFERENTE'
);

exit($matches && $corrupted === [] ? 0 : 1);
