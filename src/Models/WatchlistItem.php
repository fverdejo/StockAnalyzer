<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

use DateTimeImmutable;

class WatchlistItem
{
    public function __construct(
        private readonly int $id,
        private readonly int $userId,
        private readonly string $ticker,
        private readonly DateTimeImmutable $addedAt
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

    public function getAddedAt(): DateTimeImmutable
    {
        return $this->addedAt;
    }
}
