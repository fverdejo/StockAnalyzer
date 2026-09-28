<?php

declare(strict_types=1);

namespace StockAnalyzer\Enums;

use InvalidArgumentException;

/**
 * Estado de gobernanza de un resultado de medicion del motor de Stock
 * Analyzer (C10, predeclaracion cerrada de `auditor-estadistico` tras la
 * auditoria de Astra/Codex del 2026-09-28,
 * `REVISION_FIABILIDAD_POST_CLAUDE_ASTRA_2026-09-28.md`; ver `versions.md`).
 * Convierte en estructural la politica ya informal de este proyecto ("nunca
 * tocar `config/weights.php` sin un resultado claro"): solo
 * `VALIDADO_PROVISIONAL` puede justificar un cambio de produccion
 * (recomendaciones, pesos, alertas).
 *
 * Se calcula por METRICA, nunca por documento/estudio completo (una
 * medicion puede reportar varias metricas -- primaria/secundarias -- con
 * informatividad distinta cada una); vease `Services\StudyVerdictStatusResolver`.
 */
enum StudyVerdictStatus: string
{
    /**
     * Nunca hubo intencion de test formal (diagnosticos puramente
     * descriptivos, p.ej. give-back/A-B del trailing o Bollinger/RSI del
     * 2026-09-26). Nunca bloquea ni autoriza nada porque nunca lo intento.
     */
    case DESCRIPTIVO = 'descriptivo';

    /**
     * Test formal intentado, resolucion insuficiente
     * (`bootstrap_has_enough_resolution=false` o `effective_n` por debajo
     * del minimo). Sustituye en adelante los usos sueltos de `INDET`/
     * `INSUFICIENTE`/`NO_INFORMATIVO` de `versions.md` -- mismo
     * significado, un solo nombre desde ahora. Los documentos ya cerrados
     * NO se reescriben retroactivamente (mismo criterio que
     * `INVALIDADO_METODO`: se anota, no se edita el historial).
     */
    case NO_INFORMATIVO = 'no_informativo';

    /**
     * Un resultado YA PUBLICADO se sabe defectuoso por un bug de
     * codigo/diseno descubierto despues (p.ej. C8: `exposure_end_date`
     * colapsado a `entry_date` en el protocolo D/E del 2026-09-22). Lleva
     * obligatoriamente `defect_ref` (p.ej. `"C8"`) y `superseded_by`
     * (referencia al resultado corregido, `null` mientras no exista).
     */
    case INVALIDADO_METODO = 'invalidado_metodo';

    /**
     * Resolucion suficiente (bloques/`effective_n` por encima del umbral),
     * pero el METODO de incertidumbre usado para construir el intervalo no
     * tiene cobertura empirica demostrada (C9 sin cerrar con exito, o
     * cerrado con `METODO_NO_CALIBRADO`). Distinto de `NO_INFORMATIVO`
     * (falta de potencia/resolucion) y de `INVALIDADO_METODO` (un bug, no
     * una brecha de calibracion generica). Aplica a todo resultado que use
     * la convencion 2xP90 legada mientras C9 no cierre con exito.
     */
    case METODO_NO_CALIBRADO = 'metodo_no_calibrado';

    /**
     * Resolucion suficiente, metodo CALIBRADO (C9 cerrado con exito), el
     * IC no excluye cero / no respalda la hipotesis. Nulo genuino con
     * cobertura acreditada -- el unico estado "sin ventaja" que puede
     * fiarse de su propio intervalo.
     */
    case INFORMATIVO_SIN_VENTAJA = 'informativo_sin_ventaja';

    /**
     * El UNICO estado que puede tocar produccion (recomendaciones, pesos,
     * alertas). Condiciones de entrada, TODAS obligatorias: C9 cerrado con
     * exito Y esta medicion concreta usa el metodo validado (no el legado
     * 2xP90) Y la pregunta estaba predeclarada Y hay datos point-in-time Y
     * existe una reserva temporal auditada (`holdout_window.audit_verified`)
     * Y, si toca RIESGO (`RiskLevelsConfig`/`SuggestedPositionCalculator`),
     * verificacion de cola (`tail_risk_verification.tail_risk_verified`).
     * Estas condiciones adicionales NO las comprueba este enum ni su
     * resolver (son procedimiento/documentacion, no aritmetica de un
     * bootstrap): quien persiste el resultado es responsable de no asignar
     * este valor sin haberlas verificado.
     */
    case VALIDADO_PROVISIONAL = 'validado_provisional';

    public function label(): string
    {
        return match ($this) {
            self::DESCRIPTIVO => 'Descriptivo (sin test formal)',
            self::NO_INFORMATIVO => 'No informativo (resolución insuficiente)',
            self::INVALIDADO_METODO => 'Invalidado (bug de método conocido)',
            self::METODO_NO_CALIBRADO => 'Método de incertidumbre no calibrado',
            self::INFORMATIVO_SIN_VENTAJA => 'Informativo, sin ventaja (nulo acreditado)',
            self::VALIDADO_PROVISIONAL => 'Validado (provisional)',
        };
    }

    /**
     * Gate estructural de C10: solo `VALIDADO_PROVISIONAL` puede justificar
     * un cambio de produccion (recomendaciones, pesos, alertas).
     */
    public function canAuthorizeProductionChange(): bool
    {
        return $this === self::VALIDADO_PROVISIONAL;
    }

    /**
     * Orden de "rollup" cuando hace falta un unico valor de presentacion
     * para un estudio con varias metricas: el estado mas conservador
     * (numero mas bajo) prevalece siempre. Nunca sustituye al gate real de
     * produccion, que es SIEMPRE por metrica individual
     * (`canAuthorizeProductionChange()`), nunca por este rollup.
     */
    public function rollupPriority(): int
    {
        return match ($this) {
            self::INVALIDADO_METODO => 1,
            self::NO_INFORMATIVO => 2,
            self::METODO_NO_CALIBRADO => 3,
            self::DESCRIPTIVO => 4,
            self::INFORMATIVO_SIN_VENTAJA => 5,
            self::VALIDADO_PROVISIONAL => 6,
        };
    }

    /**
     * @param list<self> $statuses
     */
    public static function rollup(array $statuses): self
    {
        if ($statuses === []) {
            throw new InvalidArgumentException('rollup() exige al menos un estado.');
        }

        usort($statuses, static fn (self $a, self $b): int => $a->rollupPriority() <=> $b->rollupPriority());

        return $statuses[0];
    }
}
