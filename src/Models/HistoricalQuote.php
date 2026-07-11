<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

use DateTimeImmutable;

class HistoricalQuote
{
    public function __construct(
        private readonly DateTimeImmutable $date,
        private readonly float $open,
        private readonly float $high,
        private readonly float $low,
        private readonly float $close,
        private readonly int $volume
    ) {
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
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
}
