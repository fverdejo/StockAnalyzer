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
    } catch (PDOException $exception) {
        if (isAlreadyAppliedError($exception)) {
            $statement = $pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (:migration, NOW())');
            $statement->execute(['migration' => $name]);
            echo "WARN  {$name}: {$exception->getMessage()} -- el cambio ya existia en el esquema (aplicado por otra via antes de que schema_migrations lo registrara); se marca como aplicada sin repetirla.\n";
            continue;
        }

        throw $exception;
    }
}

echo "DONE\n";

/**
 * Un esquema puede llegar mas avanzado que schema_migrations (restauracion de
 * backup, cambio manual, migracion antigua nunca registrada). En ese caso el
 * DDL falla con un error de "ya existe" -- no es un fallo real de la
 * migracion, es que su efecto ya esta presente. Cualquier otro error se sigue
 * propagando tal cual.
 */
function isAlreadyAppliedError(PDOException $exception): bool
{
    $driverCode = $exception->errorInfo[1] ?? null;

    // 1050 tabla ya existe, 1060 columna duplicada, 1061 nombre de indice/clave duplicado.
    return in_array($driverCode, [1050, 1060, 1061], true);
}
