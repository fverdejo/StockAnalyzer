<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;

$root = dirname(__DIR__);
$migrationsPath = $root . '/database/migrations';
$files = glob($migrationsPath . '/*.sql') ?: [];
sort($files);

$pdo = (new Connection())->getPdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$appliedRows = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_fill_keys(array_map('strval', $appliedRows), true);

foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name])) {
        echo "SKIP {$name}\n";
        continue;
    }

    $sql = file_get_contents($file);

    if ($sql === false) {
        throw new RuntimeException("Could not read migration {$name}.");
    }

    try {
        $pdo->exec($sql);
        $statement = $pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (:migration, NOW())');
        $statement->execute(['migration' => $name]);
        echo "APPLIED {$name}\n";
    } catch (Throwable $exception) {
        throw $exception;
    }
}

echo "DONE\n";
