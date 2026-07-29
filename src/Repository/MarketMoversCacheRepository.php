<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateInterval;
use DateTimeImmutable;
use JsonException;
use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * Cache de las listas de "mas sube"/"mas baja" (ver versions.md v2.12).
 * Una sola fila por tipo ("gainers"/"losers"): no hace falta historico,
 * solo la ultima lista obtenida y cuando se obtuvo.
 */
class MarketMoversCacheRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return list<string>|null
     */
    public function find(string $kind, DateInterval $ttl): ?array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT tickers_payload, fetched_at FROM market_movers_cache WHERE kind = :kind'
        );
        $statement->execute(['kind' => $kind]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || !$this->isFresh((string) ($row['fetched_at'] ?? ''), $ttl)) {
            return null;
        }

        try {
            $tickers = json_decode((string) $row['tickers_payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($tickers) ? array_values(array_map('strval', $tickers)) : null;
    }

    /**
     * @param list<string> $tickers
     */
    public function save(string $kind, array $tickers): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO market_movers_cache (kind, tickers_payload, fetched_at)
             VALUES (:kind, :payload, NOW())
             ON DUPLICATE KEY UPDATE tickers_payload = VALUES(tickers_payload), fetched_at = NOW()'
        );
        $statement->execute([
            'kind' => $kind,
            'payload' => json_encode($tickers, JSON_THROW_ON_ERROR),
        ]);
    }

    private function isFresh(string $fetchedAt, DateInterval $ttl): bool
    {
        if ($fetchedAt === '') {
            return false;
        }

        $minimum = (new DateTimeImmutable())->sub($ttl);

        return new DateTimeImmutable($fetchedAt) >= $minimum;
    }
}
