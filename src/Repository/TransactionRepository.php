<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Models\Transaction;
use StockAnalyzer\Models\User;

class TransactionRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function add(User $user, string $ticker, TransactionType $type, float $quantity, float $price): Transaction
    {
        $pdo = $this->connection->getPdo();
        $statement = $pdo->prepare(
            'INSERT INTO transactions (user_id, ticker, type, quantity, price, executed_at)
             VALUES (:user_id, :ticker, :type, :quantity, :price, NOW())'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
            'type' => $type->value,
            'quantity' => $quantity,
            'price' => $price,
        ]);

        return $this->findById((int) $pdo->lastInsertId());
    }

    /**
     * @return list<Transaction>
     */
    public function findByUser(User $user): array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT id, user_id, ticker, type, quantity, price, executed_at
             FROM transactions
             WHERE user_id = :user_id
             ORDER BY executed_at ASC, id ASC'
        );
        $statement->execute(['user_id' => $user->getId()]);

        return $this->mapTransactions($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<Transaction>
     */
    public function findByUserAndTicker(User $user, string $ticker): array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT id, user_id, ticker, type, quantity, price, executed_at
             FROM transactions
             WHERE user_id = :user_id AND ticker = :ticker
             ORDER BY executed_at ASC, id ASC'
        );
        $statement->execute([
            'user_id' => $user->getId(),
            'ticker' => strtoupper($ticker),
        ]);

        return $this->mapTransactions($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function findById(int $id): Transaction
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT id, user_id, ticker, type, quantity, price, executed_at FROM transactions WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new \RuntimeException('Transaction was not found after insert.');
        }

        return $this->mapTransaction($row);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return list<Transaction>
     */
    private function mapTransactions(array $rows): array
    {
        return array_map(fn (array $row): Transaction => $this->mapTransaction($row), $rows);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapTransaction(array $row): Transaction
    {
        return new Transaction(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['ticker'],
            TransactionType::from((string) $row['type']),
            (float) $row['quantity'],
            (float) $row['price'],
            new DateTimeImmutable((string) $row['executed_at'])
        );
    }
}
