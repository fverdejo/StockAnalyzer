<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateInterval;
use DateTimeImmutable;
use JsonException;
use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * Cache del resultado completo de `BacktestingService::backtestTicker()`
 * (modo 'full', el que ve el usuario real) por ticker/horizonte/paso. Ver
 * versions.md v2.34: evita recalcular un backtest completo de hasta ~50
 * tickers de forma sincrona en cada peticion del historial de señal.
 */
class TickerBacktestCacheRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $ticker, int $horizonDays, int $step, DateInterval $ttl): ?array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT result_payload, cached_at
             FROM ticker_backtest_cache
             WHERE ticker = :ticker AND horizon_days = :horizon_days AND step = :step'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'horizon_days' => $horizonDays,
            'step' => $step,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || !is_string($row['result_payload'] ?? null) || !$this->isFresh($row['cached_at'] ?? null, $ttl)) {
            return null;
        }

        try {
            $payload = json_decode((string) $row['result_payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string,mixed> $result
     */
    public function save(string $ticker, int $horizonDays, int $step, array $result): void
    {
        $payload = json_encode($result, JSON_THROW_ON_ERROR);
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO ticker_backtest_cache (ticker, horizon_days, step, result_payload, cached_at, updated_at)
             VALUES (:ticker, :horizon_days, :step, :payload, NOW(), NOW())
             ON DUPLICATE KEY UPDATE result_payload = VALUES(result_payload), cached_at = NOW(), updated_at = NOW()'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'horizon_days' => $horizonDays,
            'step' => $step,
            'payload' => $payload,
        ]);
    }

    private function isFresh(mixed $cachedAt, DateInterval $ttl): bool
    {
        if (!is_string($cachedAt) || $cachedAt === '') {
            return false;
        }

        $minimum = (new DateTimeImmutable())->sub($ttl);

        return new DateTimeImmutable($cachedAt) >= $minimum;
    }
}
