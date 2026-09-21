<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdSymbolEquivalences;
use StockAnalyzer\Services\EodhdArchiveExportWriter;
use StockAnalyzer\Services\EodhdEarningsEventsNormalizer;

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
 * Reescrito el 2026-09-22 (encargo C1 de
 * `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`):
 * - PUBLICACION ATOMICA: se escribe a temporales, se VERIFICA el fichero
 *   recien escrito con la misma validacion autonoma que se usara despues
 *   (`bin/verify-eodhd-archive-export.php`) y solo entonces se renombra al
 *   destino final; una exportacion interrumpida o con un fallo de escritura
 *   conserva la ultima copia valida y no deja ficheros parciales.
 * - IDENTIDAD Y ORDEN: cada fila lleva `observation_id`/`version_id` y el
 *   orden es binario (`ticker, api_version, section, observed_at_utc, id`),
 *   declarado en el manifiesto, para poder reconstruir tambien el estado
 *   VIGENTE (la observacion mas reciente) exactamente como
 *   `EodhdRawFundamentalVersionsRepository::latestFor()`.
 * - PAQUETE AUTOSUFICIENTE: el manifiesto lleva el esquema (`SHOW CREATE
 *   TABLE` y el sha256 de las migraciones), las equivalencias de simbolos
 *   (Yahoo -> EODHD) y la version del normalizador, ademas del hash de la
 *   lista de tickers y el rango de identificadores.
 *
 * Este script NUNCA toca la red ni pide nada a EODHD: es una lectura pura
 * de lo que ya esta en `eodhd_raw_fundamental_versions`.
 *
 * Uso:
 *   php bin/export-eodhd-archive.php
 *   php bin/export-eodhd-archive.php --out=storage/exports/eodhd_export_2026-09-22.jsonl.gz
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

// Esquema y migraciones: SE LEEN ANTES de abrir la consulta sin buffer (no se
// puede lanzar otra consulta mientras un cursor sin buffer sigue abierto).
$tables = ['eodhd_raw_fundamental_versions', 'eodhd_raw_fundamental_version_observations'];
$schemaTables = [];

foreach ($tables as $table) {
    $create = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM);
    $schemaTables[$table] = is_array($create) ? (string) $create[1] : '';
}

$migrationHashes = [];

foreach (['025_create_eodhd_raw_fundamental_versions.sql', '027_create_eodhd_raw_fundamental_version_observations.sql'] as $migration) {
    $migrationPath = __DIR__ . '/../database/migrations/' . $migration;
    $migrationHashes[$migration] = is_file($migrationPath) ? hash_file('sha256', $migrationPath) : null;
}

$manifestExtras = [
    'code_revision' => trim((string) shell_exec('git rev-parse HEAD 2>&1')),
    'schema_migrations' => array_keys($migrationHashes),
    'schema' => [
        'create_table' => $schemaTables,
        'migrations_sha256' => $migrationHashes,
        'note' => 'observation_id/version_id son las claves primarias originales; payload_hash es el sha256 del JSON ORIGINAL sin comprimir; payload_compressed_base64 es el blob gzip tal cual estaba en la tabla.',
    ],
    'symbol_equivalences' => [
        'ticker' => 'ticker del proyecto (convencion Yahoo); source_symbol de cada fila es el simbolo realmente pedido a EODHD (null = el mismo ticker + .US)',
        'exchange_suffix_map' => EodhdSymbolEquivalences::EXCHANGE_SUFFIX_MAP,
        'old_suffix_rule' => 'TICKER_OLD[n] -> TICKER_old[n].US (minusculas, antes del sufijo de bolsa)',
        'uncovered_by_plan' => ['.T (Japon)', '.MI (Italia)', '.SI (Singapur)', '.TA (Israel)', '.NZ (Nueva Zelanda)'],
    ],
    'normalizers' => ['earnings_events_normalizer_version' => EodhdEarningsEventsNormalizer::VERSION],
    'restore_howto' => 'php bin/verify-eodhd-archive-export.php --file=<este fichero> --restore-check  (valida, restaura en SQLite aislado y reconstruye un simbolo exclusivamente v1.1 sin red ni base de datos)',
];

// Cursor SIN buffer: el archivo pesa cientos de MB y no debe cargarse entero.
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$statement = $pdo->query(
    'SELECT o.id AS observation_id, o.version_id, o.ticker, o.api_version, o.section, o.observed_at_utc,
            o.source_symbol, o.request_from, o.request_to,
            v.payload_hash, v.payload_compressed
     FROM eodhd_raw_fundamental_version_observations o
     INNER JOIN eodhd_raw_fundamental_versions v ON v.id = o.version_id
     ORDER BY o.ticker COLLATE utf8mb4_bin, o.api_version COLLATE utf8mb4_bin, o.section COLLATE utf8mb4_bin,
              o.observed_at_utc, o.id'
);

$rows = (static function () use ($statement): Generator {
    $count = 0;

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $row['observation_id'] = (int) $row['observation_id'];
        $row['version_id'] = (int) $row['version_id'];

        yield $row;

        if (++$count % 5000 === 0) {
            echo "... $count filas exportadas\n";
        }
    }
})();

$manifest = (new EodhdArchiveExportWriter())->write($outPath, $rows, $manifestExtras);

printf(
    "Exportadas %d observaciones (%d tickers distintos) a %s (%s), verificadas antes de publicar.\n",
    $manifest['row_count'],
    $manifest['distinct_tickers'],
    $outPath,
    number_format((float) (filesize($outPath) / 1_048_576), 1) . 'MB'
);

foreach ($manifest['by_api_version_section'] as $key => $count) {
    printf("  %-20s %d\n", $key, $count);
}
