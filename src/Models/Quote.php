<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

class Quote
{
    public function __construct(
        private readonly float $price,
        private readonly float $open,
        private readonly float $high,
        private readonly float $low,
        private readonly float $close,
        private readonly int $volume,
        private readonly \DateTimeImmutable $date
    ) {
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getOpen(): float
    {
        return $this->open;
    }

    public function getHigh(): float
    {
        return $this->high;
    }

    public function getLow(): float
    {
        return $this->low;
    }

    public function getClose(): float
    {
        return $this->close;
    }

    public function getVolume(): int
    {
        return $this->volume;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }
}