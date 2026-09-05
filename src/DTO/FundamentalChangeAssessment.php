<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Enums\FundamentalChangeVerdict;

/**
 * D2 del diagnostico fundamental ("Cambio interanual", ver
 * Services\FundamentalChangeAssessor): compara el `Fundamentals` actual
 * de una accion contra su snapshot de hace ~365 dias y clasifica la
 * tendencia por mayoria de signo entre los factores disponibles.
 * Puramente informativo: `ScoreCalculator`/`Score`/`config/weights.php` no
 * conocen este DTO ni lo usan para puntuar.
 *
 * `sectorExcluded` usa el mismo criterio que
 * `DTO\FundamentalHealthAssessment` (bancos/aseguradoras e inmobiliarias,
 * ver `Services\FundamentalSectorExclusion`): cuando es `true`, `verdict`
 * es `NO_EVALUABLE` y `factors` esta vacio, y la vista debe mostrar solo
 * la nota de exclusion.
 *
 * `factors` puede tener menos de 2 elementos (incluido 0) aunque
 * `verdict` no sea `NO_EVALUABLE` por exclusion de sector: en ese caso el
 * propio conteo de factores por debajo de 2 es la razon de que
 * `verdict === NO_EVALUABLE` (ver `FundamentalChangeAssessor::assess()`).
 * Se expone la lista igualmente (aunque tenga 0 o 1 elemento) para que la
 * vista pueda mostrar que se comparo, si algo, incluso cuando el
 * veredicto agregado no es concluyente.
 */
final class FundamentalChangeAssessment
{
    /**
     * @param list<FundamentalChangeFactor> $factors
     */
    public function __construct(
        public readonly bool $sectorExcluded,
        public readonly FundamentalChangeVerdict $verdict,
        public readonly array $factors
    ) {
    }

    public static function sectorExcludedResult(): self
    {
        return new self(true, FundamentalChangeVerdict::NO_EVALUABLE, []);
    }

    /**
     * Sin snapshot de hace un año en `fundamentals_history`: "No
     * evaluable: sin historico suficiente" (no hay nada que comparar, ni
     * siquiera un unico factor).
     */
    public static function noEvaluableResult(): self
    {
        return new self(false, FundamentalChangeVerdict::NO_EVALUABLE, []);
    }
}
