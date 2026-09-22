<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\OfflineOnlyMarketDataProvider;
use StockAnalyzer\Repository\IndexMembershipRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Repository\PreloadedFundamentalsHistoryRepository;
use StockAnalyzer\Repository\PreloadedIndexMembershipChecker;
use StockAnalyzer\Services\MeasurementDataFingerprint;
use StockAnalyzer\Services\MeasurementGenerationManifest;

/**
 * Manifiesto de GENERACION de un directorio de resultados offline ya
 * producido (`bin/*-batch.php` de `storage/scratch/`): encargo C4 de
 * `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`. Toca la base de datos
 * SOLO para leer (precarga + `getHistoricalQuotes()`), nunca escribe en ella
 * ni llama a ningun proveedor de red -- el mismo contrato offline que los
 * lotes que genera.
 *
 * Dos modos:
 *
 *   --mode=build   Calcula la huella de cada ticker con los datos ACTUALES
 *                  de la base de datos y escribe `<dir>/generation_manifest.json`
 *                  (se niega a pisar uno existente salvo `--force`).
 *   --mode=verify  Recalcula las huellas igual que `build`, pero las
 *                  COMPARA contra el `generation_manifest.json` ya guardado
 *                  en `<dir>` -- el "modo reanudar" que exige C4: si la base
 *                  de datos cambio para algun ticker desde que se genero el
 *                  manifiesto, `dataset_hash` no coincide y esto falla.
 *
 * En ambos modos se comprueba ademas que el conjunto de tickers con
 * resultado en `<dir>` es EXACTAMENTE `--universe` menos las exclusiones
 * documentadas (`<dir>/<TICKER>.error.txt` con prefijo `AUSENCIA_LEGITIMA:`,
 * el mismo convenio que ya usan los lotes) -- ni falta ninguno sin
 * documentar, ni sobra ninguno fuera del universo congelado.
 *
 * Uso:
 *   php bin/verify-measurement-dataset.php --mode=build  --dir=<resultados> --universe=<fichero> --as-of=2026-09-18 [--index-code=GSPC] [--history-range=10y] [--tickers="A B"]
 *   php bin/verify-measurement-dataset.php --mode=verify --dir=<resultados> --universe=<fichero> --as-of=2026-09-18 [--index-code=GSPC] [--history-range=10y]
 *
 * `--tickers` restringe el recorrido a una lista explicita (pruebas
 * pequeñas); sin el, se usan todos los `.json` presentes en `--dir` (modo
 * `verify`) o todo `--universe` (modo `build`).
 */
$options = getopt('', ['mode:', 'dir:', 'universe:', 'as-of:', 'index-code::', 'history-range::', 'tickers::', 'force']);

$mode = (string) ($options['mode'] ?? '');
$dir = (string) ($options['dir'] ?? '');
$universeFile = (string) ($options['universe'] ?? '');
$asOfArg = (string) ($options['as-of'] ?? '');

if (!in_array($mode, ['build', 'verify'], true) || $dir === '' || $universeFile === '' || $asOfArg === '') {
    fwrite(STDERR, "Uso: --mode=build|verify --dir=<resultados> --universe=<fichero> --as-of=YYYY-MM-DD [--index-code=GSPC] [--history-range=10y] [--tickers=\"A B\"] [--force]\n");
    exit(1);
}

if (!is_dir($dir) || !is_file($universeFile)) {
    fwrite(STDERR, "El directorio de resultados o el fichero de universo no existen.\n");
    exit(1);
}

$asOf = new DateTimeImmutable($asOfArg);
$indexCode = is_string($options['index-code'] ?? null) ? strtoupper(trim((string) $options['index-code'])) : null;
$historyRange = (string) ($options['history-range'] ?? '10y');
$manifestPath = rtrim($dir, '/') . '/generation_manifest.json';

$generationManifest = new MeasurementGenerationManifest();
$universeTickers = $generationManifest->parseUniverse((string) file_get_contents($universeFile));

// Exclusiones documentadas: mismo convenio que los lotes (`<TICKER>.error.txt`).
$excluded = [];

foreach (glob("$dir/*.error.txt") ?: [] as $errorFile) {
    $ticker = strtoupper(basename($errorFile, '.error.txt'));
    $reason = trim((string) file_get_contents($errorFile));

    if (str_starts_with($reason, 'AUSENCIA_LEGITIMA')) {
        $excluded[$ticker] = $reason;
    }
}

if (is_string($options['tickers'] ?? null) && trim((string) $options['tickers']) !== '') {
    $tickers = array_values(array_unique(array_map('strtoupper', preg_split('/\s+/', trim((string) $options['tickers'])) ?: [])));
} elseif ($mode === 'build') {
    $tickers = $universeTickers;
} else {
    $tickers = [];

    foreach (glob("$dir/*.json") ?: [] as $file) {
        $base = basename($file, '.json');

        if (!in_array($base, ['manifest', 'generation_manifest'], true)) {
            $tickers[] = strtoupper($base);
        }
    }

    sort($tickers);
}

$connection = new Connection();
$marketData = new OfflineOnlyMarketDataProvider(new MarketDataCacheRepository($connection), $historyRange);
$preloadedFundamentals = new PreloadedFundamentalsHistoryRepository($connection);
$preloadedMembership = $indexCode !== null ? new PreloadedIndexMembershipChecker(new IndexMembershipRepository($connection)) : null;
$fingerprintService = new MeasurementDataFingerprint();

$fingerprints = [];
$technicalFailure = null;
$startedAt = microtime(true);

foreach ($tickers as $ticker) {
    if (isset($excluded[$ticker])) {
        continue;
    }

    try {
        $preloadedFundamentals->preloadTicker($ticker);
        $preloadedMembership?->preload($ticker, (string) $indexCode);
        $quotes = $marketData->getHistoricalQuotes($ticker);

        $price = $fingerprintService->priceFingerprint($quotes, $asOf);
        $fundamentals = $preloadedFundamentals->dataFingerprint($ticker, $asOf);
        $membership = $preloadedMembership?->dataFingerprint($ticker, (string) $indexCode, $asOf);

        $fingerprints[$ticker] = $fingerprintService->combine($price, $fundamentals, $membership);
    } catch (MarketDataException $exception) {
        // Ausencia legitima NO documentada previamente: en modo build esto
        // hay que declararlo (con .error.txt) antes de poder publicar el
        // estudio; se reporta, no se detiene el resto del recorrido.
        fwrite(STDERR, "AVISO $ticker: {$exception->getMessage()}\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, "FALLO TECNICO en $ticker: {$exception->getMessage()}\n");
        $technicalFailure = $ticker;

        break;
    }
}

if ($technicalFailure !== null) {
    fwrite(STDERR, "Fallo tecnico en $technicalFailure -- se aborta sin escribir ni comparar ningun manifiesto.\n");
    exit(1);
}

printf("Huellas calculadas para %d tickers en %.1fs.\n", count($fingerprints), microtime(true) - $startedAt);

$completeness = $generationManifest->verifyComplete($universeTickers, array_keys($fingerprints), $excluded);

if (!$completeness['ok']) {
    echo "\n*** EL CONJUNTO DE TICKERS NO ES EL ESPERADO ***\n";

    foreach ($completeness['errors'] as $error) {
        echo " - $error\n";
    }

    if ($mode === 'build') {
        fwrite(STDERR, "\nSe aborta: no se puede publicar un manifiesto de generacion incompleto.\n");
        exit(1);
    }
}

$config = ['as_of' => $asOf->format('Y-m-d'), 'index_code' => $indexCode, 'history_range' => $historyRange];
$fresh = $generationManifest->build($universeFile, $fingerprints, $excluded, $config);

if ($mode === 'build') {
    if (file_exists($manifestPath) && !array_key_exists('force', $options)) {
        fwrite(STDERR, "$manifestPath ya existe -- usa --force para sobrescribirlo (o compara con --mode=verify).\n");
        exit(2);
    }

    $encoded = json_encode($fresh, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false || file_put_contents($manifestPath, $encoded) === false) {
        fwrite(STDERR, "No se pudo escribir $manifestPath.\n");
        exit(1);
    }

    printf("Manifiesto de generacion escrito en %s\ndataset_hash = %s\n", $manifestPath, $fresh['dataset_hash']);
    exit($completeness['ok'] ? 0 : 1);
}

// --- mode=verify ---
if (!file_exists($manifestPath)) {
    fwrite(STDERR, "No existe $manifestPath -- genera primero con --mode=build.\n");
    exit(1);
}

$stored = json_decode((string) file_get_contents($manifestPath), true);

if (!is_array($stored)) {
    fwrite(STDERR, "$manifestPath no es un JSON valido.\n");
    exit(1);
}

$datasetMatches = ($stored['dataset_hash'] ?? null) === $fresh['dataset_hash'];
$universeMatches = ($stored['universe_sha256'] ?? null) === $fresh['universe_sha256'];

printf("\ndataset_hash guardado  = %s\n", (string) ($stored['dataset_hash'] ?? '?'));
printf("dataset_hash recalculado = %s\n", $fresh['dataset_hash']);
printf("universe_sha256 coincide: %s\n", $universeMatches ? 'si' : 'NO');
printf("dataset_hash coincide:    %s\n", $datasetMatches ? 'si' : 'NO');

if (!$datasetMatches) {
    $changed = [];

    foreach ($fingerprints as $ticker => $hash) {
        $before = $stored['fingerprints'][$ticker] ?? null;

        if ($before !== null && $before !== $hash) {
            $changed[] = $ticker;
        }
    }

    if ($changed !== []) {
        echo 'Tickers cuyos datos cambiaron desde la generacion: ' . implode(',', $changed) . "\n";
    } else {
        echo "El conjunto de tickers cambio (ver arriba) o el manifiesto guardado no incluye huellas por ticker para comparar una a una.\n";
    }
}

$ok = $completeness['ok'] && $datasetMatches && $universeMatches;
echo "\n" . ($ok ? 'RESULTADO: identico -- el dataset no ha cambiado desde la generacion.' : 'RESULTADO: EL DATASET YA NO ES EL MISMO -- no publicar ningun analisis sobre el.') . "\n";
exit($ok ? 0 : 1);
