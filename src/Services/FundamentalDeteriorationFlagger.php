<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\DTO\FundamentalTtmSnapshot;

/**
 * E1 del plan de aprovechamiento de EODHD ("Deterioro fundamental",
 * `PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`, Bloque E):
 * bandera COMPUESTA -- no un ranking, no un promedio -- que marca si una
 * accion sufrio un deterioro fundamental simultaneo entre su TTM actual y
 * su TTM de hace un año.
 *
 * Formula EXACTA, predeclarada por `auditor-estadistico` el 2026-09-05
 * antes de medir nada:
 *
 *     deterioro = (margen BAJA Y ROIC BAJA Y FCF BAJA)
 *              O (deuda/patrimonio SUBE Y FCF BAJA)
 *
 * **Interpretacion de "caja" documentada aqui, no confirmada por el
 * auditor**: se usa flujo de caja libre en yield
 * (`Models\Fundamentals::getFreeCashFlowYield()`, el mismo que ya usa
 * `RelativeFundamentalScorer`/P3.3 para mantener consistencia con esa
 * medicion previa) porque no existe ningun campo de caja/tesoreria en el
 * balance de `Fundamentals` hoy. Si la intencion original del auditor era
 * otra ("caja" en el sentido contable de efectivo y equivalentes en
 * balance), este es el sitio a corregir -- no se ha inventado ningun campo
 * nuevo para evitar esa ambiguedad.
 *
 * Deliberadamente PURA (sin red ni base de datos), mismo patron que
 * `RelativeFundamentalScorer`: recibe los dos snapshots ya resueltos.
 * Quien los busca en `FundamentalsHistoryRepository::findAsOf()` en el
 * momento historico correspondiente es
 * `BacktestingService::runDeteriorationRiskAnalysis()`, no esta clase --
 * misma separacion de responsabilidades que ya existe entre
 * `RelativeFundamentalScorer` (puntua) y `BacktestingService`
 * (consulta/orquesta).
 *
 * Ausencia de dato en CUALQUIERA de las dos fechas EXCLUYE ese factor de
 * la formula -- nunca se cuenta como "no empeoro" (mismo criterio
 * "ausencia no es neutral" que `FundamentalChangeAssessor`/P3.3, ver sus
 * docblocks). Un factor sin dato no puede aportar el `true` afirmativo
 * que sus clausulas necesitan: la clausula que lo usa simplemente no se
 * satisface por esa via, sin que este codigo llegue nunca a decidir una
 * direccion (subida/bajada) para ese factor cuando falta el dato.
 *
 * Consecuencia directa de implementar la formula tal cual (documentada
 * aqui para poder revisarla si no era la intencion): las DOS clausulas
 * comparten "FCF BAJA", asi que sin dato de FCF yield -- en cualquiera de
 * las dos fechas -- la bandera nunca puede ser `true`, sea cual sea el
 * resto de factores.
 */
final class FundamentalDeteriorationFlagger
{
    /**
     * Umbral minimo de cambio para contarlo como subida/bajada real, mismo
     * valor y mismo motivo que `DTO\FundamentalChangeFactor::NOISE_EPSILON`:
     * solo descarta ruido de redondeo de coma flotante alrededor de un
     * cambio nulo, NO es un umbral de magnitud para filtrar cambios
     * pequeños de verdad.
     */
    private const NOISE_EPSILON = 0.001;

    public function isDeteriorating(FundamentalTtmSnapshot $current, FundamentalTtmSnapshot $previousYear): bool
    {
        $marginDown = $this->wentDown($current->operatingMargin, $previousYear->operatingMargin);
        $roicDown = $this->wentDown($current->roic, $previousYear->roic);
        $fcfDown = $this->wentDown($current->freeCashFlowYield, $previousYear->freeCashFlowYield);
        $debtUp = $this->wentUp($current->debtToEquity, $previousYear->debtToEquity);

        $qualityCollapse = $marginDown === true && $roicDown === true && $fcfDown === true;
        $leverageWithCashDrain = $debtUp === true && $fcfDown === true;

        return $qualityCollapse || $leverageWithCashDrain;
    }

    /**
     * `true` si bajo de verdad, `false` si no bajo (subio o se quedo
     * igual), `null` si falta el dato en cualquiera de las dos fechas --
     * ese `null` es lo que excluye el factor de `isDeteriorating()` sin
     * decidir nunca una direccion sobre un dato que no existe.
     */
    private function wentDown(?float $current, ?float $previous): ?bool
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return ($previous - $current) > self::NOISE_EPSILON;
    }

    private function wentUp(?float $current, ?float $previous): ?bool
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return ($current - $previous) > self::NOISE_EPSILON;
    }
}
