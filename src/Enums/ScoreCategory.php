<?php

declare(strict_types=1);

namespace StockAnalyzer\Enums;

enum ScoreCategory: string
{
    case TECHNICAL = 'technical';
    case FUNDAMENTAL = 'fundamental';
    case VALUATION = 'valuation';
    case NEWS = 'news';
    case MOMENTUM = 'momentum';
    case RISK = 'risk';
    case QUALITY = 'quality';
    case DIVIDEND = 'dividend';

    public function label(): string
    {
        return match ($this) {
            self::TECHNICAL => 'Analisis tecnico',
            self::FUNDAMENTAL => 'Fundamentales',
            self::VALUATION => 'Valoracion',
            self::NEWS => 'Noticias',
            self::MOMENTUM => 'Momentum',
            self::RISK => 'Riesgo',
            self::QUALITY => 'Calidad',
            self::DIVIDEND => 'Dividendos',
        };
    }

    public function maxScore(): float
    {
        return match ($this) {
            self::TECHNICAL => 30,
            self::FUNDAMENTAL => 30,
            self::VALUATION => 20,
            self::NEWS => 10,
            self::MOMENTUM => 10,
            self::RISK => 10,
            self::QUALITY => 10,
            self::DIVIDEND => 5,
        };
    }
}
