<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateInterval;
use DateTimeImmutable;
use JsonException;
use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * Cache del resultado combinado de `Providers\YahooCorporateProfileProvider::fetch()`
 * (descripcion/sector/industria enriquecidos, mas los dos campos de
 * `DTO\CorporateEvents`), por ticker. `quoteSummary` (modulo
 * assetProfile+calendarEvents) es "la pieza mas fragil de todo el
 * proveedor" (ver YahooFundamentalsFetcher): esta cache evita pedirselo a
 * Yahoo en cada visita a la ficha de detalle de un ticker y, sobre todo,
 * evita pedirselo una vez por cada ticker de la watchlist en cada visita
 * (ver Services\AlertService::checkUpcomingDividend()). Mismo patron
 * exacto que `TickerBacktestCacheRepository` (find()/save(), payload
 * JSON, isFresh(), ON DUPLICATE KEY UPDATE).
 */
class CorporateProfileCacheRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $ticker, DateInterval $ttl): ?array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT result_payload, cached_at FROM corporate_profile_cache WHERE ticker = :ticker'
        );
        $statement->execute(['ticker' => strtoupper($ticker)]);
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
    public function save(string $ticker, array $result): void
    {
        $payload = json_encode($result, JSON_THROW_ON_ERROR);
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO corporate_profile_cache (ticker, result_payload, cached_at, updated_at)
             VALUES (:ticker, :payload, NOW(), NOW())
             ON DUPLICATE KEY UPDATE result_payload = VALUES(result_payload), cached_at = NOW(), updated_at = NOW()'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
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
