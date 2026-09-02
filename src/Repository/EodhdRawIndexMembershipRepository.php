<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * Archivo de la respuesta CRUDA de EODHD para un indice
 * (`/api/fundamentals/{INDICE}.INDX`), sin transformar
 * (`021_create_eodhd_raw_index_membership.sql`, roadmap.md "Segundo
 * bloque" punto 1).
 *
 * Mismo patron que `EodhdRawFundamentalsRepository` (UPSERT por clave,
 * hash sha256 para verificar integridad sin decodificar), con `index_code`
 * en vez de `ticker` como clave.
 */
class EodhdRawIndexMembershipRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function has(string $indexCode): bool
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT 1 FROM eodhd_raw_index_membership WHERE index_code = :index_code LIMIT 1'
        );
        $statement->execute(['index_code' => strtoupper($indexCode)]);

        return $statement->fetchColumn() !== false;
    }

    public function store(
        string $indexCode,
        string $payloadJson,
        bool $hasPointInTime,
        ?DateTimeImmutable $fetchedAt = null
    ): void {
        $fetchedAt ??= new DateTimeImmutable();
        $hash = hash('sha256', $payloadJson);

        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO eodhd_raw_index_membership (index_code, payload_json, payload_hash, has_point_in_time, fetched_at)
             VALUES (:index_code, :payload_json, :payload_hash, :has_point_in_time, :fetched_at)
             ON DUPLICATE KEY UPDATE
                payload_json = VALUES(payload_json),
                payload_hash = VALUES(payload_hash),
                has_point_in_time = VALUES(has_point_in_time),
                fetched_at = VALUES(fetched_at)'
        );
        $statement->execute([
            'index_code' => strtoupper($indexCode),
            'payload_json' => $payloadJson,
            'payload_hash' => $hash,
            'has_point_in_time' => $hasPointInTime ? 1 : 0,
            'fetched_at' => $fetchedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function find(string $indexCode): ?string
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT payload_json FROM eodhd_raw_index_membership WHERE index_code = :index_code LIMIT 1'
        );
        $statement->execute(['index_code' => strtoupper($indexCode)]);
        $payload = $statement->fetchColumn();

        return is_string($payload) ? $payload : null;
    }

    public function count(): int
    {
        $statement = $this->connection->getPdo()->query('SELECT COUNT(*) FROM eodhd_raw_index_membership');

        return (int) $statement->fetchColumn();
    }
}
