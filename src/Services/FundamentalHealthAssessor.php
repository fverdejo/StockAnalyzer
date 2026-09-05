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

        $datosInsuficientes = $roic === null
            && $operatingMargin === null
            && $debtToEquity === null
            && $cashConversion === null;

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
