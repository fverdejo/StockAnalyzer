<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\User;

class UserRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function create(string $email, string $passwordHash): User
    {
        $pdo = $this->connection->getPdo();
        $statement = $pdo->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (:email, :password_hash, NOW())');
        $statement->execute([
            'email' => strtolower($email),
            'password_hash' => $passwordHash,
        ]);

        $user = $this->findById((int) $pdo->lastInsertId());

        if (!$user instanceof User) {
            throw new \RuntimeException('User was not found after insert.');
        }

        return $user;
    }

    public function findById(int $id): ?User
    {
        $statement = $this->connection->getPdo()->prepare('SELECT id, email, created_at FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapUser($row) : null;
    }

    /**
     * @return array{user: User, password_hash: string}|null
     */
    public function findCredentialsByEmail(string $email): ?array
    {
        $statement = $this->connection->getPdo()->prepare('SELECT id, email, password_hash, created_at FROM users WHERE email = :email');
        $statement->execute(['email' => strtolower($email)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'user' => $this->mapUser($row),
            'password_hash' => (string) $row['password_hash'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapUser(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['email'],
            new DateTimeImmutable((string) $row['created_at'])
        );
    }
}
