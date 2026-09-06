<?php

declare(strict_types=1);

namespace StockAnalyzer\Enums;

/**
 * Clasificacion de D2 ("Cambio interanual", ver
 * Services\FundamentalChangeAssessor): por mayoria de signo entre los
 * factores disponibles, nunca por magnitud con un umbral inventado.
 *
 * `ESTABLE` y `MIXTO` son casos distintos (correccion de Codex,
 * 2026-09-06): `ESTABLE` significa que NINGUN factor mostro un cambio real
 * (todos nulos o dentro del ruido de `FundamentalChangeFactor::NOISE_EPSILON`);
 * `MIXTO` significa que SI hubo cambios reales, pero en el mismo numero en
 * cada direccion (empate entre factores que mejoran y que empeoran). Antes
 * ambos casos se etiquetaban `ESTABLE`, lo que hacia parecer "sin cambio"
 * a una empresa con la mitad de sus factores mejorando y la otra mitad
 * empeorando.
 */
enum FundamentalChangeVerdict: string
{
    case MEJORANDO = 'mejorando';
    case ESTABLE = 'estable';
    case MIXTO = 'mixto';
    case DETERIORANDO = 'deteriorando';
    case NO_EVALUABLE = 'no_evaluable';

    public function label(): string
    {
        return match ($this) {
            self::MEJORANDO => 'Mejorando',
            self::ESTABLE => 'Estable',
            self::MIXTO => 'Mixto',
            self::DETERIORANDO => 'Deteriorando',
            self::NO_EVALUABLE => 'No evaluable',
        };
    }
}
