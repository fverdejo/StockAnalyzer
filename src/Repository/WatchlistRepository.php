<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\User;
use StockAnalyzer\Models\WatchlistItem;

/**
 * Persistencia de la watchlist personal (ver versions.md v2.14): lista de
 * tickers que un usuario quiere seguir sin necesidad de "comprarlos" en la
 * cartera simulada.
 */
class WatchlistRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Idempotente: anadir un ticker ya seguido no hace nada (no duplica
     * ni cambia la fecha original de seguimiento).
     */
    public function add(User $user, string $ticker): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO watchlist_items (user_id, ticker, added_at)
             VALUES (:user_id, :ticker, NOW())
             ON DUPLICATE KEY UPDATE ticker = ticker'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
        ]);
    }

    public function remove(User $user, string $ticker): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'DELETE FROM watchlist_items WHERE user_id = :user_id AND ticker = :ticker'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
        ]);
    }

    public function isWatched(User $user, string $ticker): bool
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT 1 FROM watchlist_items WHERE user_id = :user_id AND ticker = :ticker'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<WatchlistItem>
     */
    public function findByUser(User $user): array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT id, user_id, ticker, added_at FROM watchlist_items WHERE user_id = :user_id ORDER BY added_at ASC, id ASC'
        );
        $statement->execute(['user_id' => $user->getId()]);

        return array_map(
            static fn (array $row): WatchlistItem => new WatchlistItem(
                (int) $row['id'],
                (int) $row['user_id'],
                (string) $row['ticker'],
                new DateTimeImmutable((string) $row['added_at'])
            ),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}
