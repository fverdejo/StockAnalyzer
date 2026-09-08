<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use JsonException;
use StockAnalyzer\Infrastructure\Database\Connection;

class DailyRankingRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function save(string $name, array $tickers, array $payload, ?DateTimeImmutable $date = null): void
    {
        $date ??= new DateTimeImmutable();
        $hash = sha1(implode('|', array_map('strtoupper', $tickers)));
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO daily_rankings (ranking_date, name, tickers_hash, payload, created_at)
             VALUES (:ranking_date, :name, :tickers_hash, :payload, NOW())
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), created_at = NOW()'
        );
        $statement->execute([
            'ranking_date' => $date->format('Y-m-d'),
            'name' => $name,
            'tickers_hash' => $hash,
            'payload' => $json,
        ]);
    }

    /**
     * Solo la fecha del ranking mas reciente, sin decodificar el payload
     * entero -- lo usa AnalysisRefreshTrigger para decidir si falta el
     * snapshot de hoy sin pagar el coste de json_decode() en cada peticion.
     */
    public function latestDate(string $name): ?DateTimeImmutable
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT ranking_date FROM daily_rankings WHERE name = :name ORDER BY ranking_date DESC LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $date = $statement->fetchColumn();

        if (!is_string($date) || $date === '') {
            return null;
        }

        return DateTimeImmutable::createFromFormat('Y-m-d', $date) ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latest(string $name): ?array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT payload FROM daily_rankings WHERE name = :name ORDER BY ranking_date DESC, created_at DESC LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $payload = $statement->fetchColumn();

        if (!is_string($payload)) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
