<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * D1 del diagnostico fundamental ("Salud fundamental", ver
 * `Services\FundamentalHealthAssessor` y versions.md): estado ACTUAL de la
 * empresa, expresado como alertas de distress INDEPENDIENTES con umbrales
 * absolutos, nunca como una unica puntuacion combinada ni como un
 * percentil sectorial.
 *
 * Se descarto el percentil sectorial (como el que usa
 * `Services\RelativeFundamentalScorer` en el backtest) porque no hay una
 * fuente barata de pares del mismo sector para una ficha individual en
 * vivo: `daily_rankings` no guarda sector ni ratios crudos en su payload
 * JSON, y pedir fundamentales de todo el sector en cada carga de ficha
 * seria lento y caro (ver versions.md, entrada de esta tarea, para el
 * detalle completo).
 *
 * Puramente informativo: `ScoreCalculator`/`Score`/`config/weights.php` no
 * conocen este DTO ni lo usan para puntuar.
 *
 * `sectorExcluded` es `true` para bancos/aseguradoras e inmobiliarias (ver
 * `Services\FundamentalSectorExclusion`): esos ratios no son directamente
 * comparables a los de una empresa industrial. Cuando es `true`, el resto
 * de campos son irrelevantes (quedan a `false`/`null`, ver
 * `sectorExcludedResult()`) y la vista NUNCA debe intentar aplicarles los
 * mismos umbrales.
 *
 * `datosInsuficientes` es `true` solo cuando NINGUNO de los cuatro
 * factores (`roic`, `operatingMargin`, `debtToEquity`, `cashConversion`)
 * tiene dato: distingue "no hay informacion" de "no hay alertas", para
 * que la ausencia de dato nunca se lea como ausencia de alerta (la
 * leccion de P3.3, ver versions.md `2026-09-03`/`2026-09-04`: un dato
 * ausente no es lo mismo que un dato bueno).
 */
final class FundamentalHealthAssessment
{
    public function __construct(
        public readonly bool $sectorExcluded,
        public readonly bool $datosInsuficientes,
        /**
         * `true` cuando `Fundamentals::getDebtToEquity() === null`. El
         * nombre describe exactamente lo que se sabe, ni mas ni menos:
         * `debtToEquity` puede ser `null` por patrimonio no positivo O por
         * simple falta de dato de deuda en la fuente --
         * `PointInTimeFundamentalsBuilder` no distingue hoy los dos casos,
         * y este DTO/`FundamentalHealthAssessor` tampoco lo intentan
         * resolver. Deliberadamente NO se llama `patrimonioNoPositivo`
         * (una etiqueta con ese nombre afirmaria una causa concreta que no
         * esta confirmada): la vista debe mostrar "endeudamiento no
         * evaluable", nunca "patrimonio negativo confirmado".
         */
        public readonly bool $endeudamientoNoEvaluable,
        public readonly bool $fcfNegativo,
        public readonly bool $margenOperativoNegativo,
        public readonly ?float $roic,
        public readonly ?float $operatingMargin,
        public readonly ?float $debtToEquity,
        public readonly ?float $cashConversion
    ) {
    }

    /**
     * Sector financiero/inmobiliario: el resto de campos quedan vacios a
     * proposito, la vista no debe leerlos.
     */
    public static function sectorExcludedResult(): self
    {
        return new self(
            sectorExcluded: true,
            datosInsuficientes: false,
            endeudamientoNoEvaluable: false,
            fcfNegativo: false,
            margenOperativoNegativo: false,
            roic: null,
            operatingMargin: null,
            debtToEquity: null,
            cashConversion: null
        );
    }
}
