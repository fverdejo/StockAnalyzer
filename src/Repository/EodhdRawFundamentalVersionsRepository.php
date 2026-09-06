<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use StockAnalyzer\DTO\EodhdFundamentalVersionStoreResult;
use StockAnalyzer\Enums\EodhdFundamentalVersionStoreOutcome;
use StockAnalyzer\Infrastructure\Database\Connection;
use Throwable;

/**
 * Historial VERSIONADO del archivo crudo de EODHD
 * (`025_create_eodhd_raw_fundamental_versions.sql`, Bloque A del plan de
 * Codex del 2026-09-04: "proteger lo ya pagado antes de nuevas descargas").
 *
 * Distinto de `EodhdRawFundamentalsRepository` (019, una fila por ticker,
 * UPSERT): esta tabla NUNCA sobrescribe -- cada captura queda como una fila
 * nueva, deduplicada solo cuando el contenido (hash del JSON original) no
 * cambia respecto a una version ya guardada con la misma
 * `(ticker, api_version, section)`. No sustituye al repositorio existente ni
 * cambia su comportamiento; es un archivo adicional para que un `--force`
 * futuro, o una captura con la API v1.1, no destruya lo ya pagado.
 *
 * El JSON se guarda comprimido con gzip (`payload_compressed`): a
 * ~580,6 MB sin comprimir para 938 tickers (ver `versions.md`, 2026-09-04),
 * repetir ese tamano sin comprimir en cada version futura no es sostenible.
 * `payload_hash` es el sha256 del JSON ORIGINAL sin comprimir -- gzip no es
 * determinista byte a byte entre ejecuciones aunque el contenido sea
 * identico, asi que el hash tiene que calcularse ANTES de comprimir para
 * servir de clave de deduplicacion real.
 *
 * **Correccion del 2026-09-06** (bug real senalado por Codex en la revision
 * independiente de `d608747`, ver `versions.md`): hasta entonces `store()`
 * usaba `INSERT IGNORE` contra la clave unica `(ticker, api_version,
 * section, payload_hash)`, asi que dos capturas con contenido IDENTICO
 * dejaban una unica fila -- indistinguible de "nunca se recaptura" -- y una
 * secuencia A->B->A (el valor cambia y luego VUELVE al original) perdia la
 * tercera captura por colision de hash con la primera fila, dejando que
 * `latestFor()` devolviera B como "mas reciente" aunque el ultimo estado
 * real observado fuera A. Ahora los BLOBS siguen deduplicados por hash en
 * esta tabla (migracion 025, sin cambios de forma), pero CADA llamada a
 * `store()` anhade tambien una fila nueva, sin deduplicar, en
 * `eodhd_raw_fundamental_version_observations` (migracion 027) con su
 * propio `observed_at_utc`. `latestFor()`/`allPayloadsFor()` resuelven por
 * esa tabla de observaciones, no por la fila de blob mas reciente.
 */
class EodhdRawFundamentalVersionsRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Archiva una captura. El BLOB (JSON comprimido) se deduplica por
     * `(ticker, api_version, section, payload_hash)`: un contenido
     * identico al de una version ya archivada reutiliza esa fila en vez de
     * duplicar el espacio. La OBSERVACION, en cambio, nunca se deduplica --
     * esta llamada representa una peticion real que acaba de completarse
     * con exito, y por tanto deja siempre una fila nueva en
     * `eodhd_raw_fundamental_version_observations`, apunte o no a un blob
     * ya existente. El resultado devuelto distingue ambos casos (ver
     * `EodhdFundamentalVersionStoreResult`).
     *
     * Antes de escribir nada se verifica que comprimir y descomprimir el
     * payload reproduce EXACTAMENTE el JSON original -- no basta con confiar
     * en que `gzencode()`/`gzdecode()` son deterministas, ya que el objetivo
     * entero de esta tabla es no perder nunca una captura ya pagada.
     *
     * `$requestFrom`/`$requestTo` son la ventana `from`/`to` pedida a EODHD
     * cuando el endpoint la admite (hoy solo `calendar/earnings`): forman
     * parte de lo que se observo en esa captura concreta, no del contenido
     * (dos capturas con la misma ventana pueden dar contenido identico o
     * distinto igualmente).
     */
    public function store(
        string $ticker,
        string $payloadJson,
        string $apiVersion,
        string $section,
        ?DateTimeImmutable $fetchedAt = null,
        ?int $httpStatus = null,
        ?string $sourceSymbol = null,
        ?DateTimeImmutable $requestFrom = null,
        ?DateTimeImmutable $requestTo = null
    ): EodhdFundamentalVersionStoreResult {
        $fetchedAt ??= new DateTimeImmutable();
        $ticker = strtoupper($ticker);
        $hash = hash('sha256', $payloadJson);
        $compressed = $this->compressAndVerify($payloadJson);

        $pdo = $this->connection->getPdo();
        $pdo->beginTransaction();

        try {
            // `ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` es la forma
            // explicita y no silenciosa de "insertar o reutilizar" que pidio
            // Codex en sustitucion de `INSERT IGNORE`: en una sola sentencia
            // atomica, o inserta el blob nuevo, o dirige `LAST_INSERT_ID()`
            // al id del blob ya existente -- en ningun caso se pierde el id
            // resultante, y `rowCount()` distingue de forma fiable cual de
            // los dos paso (MySQL documenta 1 fila afectada si se inserto, 0
            // si la fila ya tenia esos mismos valores).
            $upsert = $pdo->prepare(
                'INSERT INTO eodhd_raw_fundamental_versions
                    (ticker, api_version, section, fetched_at, payload_hash, payload_compressed, http_status, source_symbol)
                 VALUES
                    (:ticker, :api_version, :section, :fetched_at, :payload_hash, :payload_compressed, :http_status, :source_symbol)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $upsert->bindValue('ticker', $ticker);
            $upsert->bindValue('api_version', $apiVersion);
            $upsert->bindValue('section', $section);
            $upsert->bindValue('fetched_at', $fetchedAt->format('Y-m-d H:i:s'));
            $upsert->bindValue('payload_hash', $hash);
            $upsert->bindValue('payload_compressed', $compressed, PDO::PARAM_LOB);
            $upsert->bindValue('http_status', $httpStatus, $httpStatus === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $upsert->bindValue('source_symbol', $sourceSymbol);
            $upsert->execute();

            $outcome = $upsert->rowCount() === 1
                ? EodhdFundamentalVersionStoreOutcome::NEW_VERSION
                : EodhdFundamentalVersionStoreOutcome::DUPLICATE_CONTENT;
            $versionId = (int) $pdo->lastInsertId();

            $observation = $pdo->prepare(
                'INSERT INTO eodhd_raw_fundamental_version_observations
                    (version_id, ticker, api_version, section, observed_at_utc, http_status, source_symbol, request_from, request_to)
                 VALUES
                    (:version_id, :ticker, :api_version, :section, :observed_at_utc, :http_status, :source_symbol, :request_from, :request_to)'
            );
            $observation->bindValue('version_id', $versionId, PDO::PARAM_INT);
            $observation->bindValue('ticker', $ticker);
            $observation->bindValue('api_version', $apiVersion);
            $observation->bindValue('section', $section);
            $observation->bindValue('observed_at_utc', $fetchedAt->format('Y-m-d H:i:s'));
            $observation->bindValue('http_status', $httpStatus, $httpStatus === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $observation->bindValue('source_symbol', $sourceSymbol);
            $observation->bindValue('request_from', $requestFrom?->format('Y-m-d'));
            $observation->bindValue('request_to', $requestTo?->format('Y-m-d'));
            $observation->execute();

            $observationId = (int) $pdo->lastInsertId();

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        return new EodhdFundamentalVersionStoreResult($versionId, $observationId, $outcome);
    }

    /**
     * El JSON original de la OBSERVACION mas reciente de un
     * `(ticker, api_version, section)` (maximo `observed_at_utc` en
     * `eodhd_raw_fundamental_version_observations`), o `null` si no hay
     * ninguna archivada. Resuelve por observacion, no por la fila de blob
     * mas reciente: en una secuencia A->B->A la ultima OBSERVACION apunta
     * de vuelta al blob de A, aunque ese blob se haya insertado antes que
     * el de B (ver correccion del 2026-09-06 en el docblock de la clase).
     */
    public function latestFor(string $ticker, string $apiVersion, string $section): ?string
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT v.payload_compressed
             FROM eodhd_raw_fundamental_version_observations o
             INNER JOIN eodhd_raw_fundamental_versions v ON v.id = o.version_id
             WHERE o.ticker = :ticker AND o.api_version = :api_version AND o.section = :section
             ORDER BY o.observed_at_utc DESC, o.id DESC
             LIMIT 1'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'api_version' => $apiVersion,
            'section' => $section,
        ]);
        $compressed = $statement->fetchColumn();

        if (!is_string($compressed)) {
            return null;
        }

        return $this->decompress($compressed);
    }

    /**
     * TODAS las OBSERVACIONES archivadas de un `(ticker, api_version,
     * section)`, de mas antigua a mas reciente, con el JSON de su blob ya
     * descomprimido. A diferencia de `latestFor()` (solo la ultima), este
     * metodo existe para poder COMPARAR capturas separadas en el tiempo --
     * el proposito entero de esta tabla versionada (Bloque A,
     * `2026-09-04`/`05`): saber si un dato que hoy parece "historico"
     * (p.ej. `calendar/earnings`, `calendar/trends`) cambia entre una
     * captura y otra mas adelante, o si de verdad quedo congelado. Ver
     * `bin/compare-eodhd-calendar-versions.php`.
     *
     * Devuelve una fila POR OBSERVACION, no por blob distinto: una
     * recaptura con contenido identico (A->A) aparece dos veces con el
     * mismo `payload_hash` y `observed_at_utc` distintos -- necesario para
     * que el llamador pueda distinguir "se recapturo y no cambio" de
     * "nunca se ha vuelto a capturar" (`count() < 2`), y para poder
     * reconstruir una secuencia completa A->B->A mirando cada transicion
     * consecutiva en vez de solo la primera y la ultima.
     *
     * @return list<array{observed_at_utc: string, payload_hash: string, payload: string}>
     */
    public function allPayloadsFor(string $ticker, string $apiVersion, string $section): array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT o.observed_at_utc, v.payload_hash, v.payload_compressed
             FROM eodhd_raw_fundamental_version_observations o
             INNER JOIN eodhd_raw_fundamental_versions v ON v.id = o.version_id
             WHERE o.ticker = :ticker AND o.api_version = :api_version AND o.section = :section
             ORDER BY o.observed_at_utc ASC, o.id ASC'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'api_version' => $apiVersion,
            'section' => $section,
        ]);

        $rows = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $compressed = $row['payload_compressed'];

            if (!is_string($compressed)) {
                continue;
            }

            $rows[] = [
                'observed_at_utc' => (string) $row['observed_at_utc'],
                'payload_hash' => (string) $row['payload_hash'],
                'payload' => $this->decompress($compressed),
            ];
        }

        return $rows;
    }

    /**
     * Si ya hay al menos una version archivada de un `(ticker, api_version,
     * section)`. Es lo que hace REANUDABLE `bin/archive-eodhd-fundamentals-v11.php`
     * (Bloque B1 del plan de Codex del 2026-09-04): un proceso cortado a
     * mitad de camino no vuelve a pedir a EODHD lo que ya se guardo con
     * exito, sin tener que traer los metadatos completos de
     * `allVersionsFor()` solo para comprobar existencia.
     */
    public function hasVersion(string $ticker, string $apiVersion, string $section): bool
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT 1 FROM eodhd_raw_fundamental_versions
             WHERE ticker = :ticker AND api_version = :api_version AND section = :section
             LIMIT 1'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'api_version' => $apiVersion,
            'section' => $section,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Metadatos (sin el payload completo) de todas las versiones archivadas
     * de un ticker, de mas reciente a mas antigua. Sirve para inspeccionar
     * el historial sin cargar megabytes de JSON comprimido a memoria.
     *
     * @return list<array{
     *     id: int,
     *     ticker: string,
     *     api_version: string,
     *     section: string,
     *     fetched_at: string,
     *     payload_hash: string,
     *     http_status: ?int,
     *     source_symbol: ?string,
     *     parse_status: ?string,
     *     error_message: ?string
     * }>
     */
    public function allVersionsFor(string $ticker): array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT id, ticker, api_version, section, fetched_at, payload_hash,
                    http_status, source_symbol, parse_status, error_message
             FROM eodhd_raw_fundamental_versions
             WHERE ticker = :ticker
             ORDER BY fetched_at DESC, id DESC'
        );
        $statement->execute(['ticker' => strtoupper($ticker)]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'ticker' => (string) $row['ticker'],
                'api_version' => (string) $row['api_version'],
                'section' => (string) $row['section'],
                'fetched_at' => (string) $row['fetched_at'],
                'payload_hash' => (string) $row['payload_hash'],
                'http_status' => $row['http_status'] === null ? null : (int) $row['http_status'],
                'source_symbol' => $row['source_symbol'] === null ? null : (string) $row['source_symbol'],
                'parse_status' => $row['parse_status'] === null ? null : (string) $row['parse_status'],
                'error_message' => $row['error_message'] === null ? null : (string) $row['error_message'],
            ],
            $rows
        );
    }

    /** Cuantas versiones hay archivadas en total (todas las filas, no tickers distintos). */
    public function count(): int
    {
        $statement = $this->connection->getPdo()->query('SELECT COUNT(*) FROM eodhd_raw_fundamental_versions');

        return (int) $statement->fetchColumn();
    }

    /** Cuantos tickers distintos tienen al menos una version archivada. */
    public function countDistinctTickers(): int
    {
        $statement = $this->connection->getPdo()->query(
            'SELECT COUNT(DISTINCT ticker) FROM eodhd_raw_fundamental_versions'
        );

        return (int) $statement->fetchColumn();
    }

    /**
     * Comprime con gzip y comprueba, antes de devolver nada, que
     * descomprimirlo reproduce EXACTAMENTE el JSON original. Esta tabla
     * existe para no perder capturas ya pagadas: una compresion corrupta
     * silenciosa seria peor que no versionar nada.
     */
    private function compressAndVerify(string $payloadJson): string
    {
        $compressed = gzencode($payloadJson, 9);

        if ($compressed === false) {
            throw new RuntimeException('No se pudo comprimir el payload con gzencode().');
        }

        if ($this->decompress($compressed) !== $payloadJson) {
            throw new RuntimeException(
                'El ciclo comprimir/descomprimir no reproduce el JSON original; se aborta antes de guardar.'
            );
        }

        return $compressed;
    }

    private function decompress(string $compressed): string
    {
        $decompressed = gzdecode($compressed);

        if ($decompressed === false) {
            throw new RuntimeException('No se pudo descomprimir un payload_compressed archivado (gzdecode fallo).');
        }

        return $decompressed;
    }
}
