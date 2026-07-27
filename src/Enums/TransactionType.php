<?php

declare(strict_types=1);

namespace StockAnalyzer\Enums;

enum TransactionType: string
{
    case BUY = 'buy';
    case SELL = 'sell';

    public function label(): string
    {
        return match ($this) {
            self::BUY => 'Compra',
            self::SELL => 'Venta',
        };
    }
}
