<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\Transaction;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\TransactionRepository;

/**
 * TransactionRepository en memoria, mismo patron que
 * InMemoryAlertRepository: extiende el repositorio real sin llamar a su
 * constructor, asi que no hay Connection ni PDO por medio.
 */
final class InMemoryTransactionRepository extends TransactionRepository
{
    /** @var list<Transaction> */
    private array $rows = [];

    private int $nextId = 1;

    public function __construct()
    {
    }

    public function record(User $user, string $ticker, TransactionType $type, float $quantity, float $price, string $executedAt): void
    {
        $this->rows[] = new Transaction(
            $this->nextId++,
            $user->getId(),
            strtoupper($ticker),
            $type,
            $quantity,
            $price,
            new DateTimeImmutable($executedAt)
        );
    }

    /**
     * @return list<Transaction>
     */
    public function findByUser(User $user): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (Transaction $transaction): bool => $transaction->getUserId() === $user->getId()
        ));
    }

    /**
     * @return list<Transaction>
     */
    public function findByUserAndTicker(User $user, string $ticker): array
    {
        $ticker = strtoupper($ticker);

        return array_values(array_filter(
            $this->findByUser($user),
            static fn (Transaction $transaction): bool => $transaction->getTicker() === $ticker
        ));
    }
}
