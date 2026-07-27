<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

use DateTimeImmutable;

class User
{
    public function __construct(
        private readonly int $id,
        private readonly string $email,
        private readonly DateTimeImmutable $createdAt
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
