<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;
use StockAnalyzer\Services\EodhdArchiveExportVerifier;
use StockAnalyzer\Services\EodhdArchiveRestorer;

/**
 * Verifica que un export de `bin/export-eodhd-archive.php` es REALMENTE
 * recuperable, sin red y sin depender de la base de datos de la aplicacion
 * (Astra, `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md` y, sobre el
 * verificador anterior, `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`,
 * encargo C1, que reprodujo dos falsos exitos).
 *
 * Tres pasos, por orden, y el codigo de salida es 0 SOLO si TODOS los
 * pedidos pasan:
 *
 * 1. VALIDACION AUTONOMA (siempre): contenedor gzip, manifiesto, cada fila
 *    (campos, base64, sha256), y que los recuentos del manifiesto coinciden
 *    con lo leido. Si falla, el resultado es CORRUPTO y no se sigue.
 * 2. RECONSTRUCCION offline de un simbolo exclusivamente v1.1 SOLO desde el
 *    fichero (`--ejemplo-v11=SIMBOLO`; por defecto AZN.L si es
 *    exclusivamente v1.1, si no el primero que lo sea). Con `--restore-check`
 *    ademas se RESTAURA el fichero entero en una base SQLite aislada y se
 *    reconstruye de esas tablas, comprobando los recuentos del manifiesto.
 * 3. COMPARACION con la base de datos (solo con `--compare-db`): nunca
 *    sustituye a la validacion autonoma; si se pide y no se puede hacer (sin
 *    BD o sin version archivada), termina con codigo 2, no con exito.
 *
 * Uso:
 *   php bin/verify-eodhd-archive-export.php --file=storage/exports/eodhd_archive_export_2026-09-22.jsonl.gz [--restore-check] [--compare-db] [--ejemplo-v11=AZN.L]
 */
$options = getopt('', ['file:', 'ejemplo-v11::', 'compare-db', 'restore-check']);
$filePath = (string) ($options['file'] ?? '');

if ($filePath === '') {
    fwrite(STDERR, "Uso: --file=<ruta al .jsonl.gz exportado> [--restore-check] [--compare-db] [--ejemplo-v11=SIMBOLO]\n");
    exit(1);
}

$compareDb = array_key_exists('compare-db', $options);
$restoreCheck = array_key_exists('restore-check', $options);
$requestedExample = is_string($options['ejemplo-v11'] ?? null) ? strtoupper(trim((string) $options['ejemplo-v11'])) : '';

// === 1. Validacion autonoma ===
$verifier = new EodhdArchiveExportVerifier();
$result = $verifier->verify($filePath);
$manifest = $result['manifest'];

if ($manifest !== null) {
    printf(
        "Manifiesto: %s filas, %s tickers distintos, generado %s (codigo %s)\n",
        json_encode($manifest['row_count'] ?? null),
        json_encode($manifest['distinct_tickers'] ?? null),
        (string) ($manifest['generated_at'] ?? '?'),
        substr((string) ($manifest['code_revision'] ?? '?'), 0, 12)
    );
}

printf(
    "Leidas %d filas (%d validas), %d tickers distintos, %d bytes descomprimidos.\n",
    $result['rows_read'],
    $result['rows_valid'],
    $result['distinct_tickers'],
    $result['container']['decompressed_bytes']
);

foreach ($result['by_api_version_section'] as $group => $rowsInGroup) {
    printf("  %-24s %d filas\n", $group, $rowsInGroup);
}

foreach ($result['warnings'] as $warning) {
    echo "AVISO: {$warning}\n";
}

if (!$result['ok']) {
    echo "\nARCHIVO CORRUPTO O INCOMPLETO:\n";

    foreach ($result['errors'] as $error) {
        echo " - {$error}\n";
    }

    exit(1);
}

printf("Validacion autonoma: OK (%d/%d filas con hash verificado, recuentos del manifiesto coinciden).\n", $result['rows_valid'], $result['rows_read']);

// === 2. Reconstruccion offline de un simbolo exclusivamente v1.1 ===
$exclusive = $result['exclusively_v11_tickers'];
printf("Simbolos exclusivamente v1.1 en el archivo: %d\n", count($exclusive));

$example = $requestedExample !== '' ? $requestedExample : (in_array('AZN.L', $exclusive, true) ? 'AZN.L' : ($exclusive[0] ?? ''));

if ($example === '') {
    fwrite(STDERR, "No hay ningun simbolo exclusivamente v1.1 y no se indico --ejemplo-v11: no se puede comprobar la reconstruccion offline.\n");
    exit(1);
}

$fromFile = $verifier->latestPayload($filePath, $example, 'v1.1', 'full');

if ($fromFile === null) {
    fwrite(STDERR, "No se encontro {$example} (v1.1/full) en el export: no se puede comprobar la reconstruccion.\n");
    exit(1);
}

$provider = new EodhdFiscalPeriodProvider('');
$periods = $provider->parse(json_decode($fromFile['payload'], true), $example);

if ($periods === []) {
    fwrite(STDERR, "{$example}: el parseo del payload exportado no produjo ningun periodo.\n");
    exit(1);
}

printf(
    "%s reconstruido SOLO desde el fichero (sin red, sin base de datos): %d periodos, %s -> %s (observado %s)\n",
    $example,
    count($periods),
    $periods[0]->endDate->format('Y-m-d'),
    $periods[array_key_last($periods)]->endDate->format('Y-m-d'),
    $fromFile['observed_at_utc']
);

if ($restoreCheck) {
    $sqlite = sys_get_temp_dir() . '/eodhd_restore_check_' . getmypid() . '.sqlite';

    try {
        $restorer = new EodhdArchiveRestorer();
        $store = EodhdArchiveRestorer::openIsolatedStore($sqlite);
        $counts = $restorer->restore($filePath, $store);
        printf(
            "Restauracion aislada (SQLite): %d observaciones, %d blobs unicos, %d tickers distintos.\n",
            $counts['observations'],
            $counts['versions'],
            $counts['distinct_tickers']
        );

        if ($counts['observations'] !== ($manifest['row_count'] ?? -1) || $counts['distinct_tickers'] !== ($manifest['distinct_tickers'] ?? -1)) {
            fwrite(STDERR, "La restauracion NO reproduce los recuentos del manifiesto.\n");
            exit(1);
        }

        $restored = $restorer->latestPayload($store, $example, 'v1.1', 'full');

        if ($restored !== $fromFile['payload']) {
            fwrite(STDERR, "El payload restaurado de {$example} NO coincide con el leido directamente del fichero.\n");
            exit(1);
        }

        $restoredPeriods = $provider->parse(json_decode((string) $restored, true), $example);
        printf("%s reconstruido desde las tablas RESTAURADAS: %d periodos (identico al fichero).\n", $example, count($restoredPeriods));
        $store = null;
    } finally {
        if (is_file($sqlite)) {
            unlink($sqlite);
        }
    }
}

// === 3. Comparacion opcional con la base de datos ===
if ($compareDb) {
    try {
        $versions = new EodhdRawFundamentalVersionsRepository(new Connection());
        $realJson = $versions->latestFor($example, 'v1.1', 'full');
    } catch (Throwable $throwable) {
        fwrite(STDERR, 'COMPARACION PEDIDA Y NO REALIZADA (base de datos no disponible): ' . $throwable->getMessage() . "\n");
        exit(2);
    }

    if ($realJson === null) {
        fwrite(STDERR, "COMPARACION PEDIDA Y NO REALIZADA: la base de datos no tiene version archivada de {$example}.\n");
        exit(2);
    }

    $identical = $fromFile['payload'] === $realJson;
    printf("Comparacion export vs base de datos real: %s\n", $identical ? 'IDENTICO (byte a byte)' : 'DISTINTO');

    if (!$identical) {
        exit(1);
    }
}

echo "\nRESULTADO: el archivo es integro y recuperable con las comprobaciones pedidas.\n";
exit(0);
