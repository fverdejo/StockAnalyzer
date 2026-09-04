<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;

/**
 * Copia, UNA SOLA VEZ, las filas de `eodhd_raw_fundamentals` (019, una fila
 * por ticker, UPSERT) a `eodhd_raw_fundamental_versions`
 * (025, historial versionado) con `api_version='legacy'`, `section='full'`.
 * Bloque A del plan de Codex del 2026-09-04: "proteger lo ya pagado antes
 * de nuevas descargas".
 *
 * No golpea la red: usa el `fetched_at`/`payload_hash` YA GUARDADOS en la
 * tabla origen. Antes de copiar cada fila, SI recalcula
 * `hash('sha256', payload_json)` y lo compara con el hash guardado en
 * origen -- para detectar corrupcion (un `payload_json` que ya no
 * corresponde a su propio hash archivado), no para ahorrarse el calculo.
 * Un ticker cuyo hash no cuadra NO se copia (se cuenta aparte, como
 * corrupto): mejor dejarlo fuera y que la verificacion final lo señale que
 * archivar una version que no se sabe si es integra.
 *
 * `eodhd_raw_fundamentals` (tabla origen) NO se toca, ni se lee con su
 * repositorio (que solo expone el JSON, no el hash/fecha guardados): se lee
 * directamente por SQL, mismo patron ya usado por otros scripts de
 * informe/backfill de un solo uso de este directorio (ver
 * `bin/compare-fundamentals-history-v2110.php`).
 *
 * Idempotente: la clave UNIQUE `(ticker, api_version, section, payload_hash)`
 * de la migracion 025 hace que ejecutar este script dos veces no duplique
 * nada -- la segunda vez, todas las filas caen en "ya existia".
 *
 * Uso:
 *   php bin/backfill-eodhd-fundamental-versions.php
 */
$connection = new Connection();
$pdo = $connection->getPdo();
$versions = new EodhdRawFundamentalVersionsRepository($connection);

$sourceCount = (int) $pdo->query('SELECT COUNT(*) FROM eodhd_raw_fundamentals')->fetchColumn();

echo 'Backfill de eodhd_raw_fundamentals -> eodhd_raw_fundamental_versions' . PHP_EOL;
printf('Filas en origen (eodhd_raw_fundamentals): %d%s', $sourceCount, PHP_EOL);
echo str_repeat('-', 62) . PHP_EOL;

// Deliberadamente NO se hace un unico SELECT de las 4 columnas para las 938
// filas: PDO usa consultas "bufferadas" por defecto con el driver de MySQL,
// asi que un SELECT que devuelva los ~580 MB de payload_json de golpe los
// carga TODOS en memoria antes de que el bucle procese la primera fila.
// Colgo la Raspberry Pi de produccion (906 MiB de RAM, ~199 MiB de swap) la
// primera vez que se ejecuto este script ahi -- no fue la compresion gzip,
// fue este SELECT. Primero se piden solo los tickers (cadenas cortas,
// coste de memoria insignificante) y despues se pide el payload de UNO en
// UNO dentro del bucle, para que en memoria haya como mucho un payload a la
// vez (el mayor de los 938 no llega a unos pocos MB).
$tickers = $pdo->query('SELECT ticker FROM eodhd_raw_fundamentals ORDER BY ticker')
    ->fetchAll(PDO::FETCH_COLUMN);

$rowStatement = $pdo->prepare(
    'SELECT payload_json, payload_hash, fetched_at FROM eodhd_raw_fundamentals WHERE ticker = :ticker'
);

$copied = 0;
$alreadyExisted = 0;
$corrupted = 0;
$index = 0;
/** @var list<string> $corruptedTickers */
$corruptedTickers = [];

foreach ($tickers as $ticker) {
    ++$index;
    $ticker = (string) $ticker;

    $rowStatement->execute(['ticker' => $ticker]);
    $row = $rowStatement->fetch();
    $rowStatement->closeCursor();

    if ($row === false) {
        // No debería pasar (el ticker viene de la misma tabla), pero si la
        // fila se borró entre el SELECT de tickers y este fetch, se salta
        // en vez de fallar todo el backfill.
        printf('[%3d/%3d] %-10s ya no existe en origen, se salta%s', $index, $sourceCount, $ticker, PHP_EOL);

        continue;
    }

    $payloadJson = (string) $row['payload_json'];
    $storedHash = (string) $row['payload_hash'];
    $fetchedAt = new DateTimeImmutable((string) $row['fetched_at']);

    $recomputedHash = hash('sha256', $payloadJson);

    if ($recomputedHash !== $storedHash) {
        printf(
            '[%3d/%3d] %-10s CORRUPCION: hash guardado no coincide con el payload actual, se omite%s',
            $index,
            $sourceCount,
            $ticker,
            PHP_EOL
        );
        ++$corrupted;
        $corruptedTickers[] = $ticker;

        continue;
    }

    $before = $versions->count();
    $versions->store($ticker, $payloadJson, 'legacy', 'full', $fetchedAt);
    $after = $versions->count();

    if ($after > $before) {
        printf('[%3d/%3d] %-10s copiado%s', $index, $sourceCount, $ticker, PHP_EOL);
        ++$copied;
    } else {
        printf('[%3d/%3d] %-10s ya existia (idempotencia)%s', $index, $sourceCount, $ticker, PHP_EOL);
        ++$alreadyExisted;
    }
}

echo str_repeat('-', 62) . PHP_EOL;
printf(
    'Copiados ahora: %d | ya existian: %d | corruptos (omitidos): %d%s',
    $copied,
    $alreadyExisted,
    $corrupted,
    PHP_EOL
);

if ($corruptedTickers !== []) {
    printf('Tickers corruptos: %s%s', implode(', ', $corruptedTickers), PHP_EOL);
}

$destinationDistinctTickers = $versions->countDistinctTickers();

echo str_repeat('-', 62) . PHP_EOL;
printf('Verificacion de cobertura:%s', PHP_EOL);
printf('  COUNT(*) origen (eodhd_raw_fundamentals):                 %d%s', $sourceCount, PHP_EOL);
printf('  COUNT(DISTINCT ticker) destino (eodhd_raw_fundamental_versions): %d%s', $destinationDistinctTickers, PHP_EOL);

if ($destinationDistinctTickers === $sourceCount) {
    echo 'OK: cobertura completa, todos los tickers de origen tienen al menos una version.' . PHP_EOL;
} else {
    printf(
        'AVISO: %d ticker(s) de origen sin version copiada (revisar los corruptos listados arriba).%s',
        $sourceCount - $destinationDistinctTickers,
        PHP_EOL
    );
}
