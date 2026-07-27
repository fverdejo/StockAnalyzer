<?php

declare(strict_types=1);

namespace StockAnalyzer\Auth;

use InvalidArgumentException;
use RuntimeException;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\UserRepository;

class AuthService
{
    private const SESSION_KEY = 'stock_analyzer_user';
    private const ATTEMPTS_KEY = 'stock_analyzer_login_attempts';
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly UserRepository $users
    ) {
        $this->ensureSessionStarted();
    }

    public function register(string $email, string $password): User
    {
        $email = $this->normalizeEmail($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Introduce un email valido.');
        }

        if (strlen($password) < 8) {
            throw new InvalidArgumentException('La contrasena debe tener al menos 8 caracteres.');
        }

        if ($this->users->findCredentialsByEmail($email) !== null) {
            throw new InvalidArgumentException('Ya existe una cuenta con ese email.');
        }

        $user = $this->users->create($email, password_hash($password, PASSWORD_DEFAULT));
        $this->setCurrentUser($user);

        return $user;
    }

    public function login(string $email, string $password): User
    {
        if ($this->getFailedAttempts() >= self::MAX_ATTEMPTS) {
            throw new RuntimeException('Demasiados intentos fallidos. Cierra el navegador o espera antes de intentarlo de nuevo.');
        }

        $credentials = $this->users->findCredentialsByEmail($this->normalizeEmail($email));

        if ($credentials === null || !password_verify($password, $credentials['password_hash'])) {
            $this->recordFailedAttempt();

            throw new InvalidArgumentException('Email o contrasena incorrectos.');
        }

        $_SESSION[self::ATTEMPTS_KEY] = 0;
        $this->setCurrentUser($credentials['user']);

        return $credentials['user'];
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public function currentUser(): ?User
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_array($stored)) {
            return null;
        }

        return new User(
            (int) ($stored['id'] ?? 0),
            (string) ($stored['email'] ?? ''),
            new \DateTimeImmutable((string) ($stored['created_at'] ?? 'now'))
        );
    }

    public function requireUser(): User
    {
        $user = $this->currentUser();

        if (!$user instanceof User) {
            throw new RuntimeException('Debes iniciar sesion para acceder a esta pagina.');
        }

        return $user;
    }

    private function setCurrentUser(User $user): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'created_at' => $user->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function getFailedAttempts(): int
    {
        return (int) ($_SESSION[self::ATTEMPTS_KEY] ?? 0);
    }

    private function recordFailedAttempt(): void
    {
        $_SESSION[self::ATTEMPTS_KEY] = $this->getFailedAttempts() + 1;
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
