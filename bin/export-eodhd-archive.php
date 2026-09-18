<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * Exporta el archivo versionado COMPLETO de EODHD
 * (`eodhd_raw_fundamental_versions` + `eodhd_raw_fundamental_version_observations`,
 * migraciones 025/027) a un fichero portable, para poder recuperarlo sin
 * depender de esta base de datos ni de la suscripcion de EODHD (que
 * expira el 2026-10-01) -- Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, "Frente de conservacion":
 * "Exportar archivo crudo y observaciones, contextos de consulta, esquema
 * y equivalencias de simbolos, con hashes y cobertura."
 *
 * Formato: JSONL comprimido con gzip (`.jsonl.gz`), una fila por
 * OBSERVACION (no por blob -- una observacion sin su blob no significa
 * nada), con el blob YA COMPRIMIDO codificado en base64 (no se
 * descomprime para no duplicar 580MB+ en memoria ni en disco: el propio
 * `payload_compressed` ya es la unidad que hay que conservar). El hash
 * (`payload_hash`, del JSON ORIGINAL sin comprimir) viaja en cada fila
 * para poder verificar integridad sin descomprimir hasta que haga falta.
 *
 * Este script NUNCA toca la red ni pide nada a EODHD: es una lectura pura
 * de lo que ya esta en `eodhd_raw_fundamental_versions`.
 *
 * Uso:
 *   php bin/export-eodhd-archive.php
 *   php bin/export-eodhd-archive.php --out=storage/exports/eodhd_export_2026-09-18.jsonl.gz
 *
 * Por defecto escribe en `storage/exports/` (ya excluido de git en
 * `.gitignore`, mismo convenio que el resto de exportaciones del
 * proyecto) -- este fichero pesa varios cientos de MB, no es codigo.
 */
$options = getopt('', ['out::']);
$defaultDir = __DIR__ . '/../storage/exports';

if (!is_dir($defaultDir)) {
    mkdir($defaultDir, 0777, true);
}

$outPath = is_string($options['out'] ?? null) && trim((string) $options['out']) !== ''
    ? trim((string) $options['out'])
    : $defaultDir . '/eodhd_archive_export_' . date('Y-m-d') . '.jsonl.gz';

$connection = new Connection();
$pdo = $connection->getPdo();

// Las filas se escriben primero a un temporal SIN manifiesto: el
// manifiesto necesita los totales, que solo se conocen al terminar de
// recorrer las filas. Combinar los dos en el fichero final es una unica
// pasada de copia, no una reescritura completa.
$dataTmpPath = $outPath . '.data.tmp';
$gz = gzopen($dataTmpPath, 'wb9');

if ($gz === false) {
    fwrite(STDERR, "No se pudo abrir $dataTmpPath para escritura.\n");
    exit(1);
}

$manifest = [
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'code_revision' => trim((string) shell_exec('git rev-parse HEAD 2>&1')),
    'source' => 'eodhd_raw_fundamental_versions + eodhd_raw_fundamental_version_observations',
    'schema_migrations' => ['025_create_eodhd_raw_fundamental_versions.sql', '027_create_eodhd_raw_fundamental_version_observations.sql'],
    'row_count' => 0,
    'by_api_version_section' => [],
    'distinct_tickers' => 0,
];

// La primera linea del .jsonl.gz es SIEMPRE el manifiesto -- un
// consumidor lee esa linea primero para saber que esperar del resto,
// sin tener que contar filas antes de empezar.
$manifestPlaceholderWritten = false;

$statement = $pdo->query(
    'SELECT o.ticker, o.api_version, o.section, o.observed_at_utc,
            o.source_symbol, o.request_from, o.request_to,
            v.payload_hash, v.payload_compressed
     FROM eodhd_raw_fundamental_version_observations o
     INNER JOIN eodhd_raw_fundamental_versions v ON v.id = o.version_id
     ORDER BY o.ticker, o.api_version, o.section, o.observed_at_utc'
);

$rowCount = 0;
$byApiVersionSection = [];
$distinctTickers = [];

while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
    $key = $row['api_version'] . '/' . $row['section'];
    $byApiVersionSection[$key] = ($byApiVersionSection[$key] ?? 0) + 1;
    $distinctTickers[$row['ticker']] = true;

    $line = json_encode([
        'ticker' => $row['ticker'],
        'api_version' => $row['api_version'],
        'section' => $row['section'],
        'observed_at_utc' => $row['observed_at_utc'],
        'source_symbol' => $row['source_symbol'],
        'request_from' => $row['request_from'],
        'request_to' => $row['request_to'],
        'payload_hash' => $row['payload_hash'],
        'payload_compressed_base64' => base64_encode((string) $row['payload_compressed']),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    gzwrite($gz, $line . "\n");
    ++$rowCount;

    if ($rowCount % 500 === 0) {
        echo "... $rowCount filas exportadas\n";
    }
}

$manifest['row_count'] = $rowCount;
$manifest['by_api_version_section'] = $byApiVersionSection;
$manifest['distinct_tickers'] = count($distinctTickers);

gzclose($gz);

// Manifiesto + datos ya recogidos, en UNA pasada de copia hacia el
// fichero final.
$manifestLine = json_encode(['__manifest__' => $manifest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

$in = gzopen($dataTmpPath, 'rb');
$out = gzopen($outPath, 'wb9');
gzwrite($out, $manifestLine . "\n");

while (!gzeof($in)) {
    gzwrite($out, gzread($in, 1_048_576));
}

gzclose($in);
gzclose($out);
unlink($dataTmpPath);

printf(
    "Exportadas %d observaciones (%d tickers distintos) a %s (%s)\n",
    $rowCount,
    count($distinctTickers),
    $outPath,
    number_format((float) (filesize($outPath) / 1_048_576), 1) . 'MB'
);
foreach ($byApiVersionSection as $key => $count) {
    printf("  %-20s %d\n", $key, $count);
}
