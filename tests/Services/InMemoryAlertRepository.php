<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use StockAnalyzer\Models\Alert;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\AlertRepository;

/**
 * AlertRepository en memoria para los tests: guarda las filas en un array
 * en vez de en la BD, reproduciendo el contrato del repositorio real,
 * incluido el filtro por user_id de todas las operaciones sobre una alerta
 * concreta (el id llega del POST del cliente: sin ese filtro cualquiera
 * podria tocar alertas ajenas).
 *
 * Extiende el repositorio real (no implementa una interfaz nueva: no
 * existe, y no se crea una solo por los tests) sin llamar a su
 * constructor, asi que nunca hay Connection ni PDO por medio.
 */
final class InMemoryAlertRepository extends AlertRepository
{
    /** @var list<array{id: int, user_id: int, ticker: string, message: string, created_at: DateTimeImmutable, read_at: ?DateTimeImmutable}> */
    private array $rows = [];

    private int $nextId = 1;

    public function __construct()
    {
    }

    public function create(User $user, string $ticker, string $message): void
    {
        $this->rows[] = [
            'id' => $this->nextId++,
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
            'message' => $message,
            'created_at' => new DateTimeImmutable(),
            'read_at' => null,
        ];
    }

    public function countUnread(User $user): int
    {
        return count(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['user_id'] === $user->getId() && $row['read_at'] === null
        ));
    }

    /**
     * @return list<Alert>
     */
    public function findRecentByUser(User $user, int $limit = AlertRepository::RECENT_LIMIT): array
    {
        return $this->find($user, $limit, false);
    }

    /**
     * @return list<Alert>
     */
    public function findRecentUnreadByUser(User $user, int $limit = AlertRepository::RECENT_LIMIT): array
    {
        return $this->find($user, $limit, true);
    }

    public function markAllRead(User $user): void
    {
        foreach ($this->rows as $index => $row) {
            if ($row['user_id'] === $user->getId() && $row['read_at'] === null) {
                $this->rows[$index]['read_at'] = new DateTimeImmutable();
            }
        }
    }

    public function markRead(User $user, int $alertId): void
    {
        $this->updateReadAt($user, $alertId, new DateTimeImmutable());
    }

    public function markUnread(User $user, int $alertId): void
    {
        $this->updateReadAt($user, $alertId, null);
    }

    public function delete(User $user, int $alertId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => !($row['id'] === $alertId && $row['user_id'] === $user->getId())
        ));
    }

    public function deleteRead(User $user): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => !($row['user_id'] === $user->getId() && $row['read_at'] !== null)
        ));
    }

    /**
     * @return list<array{ticker: string, message: string}>
     */
    public function created(): array
    {
        return array_values(array_map(
            static fn (array $row): array => ['ticker' => $row['ticker'], 'message' => $row['message']],
            $this->rows
        ));
    }

    public function countCreated(): int
    {
        return count($this->rows);
    }

    public function lastMessage(): ?string
    {
        $last = end($this->rows);

        return $last === false ? null : $last['message'];
    }

    /**
     * Ultimo id insertado, para que los tests puedan actuar sobre una
     * alerta concreta sin conocer la numeracion interna.
     */
    public function lastId(): int
    {
        $last = end($this->rows);

        return $last === false ? 0 : $last['id'];
    }

    public function isRead(int $alertId): ?bool
    {
        foreach ($this->rows as $row) {
            if ($row['id'] === $alertId) {
                return $row['read_at'] !== null;
            }
        }

        return null;
    }

    public function exists(int $alertId): bool
    {
        return $this->isRead($alertId) !== null;
    }

    private function updateReadAt(User $user, int $alertId, ?DateTimeImmutable $readAt): void
    {
        foreach ($this->rows as $index => $row) {
            if ($row['id'] === $alertId && $row['user_id'] === $user->getId()) {
                $this->rows[$index]['read_at'] = $readAt;
            }
        }
    }

    /**
     * @return list<Alert>
     */
    private function find(User $user, int $limit, bool $onlyUnread): array
    {
        $rows = array_filter(
            $this->rows,
            static fn (array $row): bool => $row['user_id'] === $user->getId()
                && (!$onlyUnread || $row['read_at'] === null)
        );

        $rows = array_reverse(array_values($rows));

        return array_map(
            static fn (array $row): Alert => new Alert(
                $row['id'],
                $row['ticker'],
                $row['message'],
                $row['created_at'],
                $row['read_at']
            ),
            array_slice($rows, 0, $limit)
        );
    }
}
