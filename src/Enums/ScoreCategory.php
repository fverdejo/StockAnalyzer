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
            // NEWS a 0: sin señal real detras, news_items esta vacia en
            // produccion y NewsAnalyzer::analyze() siempre devolvia 5/10
            // constantes, distorsionando los umbrales de recomendacion sin
            // aportar informacion (validado con backtests, ver versions.md
            // v2.34 y la version que introduce este cambio).
            self::NEWS => 0,
            self::MOMENTUM => 10,
            self::RISK => 10,
            self::QUALITY => 10,
            self::DIVIDEND => 5,
        };
    }
}
