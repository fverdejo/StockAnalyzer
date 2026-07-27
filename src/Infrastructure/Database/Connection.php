<?php

declare(strict_types=1);

namespace StockAnalyzer\Infrastructure\Database;

use PDO;
use RuntimeException;

class Connection
{
    private ?PDO $pdo = null;

    public function getPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $this->loadDotenv();

        $dsn = $this->env('DB_DSN', 'mysql:host=127.0.0.1;port=3306;dbname=stock_analyzer;charset=utf8mb4');
        $user = $this->env('DB_USER', 'root');
        $password = $this->env('DB_PASSWORD', '');

        if ($dsn === '') {
            throw new RuntimeException('DB_DSN is empty.');
        }

        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $this->pdo;
    }

    private function loadDotenv(): void
    {
        if (!class_exists(\Dotenv\Dotenv::class)) {
            return;
        }

        $root = dirname(__DIR__, 3);

        if (!is_file($root . '/.env')) {
            return;
        }

        \Dotenv\Dotenv::createImmutable($root)->safeLoad();
    }

    private function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : $default;
    }
}
