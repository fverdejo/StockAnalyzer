<?php

declare(strict_types=1);

namespace StockAnalyzer\Enums;

/**
 * Clasificacion de D2 ("Cambio interanual", ver
 * Services\FundamentalChangeAssessor): por mayoria de signo entre los
 * factores disponibles, nunca por magnitud con un umbral inventado.
 */
enum FundamentalChangeVerdict: string
{
    case MEJORANDO = 'mejorando';
    case ESTABLE = 'estable';
    case DETERIORANDO = 'deteriorando';
    case NO_EVALUABLE = 'no_evaluable';

    public function label(): string
    {
        return match ($this) {
            self::MEJORANDO => 'Mejorando',
            self::ESTABLE => 'Estable',
            self::DETERIORANDO => 'Deteriorando',
            self::NO_EVALUABLE => 'No evaluable',
        };
    }
}
