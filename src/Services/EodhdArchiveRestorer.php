<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use PDO;
use RuntimeException;

/**
 * RESTAURA un export del archivo EODHD (`bin/export-eodhd-archive.php`) en un
 * almacen AISLADO (una base SQLite propia, nunca las tablas de la
 * aplicacion) y permite leer de el sin red ni base de datos de la
 * aplicacion: encargo C1 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`
 * ("restauracion en tablas aisladas y reconstruccion offline de un simbolo
 * exclusivamente v1.1"). Comparar un parseo del export con la BD actual no
 * sustituye a esto: demuestra que el export SOLO basta para volver a tener
 * el historico usable.
 *
 * El almacen mantiene el mismo modelo que las tablas reales (blobs
 * deduplicados por hash + una fila por OBSERVACION) y `latestPayload()`
 * resuelve "la ultima observacion" igual que
 * `EodhdRawFundamentalVersionsRepository::latestFor()`: por
 * `observed_at_utc` y, a igualdad, por identificador de observacion (o
 * posicion en el fichero en el formato anterior, sin `observation_id`).
 */
final class EodhdArchiveRestorer
{
    private const BATCH_ROWS = 500;

    public function __construct(
        private readonly EodhdArchiveExportReader $reader = new EodhdArchiveExportReader()
    ) {
    }

    /**
     * Abre (creando) una base SQLite aislada.
     */
    public static function openIsolatedStore(string $sqlitePath): PDO
    {
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = OFF');
        $pdo->exec('PRAGMA synchronous = OFF');

        return $pdo;
    }

    /**
     * Carga el export en `$store` (tablas `restored_*`) y devuelve los
     * recuentos restaurados. Lanza si el fichero tiene filas invalidas: se
     * restaura solo un export que ya supero `EodhdArchiveExportVerifier`.
     *
     * @return array{observations: int, versions: int, distinct_tickers: int}
     */
    public function restore(string $exportPath, PDO $store): array
    {
        $store->exec('DROP TABLE IF EXISTS restored_observations');
        $store->exec('DROP TABLE IF EXISTS restored_versions');
        $store->exec('CREATE TABLE restored_versions (payload_hash TEXT PRIMARY KEY, payload_compressed BLOB NOT NULL)');
        $store->exec(
            'CREATE TABLE restored_observations (
                position INTEGER PRIMARY KEY,
                observation_id INTEGER NULL,
                ticker TEXT NOT NULL,
                api_version TEXT NOT NULL,
                section TEXT NOT NULL,
                observed_at_utc TEXT NOT NULL,
                source_symbol TEXT NULL,
                request_from TEXT NULL,
                request_to TEXT NULL,
                payload_hash TEXT NOT NULL REFERENCES restored_versions (payload_hash)
            )'
        );
        $store->exec('CREATE INDEX idx_restored_lookup ON restored_observations (ticker, api_version, section, observed_at_utc, observation_id, position)');

        $insertVersion = $store->prepare('INSERT OR IGNORE INTO restored_versions (payload_hash, payload_compressed) VALUES (:hash, :blob)');
        $insertObservation = $store->prepare(
            'INSERT INTO restored_observations (position, observation_id, ticker, api_version, section, observed_at_utc, source_symbol, request_from, request_to, payload_hash)
             VALUES (:position, :observation_id, :ticker, :api_version, :section, :observed_at_utc, :source_symbol, :request_from, :request_to, :hash)'
        );

        $store->beginTransaction();
        $count = 0;

        try {
            foreach ($this->reader->rows($exportPath) as $item) {
                $row = $item['row'];

                if ($row === null) {
                    throw new RuntimeException("Linea {$item['line']} invalida al restaurar: {$item['error']}.");
                }

                $blob = base64_decode((string) $row['payload_compressed_base64'], true);

                if ($blob === false) {
                    throw new RuntimeException("Linea {$item['line']}: base64 invalido al restaurar.");
                }

                $insertVersion->bindValue('hash', $row['payload_hash']);
                $insertVersion->bindValue('blob', $blob, PDO::PARAM_LOB);
                $insertVersion->execute();

                $insertObservation->execute([
                    'position' => $item['line'],
                    'observation_id' => $row['observation_id'] ?? null,
                    'ticker' => $row['ticker'],
                    'api_version' => $row['api_version'],
                    'section' => $row['section'],
                    'observed_at_utc' => $row['observed_at_utc'],
                    'source_symbol' => $row['source_symbol'] ?? null,
                    'request_from' => $row['request_from'] ?? null,
                    'request_to' => $row['request_to'] ?? null,
                    'hash' => $row['payload_hash'],
                ]);

                if (++$count % self::BATCH_ROWS === 0) {
                    $store->commit();
                    $store->beginTransaction();
                }
            }

            $store->commit();
        } catch (\Throwable $throwable) {
            if ($store->inTransaction()) {
                $store->rollBack();
            }

            throw $throwable;
        }

        return [
            'observations' => (int) $store->query('SELECT COUNT(*) FROM restored_observations')->fetchColumn(),
            'versions' => (int) $store->query('SELECT COUNT(*) FROM restored_versions')->fetchColumn(),
            'distinct_tickers' => (int) $store->query('SELECT COUNT(DISTINCT ticker) FROM restored_observations')->fetchColumn(),
        ];
    }

    /**
     * Contenido descomprimido de la ULTIMA observacion de un
     * `(ticker, api_version, section)`, solo desde el almacen restaurado;
     * comprueba el sha256 al leer.
     */
    public function latestPayload(PDO $store, string $ticker, string $apiVersion, string $section): ?string
    {
        $statement = $store->prepare(
            'SELECT v.payload_hash, v.payload_compressed
             FROM restored_observations o
             INNER JOIN restored_versions v ON v.payload_hash = o.payload_hash
             WHERE o.ticker = :ticker AND o.api_version = :api_version AND o.section = :section
             ORDER BY o.observed_at_utc DESC, COALESCE(o.observation_id, o.position) DESC
             LIMIT 1'
        );
        $statement->execute(['ticker' => strtoupper($ticker), 'api_version' => $apiVersion, 'section' => $section]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $compressed = is_resource($row['payload_compressed']) ? (string) stream_get_contents($row['payload_compressed']) : (string) $row['payload_compressed'];
        $payload = gzdecode($compressed);

        if ($payload === false || hash('sha256', $payload) !== $row['payload_hash']) {
            throw new RuntimeException("El payload restaurado de {$ticker} {$apiVersion}/{$section} no supera su hash.");
        }

        return $payload;
    }
}
