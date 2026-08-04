<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateInterval;
use DateTimeImmutable;
use JsonException;
use PDO;
use StockAnalyzer\DTO\DividendPayment;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Services\MarketDataSerializer;

class MarketDataCacheRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function findStock(string $ticker, DateInterval $ttl): ?Stock
    {
        $row = $this->findRow($ticker);

        if ($row === null || !is_string($row['stock_payload'] ?? null) || !$this->isFresh($row['stock_cached_at'] ?? null, $ttl)) {
            return null;
        }

        try {
            $payload = json_decode((string) $row['stock_payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? MarketDataSerializer::stockFromArray($payload) : null;
    }

    public function saveStock(string $ticker, Stock $stock): void
    {
        $payload = json_encode(MarketDataSerializer::stockToArray($stock), JSON_THROW_ON_ERROR);
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO market_data_cache (ticker, stock_payload, stock_cached_at, updated_at)
             VALUES (:ticker, :payload, NOW(), NOW())
             ON DUPLICATE KEY UPDATE stock_payload = VALUES(stock_payload), stock_cached_at = NOW(), updated_at = NOW()'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'payload' => $payload,
        ]);
    }

    /**
     * @return list<HistoricalQuote>|null
     */
    public function findHistory(string $ticker, DateInterval $ttl): ?array
    {
        $row = $this->findRow($ticker);

        if ($row === null || !is_string($row['history_payload'] ?? null) || !$this->isFresh($row['history_cached_at'] ?? null, $ttl)) {
            return null;
        }

        try {
            $payload = json_decode((string) $row['history_payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? MarketDataSerializer::historyFromArray($payload) : null;
    }

    /**
     * @param list<HistoricalQuote> $quotes
     */
    public function saveHistory(string $ticker, array $quotes): void
    {
        $payload = json_encode(MarketDataSerializer::historyToArray($quotes), JSON_THROW_ON_ERROR);
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO market_data_cache (ticker, history_payload, history_cached_at, updated_at)
             VALUES (:ticker, :payload, NOW(), NOW())
             ON DUPLICATE KEY UPDATE history_payload = VALUES(history_payload), history_cached_at = NOW(), updated_at = NOW()'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'payload' => $payload,
        ]);
    }

    /**
     * @return list<DividendPayment>|null
     */
    public function findDividendHistory(string $ticker, DateInterval $ttl): ?array
    {
        $row = $this->findRow($ticker);

        if (
            $row === null
            || !is_string($row['dividend_history_payload'] ?? null)
            || !$this->isFresh($row['dividend_history_cached_at'] ?? null, $ttl)
        ) {
            return null;
        }

        try {
            $payload = json_decode((string) $row['dividend_history_payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? MarketDataSerializer::dividendHistoryFromArray($payload) : null;
    }

    /**
     * @param list<DividendPayment> $payments
     */
    public function saveDividendHistory(string $ticker, array $payments): void
    {
        $payload = json_encode(MarketDataSerializer::dividendHistoryToArray($payments), JSON_THROW_ON_ERROR);
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO market_data_cache (ticker, dividend_history_payload, dividend_history_cached_at, updated_at)
             VALUES (:ticker, :payload, NOW(), NOW())
             ON DUPLICATE KEY UPDATE dividend_history_payload = VALUES(dividend_history_payload), dividend_history_cached_at = NOW(), updated_at = NOW()'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'payload' => $payload,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRow(string $ticker): ?array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT ticker, stock_payload, stock_cached_at, history_payload, history_cached_at,
                    dividend_history_payload, dividend_history_cached_at
             FROM market_data_cache
             WHERE ticker = :ticker'
        );
        $statement->execute(['ticker' => strtoupper($ticker)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
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
