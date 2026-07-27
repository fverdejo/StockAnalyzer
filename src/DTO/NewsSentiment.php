<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

class NewsSentiment
{
    public function __construct(
        private readonly string $ticker,
        private readonly float $averageScore,
        private readonly int $count,
        private readonly ?string $headline,
        private readonly ?string $source
    ) {
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getAverageScore(): float
    {
        return $this->averageScore;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getHeadline(): ?string
    {
        return $this->headline;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }
}
