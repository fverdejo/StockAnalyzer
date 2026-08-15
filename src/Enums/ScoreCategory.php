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
            // FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND a 0 en la rama
            // feature/solo-tecnico: el bloque fundamental nunca ha mostrado
            // ventaja demostrada en ningun backtest de este proyecto (ver
            // versions.md, investigacion del 2026-08-15), asi que la
            // recomendacion pasa a basarse solo en TECHNICAL+MOMENTUM+RISK
            // mientras se investiga una alternativa (fuerza relativa en
            // fundamentales, en la misma rama).
            //
            // Se cambia AQUI y no en config/weights.php porque
            // ScoreWeights::loadFile() descarta cualquier valor <= 0 como
            // override invalido y cae al maximo del enum: escribir
            // 'fundamental' => 0 en el fichero de config no tendria efecto
            // ninguno. Mismo mecanismo ya usado para retirar NEWS (ver
            // versions.md v2.37), generalizado a las 4 categorias.
            //
            // FundamentalAnalyzer sigue calculando estas cuatro categorias
            // sin cambios: es FundamentalAnalyzer::analyze() quien filtra
            // las que tienen maximo 0 antes de devolverlas, asi que no
            // generan ni puntos ni señales visibles (mismo criterio que
            // v2.39 aplico a NEWS), pero el codigo entero sigue intacto y
            // se reactiva solo con volver estos cuatro valores a sus
            // originales (30/20/10/5).
            self::FUNDAMENTAL => 0,
            self::VALUATION => 0,
            // NEWS a 0: sin señal real detras, news_items esta vacia en
            // produccion y NewsAnalyzer::analyze() siempre devolvia 5/10
            // constantes, distorsionando los umbrales de recomendacion sin
            // aportar informacion (validado con backtests, ver versions.md
            // v2.34 y la version que introduce este cambio).
            self::NEWS => 0,
            self::MOMENTUM => 10,
            self::RISK => 10,
            self::QUALITY => 0,
            self::DIVIDEND => 0,
        };
    }
}
