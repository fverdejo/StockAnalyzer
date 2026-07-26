<?php

declare(strict_types=1);

namespace StockAnalyzer\Enums;

enum SignalVerdict: string
{
    case POSITIVE = 'positive';
    case NEUTRAL = 'neutral';
    case NEGATIVE = 'negative';

    public function label(): string
    {
        return match ($this) {
            self::POSITIVE => 'Positivo',
            self::NEUTRAL => 'Neutral',
            self::NEGATIVE => 'Negativo',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::POSITIVE => 'signal-positive',
            self::NEUTRAL => 'signal-neutral',
            self::NEGATIVE => 'signal-negative',
        };
    }
}
