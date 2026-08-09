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
    /**
     * Cuantas alertas se muestran como maximo en la pagina de alertas. Es
     * una constante y no un numero suelto porque la pagina avisa al
     * usuario de que esta viendo solo las N mas recientes.
     */
    public const RECENT_LIMIT = 30;

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
    public function findRecentByUser(User $user, int $limit = self::RECENT_LIMIT): array
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

        return $this->mapRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Filtro "sin leer" de la pagina de alertas. Es un metodo aparte y no
     * un flag booleano en findRecentByUser para que la consulta que usa el
     * indice idx_alerts_user_unread (user_id, read_at) sea explicita.
     *
     * @return list<Alert>
     */
    public function findRecentUnreadByUser(User $user, int $limit = self::RECENT_LIMIT): array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT id, ticker, message, created_at, read_at
             FROM alerts
             WHERE user_id = :user_id AND read_at IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue('user_id', $user->getId(), PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $this->mapRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function markAllRead(User $user): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'UPDATE alerts SET read_at = NOW() WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $user->getId()]);
    }

    /**
     * Marcar/desmarcar y borrar una alerta concreta. El id llega del POST
     * del cliente, asi que el WHERE filtra siempre tambien por user_id: sin
     * esa condicion cualquiera podria tocar alertas ajenas iterando ids.
     */
    public function markRead(User $user, int $alertId): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'UPDATE alerts SET read_at = NOW() WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $alertId,
            'user_id' => $user->getId(),
        ]);
    }

    public function markUnread(User $user, int $alertId): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'UPDATE alerts SET read_at = NULL WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $alertId,
            'user_id' => $user->getId(),
        ]);
    }

    public function delete(User $user, int $alertId): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'DELETE FROM alerts WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $alertId,
            'user_id' => $user->getId(),
        ]);
    }

    public function deleteRead(User $user): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'DELETE FROM alerts WHERE user_id = :user_id AND read_at IS NOT NULL'
        );
        $statement->execute(['user_id' => $user->getId()]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return list<Alert>
     */
    private function mapRows(array $rows): array
    {
        return array_map(
            static fn (array $row): Alert => new Alert(
                (int) $row['id'],
                (string) $row['ticker'],
                (string) $row['message'],
                new DateTimeImmutable((string) $row['created_at']),
                $row['read_at'] !== null ? new DateTimeImmutable((string) $row['read_at']) : null
            ),
            $rows
        );
    }
}
