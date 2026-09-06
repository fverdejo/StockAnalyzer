<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\DTO\FundamentalHealthAssessment;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;

/**
 * D1 del diagnostico fundamental ("Salud fundamental", ver
 * DTO\FundamentalHealthAssessment para el diseño completo y el motivo de
 * usar alertas absolutas en vez de un percentil sectorial).
 *
 * Servicio puro, sin red ni base de datos -- recibe `Fundamentals` y
 * `Company` ya cargados, mismo patron que `RiskLevelsCalculator`. Nunca
 * puntua ni se conecta a `ScoreCalculator`: es diagnostico, no una señal
 * de decision.
 *
 * Los umbrales absolutos usados aqui (FCF negativo, margen operativo
 * negativo, endeudamiento no evaluable) son HEURISTICAS DESCRIPTIVAS de
 * distress -- señales de alerta pensadas para leerse junto al resto de la
 * ficha, no una formula validada de retorno esperado ni un criterio de
 * compra/venta. Ninguna de las dos cosas se ha medido como tal (ver
 * `RESULTADOS_OPTIMIZACION_MOTOR_CODEX_2026-09-05.md`): de ahi que este
 * servicio nunca se conecte a `ScoreCalculator`/`Score`/`config/weights.php`.
 */
final class FundamentalHealthAssessor
{
    public function assess(Fundamentals $fundamentals, Company $company): FundamentalHealthAssessment
    {
        if (FundamentalSectorExclusion::appliesTo($company)) {
            return FundamentalHealthAssessment::sectorExcludedResult();
        }

        $roic = $fundamentals->getRoic();
        $operatingMargin = $fundamentals->getOperatingMargin();
        $debtToEquity = $fundamentals->getDebtToEquity();
        $cashConversion = $fundamentals->getCashConversion();
        $freeCashFlow = $fundamentals->getFreeCashFlow();

        // Incluye freeCashFlow a proposito (revision de Codex,
        // 2026-09-06): antes solo miraba los otros cuatro factores, asi
        // que un FCF negativo conocido -- el UNICO dato disponible --
        // marcaba `datosInsuficientes=true` y la vista ocultaba la alerta
        // de `fcfNegativo` detras del mensaje generico "datos
        // insuficientes". Un FCF negativo conocido es justo el caso mas
        // informativo, no el menos: debe poder disparar su propia alerta
        // aunque el resto de factores sigan sin dato.
        $datosInsuficientes = $roic === null
            && $operatingMargin === null
            && $debtToEquity === null
            && $cashConversion === null
            && $freeCashFlow === null;

        return new FundamentalHealthAssessment(
            sectorExcluded: false,
            datosInsuficientes: $datosInsuficientes,
            endeudamientoNoEvaluable: $debtToEquity === null,
            fcfNegativo: $freeCashFlow !== null && $freeCashFlow < 0,
            margenOperativoNegativo: $operatingMargin !== null && $operatingMargin < 0,
            roic: $roic,
            operatingMargin: $operatingMargin,
            debtToEquity: $debtToEquity,
            cashConversion: $cashConversion
        );
    }
}
