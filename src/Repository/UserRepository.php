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

    private const SELECT_COLUMNS = 'id, email, password_hash, created_at, email_verified_at, verification_token, verification_expires_at';

    /**
     * Crea el usuario sin verificar (ver versions.md v2.11): guarda un
     * token de verificacion con caducidad de 24h en la misma fila, en vez
     * de una tabla aparte, porque solo hace falta un token vivo a la vez
     * por usuario.
     */
    public function create(string $email, string $passwordHash, string $verificationToken): User
    {
        $pdo = $this->connection->getPdo();
        $statement = $pdo->prepare(
            'INSERT INTO users (email, password_hash, created_at, verification_token, verification_expires_at)
             VALUES (:email, :password_hash, NOW(), :verification_token, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
        );
        $statement->execute([
            'email' => strtolower($email),
            'password_hash' => $passwordHash,
            'verification_token' => $verificationToken,
        ]);

        $user = $this->findById((int) $pdo->lastInsertId());

        if (!$user instanceof User) {
            throw new \RuntimeException('User was not found after insert.');
        }

        return $user;
    }

    public function findById(int $id): ?User
    {
        $statement = $this->connection->getPdo()->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapUser($row) : null;
    }

    /**
     * @return array{user: User, password_hash: string}|null
     */
    public function findCredentialsByEmail(string $email): ?array
    {
        $statement = $this->connection->getPdo()->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE email = :email');
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
     * @return array{id: int, email: string, token: string, expires_at: DateTimeImmutable}|null
     */
    public function findPendingVerification(string $token): ?array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT id, email, verification_token, verification_expires_at
             FROM users
             WHERE verification_token = :token AND email_verified_at IS NULL'
        );
        $statement->execute(['token' => $token]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || $row['verification_expires_at'] === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'token' => (string) $row['verification_token'],
            'expires_at' => new DateTimeImmutable((string) $row['verification_expires_at']),
        ];
    }

    public function markEmailVerified(int $id): void
    {
        $statement = $this->connection->getPdo()->prepare(
            'UPDATE users SET email_verified_at = NOW(), verification_token = NULL, verification_expires_at = NULL WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
    }

    /**
     * Genera un nuevo token para reenviar el correo de confirmacion.
     * Devuelve null si el email no existe o ya esta verificado (para no
     * revelar cuentas existentes a quien no las conoce).
     */
    public function regenerateVerificationToken(string $email, string $newToken): ?User
    {
        $pdo = $this->connection->getPdo();
        $statement = $pdo->prepare(
            'UPDATE users
             SET verification_token = :token, verification_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
             WHERE email = :email AND email_verified_at IS NULL'
        );
        $statement->execute(['token' => $newToken, 'email' => strtolower($email)]);

        if ($statement->rowCount() === 0) {
            return null;
        }

        $credentials = $this->findCredentialsByEmail($email);

        return $credentials['user'] ?? null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapUser(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['email'],
            new DateTimeImmutable((string) $row['created_at']),
            $row['email_verified_at'] !== null ? new DateTimeImmutable((string) $row['email_verified_at']) : null
        );
    }
}
