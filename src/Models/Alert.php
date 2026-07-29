<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

use DateTimeImmutable;

class Alert
{
    public function __construct(
        private readonly int $id,
        private readonly string $ticker,
        private readonly string $message,
        private readonly DateTimeImmutable $createdAt,
        private readonly ?DateTimeImmutable $readAt
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
