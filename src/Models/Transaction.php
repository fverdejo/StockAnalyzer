<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

use DateTimeImmutable;
use StockAnalyzer\Enums\TransactionType;

class Transaction
{
    public function __construct(
        private readonly int $id,
        private readonly int $userId,
        private readonly string $ticker,
        private readonly TransactionType $type,
        private readonly float $quantity,
        private readonly float $price,
        private readonly DateTimeImmutable $executedAt
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getType(): TransactionType
    {
        return $this->type;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getExecutedAt(): DateTimeImmutable
    {
        return $this->executedAt;
    }
}
