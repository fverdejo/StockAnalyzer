<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Enums\StudyVerdictStatus;

/**
 * Calcula el `StudyVerdictStatus` de UNA metrica (C10.3, predeclaracion
 * cerrada de `auditor-estadistico`, 2026-09-28 -- ver `versions.md`), a
 * partir de los campos que YA produce `PolicyReplayStatistics::summarize()`/
 * `summarizePairedDiffsPrecise()` (`result_informative`, `ci_excludes_zero`)
 * mas un flag que el llamador debe declarar EXPLICITAMENTE (nunca inferido
 * de otro dato): si el metodo de incertidumbre usado esta calibrado (C9).
 *
 * NUNCA devuelve `DESCRIPTIVO` ni `INVALIDADO_METODO`: son hechos externos a
 * la aritmetica del bootstrap (que un estudio nunca pretendio ser un test
 * formal, o que se descubrio un bug despues de publicarlo) que el llamador
 * asigna directamente, no algo que este resolver pueda deducir.
 */
final class StudyVerdictStatusResolver
{
    /**
     * @param bool $resultInformative `result_informative` de `PolicyReplayStatistics`
     * @param ?bool $ciExcludesZero `ci_excludes_zero` de `PolicyReplayStatistics` (null si no informativo)
     * @param bool $bootstrapMethodCalibrated `true` solo si C9 cerro con exito Y esta metrica concreta usa el metodo validado (nunca el 2xP90 legado por defecto mientras C9 no cierre)
     * @param ?bool $formalHypothesisSupported `true` solo si el llamador comprobo TAMBIEN el umbral economico y el resto de condiciones predeclaradas. `null` nunca valida por si solo un IC que excluye cero.
     */
    public function resolve(
        bool $resultInformative,
        ?bool $ciExcludesZero,
        bool $bootstrapMethodCalibrated,
        ?bool $formalHypothesisSupported = null
    ): StudyVerdictStatus
    {
        if (!$resultInformative) {
            return StudyVerdictStatus::NO_INFORMATIVO;
        }

        if (!$bootstrapMethodCalibrated) {
            return StudyVerdictStatus::METODO_NO_CALIBRADO;
        }

        return $ciExcludesZero === true && $formalHypothesisSupported === true
            ? StudyVerdictStatus::VALIDADO_PROVISIONAL
            : StudyVerdictStatus::INFORMATIVO_SIN_VENTAJA;
    }
}
