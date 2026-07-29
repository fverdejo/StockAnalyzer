<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\Alert;
use StockAnalyzer\Models\User;

/**
 * Alertas basicas (ver versions.md v2.15): avisos dentro de la propia web
 * cuando una accion de la cartera o de la watchlist cambia de
 * recomendacion. Quien decide cuando crear una fila aqui es
 * Services\AlertService; este repositorio solo persiste y consulta.
 */
class AlertRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function create(User $user, string $ticker, string $message): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO alerts (user_id, ticker, message, created_at) VALUES (:user_id, :ticker, :message, NOW())'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
            'message' => $message,
        ]);
    }

    public function countUnread(User $user): int
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT COUNT(*) FROM alerts WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $user->getId()]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<Alert>
     */
    public function findRecentByUser(User $user, int $limit = 30): array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT id, ticker, message, created_at, read_at
             FROM alerts
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue('user_id', $user->getId(), PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): Alert => new Alert(
                (int) $row['id'],
                (string) $row['ticker'],
                (string) $row['message'],
                new DateTimeImmutable((string) $row['created_at']),
                $row['read_at'] !== null ? new DateTimeImmutable((string) $row['read_at']) : null
            ),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function markAllRead(User $user): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'UPDATE alerts SET read_at = NOW() WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $user->getId()]);
    }
}
