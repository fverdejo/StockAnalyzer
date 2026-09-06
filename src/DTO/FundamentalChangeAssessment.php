<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use DateTimeImmutable;
use StockAnalyzer\Enums\FundamentalChangeVerdict;

/**
 * D2 del diagnostico fundamental ("Cambio interanual", ver
 * Services\FundamentalChangeAssessor): compara el `Fundamentals` actual
 * de una accion contra su snapshot de hace ~365 dias y clasifica la
 * tendencia por mayoria de signo entre los factores disponibles.
 * Puramente informativo: `ScoreCalculator`/`Score`/`config/weights.php` no
 * conocen este DTO ni lo usan para puntuar.
 *
 * IMPORTANTE (revision de Codex, 2026-09-06): el `Fundamentals` "actual"
 * viene del proveedor de mercado activo (Yahoo hoy, FMP si se cambia la
 * configuracion), pero el snapshot "anterior" siempre viene de
 * `fundamentals_history`, reconstruido enteramente desde EODHD. El
 * `verdict` puede reflejar una diferencia de PROVEEDOR y de FORMULA de
 * calculo, no solo un cambio real de la empresa. La vista debe mostrar
 * este aviso junto al veredicto, no presentarlo como una comparacion
 * homogenea.
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
 *
 * `previousSnapshotDate` (2026-09-06) es la fecha REAL del snapshot
 * comparado, tal y como la devolvio
 * `Repository\FundamentalsHistoryRepository::findAsOfWithDate()` -- nunca
 * asumir que es exactamente "hace un año": `findAsOf()`/`findAsOfWithDate()`
 * devuelven el snapshot anterior mas cercano disponible, que puede ser mas
 * antiguo. Es `null` cuando no hay ningun snapshot que mostrar
 * (`sectorExcludedResult()` o `noEvaluableResult()` sin fecha), y NO nulo
 * cuando `noEvaluableResult()` se usa para el caso "snapshot demasiado
 * antiguo" (ver `FundamentalChangeAssessor::MAX_SNAPSHOT_AGE_DAYS`), para
 * que la vista pueda decir exactamente que fecha se descarto y por que.
 */
final class FundamentalChangeAssessment
{
    /**
     * @param list<FundamentalChangeFactor> $factors
     */
    public function __construct(
        public readonly bool $sectorExcluded,
        public readonly FundamentalChangeVerdict $verdict,
        public readonly array $factors,
        public readonly ?DateTimeImmutable $previousSnapshotDate = null
    ) {
    }

    public static function sectorExcludedResult(): self
    {
        return new self(true, FundamentalChangeVerdict::NO_EVALUABLE, []);
    }

    /**
     * "No evaluable": o bien no hay ningun snapshot en `fundamentals_history`
     * de hace un año o antes (no hay nada que comparar, ni siquiera un
     * unico factor), o el snapshot mas cercano disponible es demasiado
     * antiguo (ver `FundamentalChangeAssessor::MAX_SNAPSHOT_AGE_DAYS`). En
     * el segundo caso se pasa `$previousSnapshotDate` para que la vista
     * pueda mostrar la fecha real descartada en vez de un generico "sin
     * historico".
     */
    public static function noEvaluableResult(?DateTimeImmutable $previousSnapshotDate = null): self
    {
        return new self(false, FundamentalChangeVerdict::NO_EVALUABLE, [], $previousSnapshotDate);
    }
}
