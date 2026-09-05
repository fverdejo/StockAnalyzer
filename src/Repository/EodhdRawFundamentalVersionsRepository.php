<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use StockAnalyzer\Infrastructure\Database\Connection;

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
 */
class EodhdRawFundamentalVersionsRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Archiva una version. Si ya existe una fila con el mismo
     * `(ticker, api_version, section, payload_hash)` no se duplica (INSERT
     * IGNORE contra la clave UNIQUE de la migracion): un contenido
     * identico al de una captura anterior no gasta espacio de nuevo.
     *
     * Antes de escribir nada se verifica que comprimir y descomprimir el
     * payload reproduce EXACTAMENTE el JSON original -- no basta con confiar
     * en que `gzencode()`/`gzdecode()` son deterministas, ya que el objetivo
     * entero de esta tabla es no perder nunca una captura ya pagada.
     */
    public function store(
        string $ticker,
        string $payloadJson,
        string $apiVersion,
        string $section,
        ?DateTimeImmutable $fetchedAt = null,
        ?int $httpStatus = null,
        ?string $sourceSymbol = null
    ): void {
        $fetchedAt ??= new DateTimeImmutable();
        $hash = hash('sha256', $payloadJson);
        $compressed = $this->compressAndVerify($payloadJson);

        $statement = $this->connection->getPdo()->prepare(
            'INSERT IGNORE INTO eodhd_raw_fundamental_versions
                (ticker, api_version, section, fetched_at, payload_hash, payload_compressed, http_status, source_symbol)
             VALUES
                (:ticker, :api_version, :section, :fetched_at, :payload_hash, :payload_compressed, :http_status, :source_symbol)'
        );
        $statement->bindValue('ticker', strtoupper($ticker));
        $statement->bindValue('api_version', $apiVersion);
        $statement->bindValue('section', $section);
        $statement->bindValue('fetched_at', $fetchedAt->format('Y-m-d H:i:s'));
        $statement->bindValue('payload_hash', $hash);
        $statement->bindValue('payload_compressed', $compressed, PDO::PARAM_LOB);
        $statement->bindValue('http_status', $httpStatus, $httpStatus === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('source_symbol', $sourceSymbol);
        $statement->execute();
    }

    /**
     * El JSON original de la version mas reciente de un
     * `(ticker, api_version, section)`, o `null` si no hay ninguna
     * archivada.
     */
    public function latestFor(string $ticker, string $apiVersion, string $section): ?string
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT payload_compressed FROM eodhd_raw_fundamental_versions
             WHERE ticker = :ticker AND api_version = :api_version AND section = :section
             ORDER BY fetched_at DESC, id DESC
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
