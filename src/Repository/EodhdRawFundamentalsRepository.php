<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * Archivo de la respuesta CRUDA de EODHD (`/api/fundamentals/{ticker}`),
 * sin transformar (`019_create_eodhd_raw_fundamentals.sql`, ver
 * roadmap.md punto 2 de "Prioridad cero").
 *
 * Una fila por ticker (no por dia): una unica llamada a EODHD trae todo el
 * historico de golpe, asi que no hay snapshot diario que archivar, solo la
 * respuesta mas reciente conocida. Idempotente por ticker (UPSERT), igual
 * que `FundamentalsHistoryRepository`.
 *
 * `payload_hash` (sha256 del JSON tal cual llego) permite verificar
 * integridad sin decodificar el payload completo, y detectar si una
 * re-descarga trajo exactamente lo mismo (hash identico) o algo distinto
 * (EODHD reformulo cifras, o el ticker gano trimestres nuevos).
 */
class EodhdRawFundamentalsRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Si un ticker ya tiene una respuesta archivada con exito. Es lo que
     * hace la ingesta reanudable: un proceso cortado a mitad de camino no
     * vuelve a pedir lo que ya se guardo.
     */
    public function has(string $ticker): bool
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT 1 FROM eodhd_raw_fundamentals WHERE ticker = :ticker LIMIT 1'
        );
        $statement->execute(['ticker' => strtoupper($ticker)]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Guarda el JSON tal cual lo devolvio EODHD, sin transformar. El hash
     * se calcula aqui (no se confia en uno externo) para que siempre
     * corresponda exactamente al `payload_json` que queda escrito.
     */
    public function store(string $ticker, string $payloadJson, ?DateTimeImmutable $fetchedAt = null): void
    {
        $fetchedAt ??= new DateTimeImmutable();
        $hash = hash('sha256', $payloadJson);

        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO eodhd_raw_fundamentals (ticker, payload_json, payload_hash, fetched_at)
             VALUES (:ticker, :payload_json, :payload_hash, :fetched_at)
             ON DUPLICATE KEY UPDATE
                payload_json = VALUES(payload_json),
                payload_hash = VALUES(payload_hash),
                fetched_at = VALUES(fetched_at)'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'payload_json' => $payloadJson,
            'payload_hash' => $hash,
            'fetched_at' => $fetchedAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * El JSON archivado de un ticker, o `null` si nunca se archivo con
     * exito. Es lo que permite reconstruir `fundamentals_history` sin red
     * y sin gastar cuota de EODHD (roadmap.md, criterio de salida del
     * punto 2).
     */
    public function find(string $ticker): ?string
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT payload_json FROM eodhd_raw_fundamentals WHERE ticker = :ticker LIMIT 1'
        );
        $statement->execute(['ticker' => strtoupper($ticker)]);
        $payload = $statement->fetchColumn();

        return is_string($payload) ? $payload : null;
    }

    /**
     * Cuantos tickers distintos hay archivados. Sirve para el informe de
     * cobertura ("628/628 esperado") sin tener que contar a mano.
     */
    public function count(): int
    {
        $statement = $this->connection->getPdo()->query('SELECT COUNT(*) FROM eodhd_raw_fundamentals');

        return (int) $statement->fetchColumn();
    }

    /**
     * Todos los tickers ya archivados, para saltarlos en una ingesta
     * reanudable sin una consulta `has()` por ticker.
     *
     * @return list<string>
     */
    public function archivedTickers(): array
    {
        $statement = $this->connection->getPdo()->query('SELECT ticker FROM eodhd_raw_fundamentals');

        /** @var list<string> $tickers */
        $tickers = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return $tickers;
    }
}
