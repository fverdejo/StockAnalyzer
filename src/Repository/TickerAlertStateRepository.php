<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\User;

/**
 * Guarda, por usuario y ticker, la ultima recomendacion vista (ver
 * versions.md v2.15). Es el "antes" contra el que Services\AlertService
 * compara la recomendacion actual para decidir si ha cambiado.
 */
class TickerAlertStateRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getLastRecommendation(User $user, string $ticker): ?string
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT last_recommendation FROM ticker_alert_state WHERE user_id = :user_id AND ticker = :ticker'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
        ]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function setLastRecommendation(User $user, string $ticker, string $recommendation): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO ticker_alert_state (user_id, ticker, last_recommendation, updated_at)
             VALUES (:user_id, :ticker, :recommendation, NOW())
             ON DUPLICATE KEY UPDATE last_recommendation = VALUES(last_recommendation), updated_at = NOW()'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
            'recommendation' => $recommendation,
        ]);
    }
}
