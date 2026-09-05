<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use StockAnalyzer\DTO\FundamentalChangeAssessment;
use StockAnalyzer\DTO\FundamentalChangeFactor;
use StockAnalyzer\Enums\FundamentalChangeVerdict;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;

/**
 * D2 del diagnostico fundamental ("Cambio interanual", ver
 * DTO\FundamentalChangeAssessment): compara el `Fundamentals` actual de
 * una accion contra su snapshot de hace ~365 dias
 * (`FundamentalsHistoryRepository::findAsOf()`, el mismo metodo que usa el
 * backtest point-in-time) para margen operativo, ROIC, deuda/patrimonio y
 * conversion de caja.
 *
 * A diferencia de `FundamentalHealthAssessor` (D1, puro), este servicio SI
 * toca base de datos a traves de `FundamentalsHistoryRepository`: mismo
 * patron de Service con repositorio inyectado que el resto de servicios
 * de la aplicacion (`PortfolioService`, etc.), no un servicio "puro".
 *
 * Clasificacion por MAYORIA DE SIGNO entre los factores disponibles, nunca
 * por magnitud con un umbral inventado (`auditor-estadistico`,
 * 2026-09-05): si un factor concreto no tiene dato en ambas fechas, se
 * EXCLUYE de la comparacion en vez de rellenarse con un valor neutro.
 */
final class FundamentalChangeAssessor
{
    private const DAYS_LOOKBACK = 365;

    /**
     * Por debajo de este numero de factores disponibles (ambas fechas con
     * dato), una mayoria de signo no significa nada: con 1 solo factor
     * "la mayoria" es ese unico factor, no un consenso.
     */
    private const MIN_FACTORS = 2;

    public function __construct(
        private readonly FundamentalsHistoryRepository $fundamentalsHistoryRepository
    ) {
    }

    public function assess(string $ticker, Fundamentals $current, Company $company, ?DateTimeImmutable $asOf = null): FundamentalChangeAssessment
    {
        if (FundamentalSectorExclusion::appliesTo($company)) {
            return FundamentalChangeAssessment::sectorExcludedResult();
        }

        $asOf ??= new DateTimeImmutable('today');
        $previousPayload = $this->fundamentalsHistoryRepository->findAsOf(
            $ticker,
            $asOf->modify(sprintf('-%d days', self::DAYS_LOOKBACK))
        );

        if ($previousPayload === null) {
            // "No evaluable: sin historico suficiente" -- ni siquiera hay
            // un snapshot de hace un año contra el que comparar.
            return FundamentalChangeAssessment::noEvaluableResult();
        }

        $previous = FundamentalsHistoryRepository::fromArray($previousPayload);
        $factors = $this->buildFactors($current, $previous);

        if (count($factors) < self::MIN_FACTORS) {
            // Se expone la lista aunque tenga 0 o 1 elemento (transparencia
            // de "que se comparo"), pero el veredicto agregado no es
            // concluyente con menos de MIN_FACTORS factores disponibles.
            return new FundamentalChangeAssessment(false, FundamentalChangeVerdict::NO_EVALUABLE, $factors);
        }

        return new FundamentalChangeAssessment(false, $this->classify($factors), $factors);
    }

    /**
     * @return list<FundamentalChangeFactor>
     */
    private function buildFactors(Fundamentals $current, Fundamentals $previous): array
    {
        $candidates = [
            $this->factorFor('Margen operativo', $current->getOperatingMargin(), $previous->getOperatingMargin(), higherIsBetter: true, isPercentage: true),
            $this->factorFor('ROIC', $current->getRoic(), $previous->getRoic(), higherIsBetter: true, isPercentage: true),
            // Deuda/Patrimonio: mejorar es BAJAR, al reves que el resto.
            $this->factorFor('Deuda/Patrimonio', $current->getDebtToEquity(), $previous->getDebtToEquity(), higherIsBetter: false, isPercentage: false),
            $this->factorFor('Conversión de caja', $current->getCashConversion(), $previous->getCashConversion(), higherIsBetter: true, isPercentage: false),
        ];

        return array_values(array_filter($candidates));
    }

    private function factorFor(
        string $label,
        ?float $currentValue,
        ?float $previousValue,
        bool $higherIsBetter,
        bool $isPercentage
    ): ?FundamentalChangeFactor {
        // Ausencia de dato en cualquiera de las dos fechas excluye el
        // factor de la comparacion: no se rellena con cero ni con ningun
        // otro valor inventado (mismo criterio "ausencia no es neutral"
        // que D1).
        if ($currentValue === null || $previousValue === null) {
            return null;
        }

        return new FundamentalChangeFactor($label, $currentValue, $previousValue, $higherIsBetter, $isPercentage);
    }

    /**
     * @param list<FundamentalChangeFactor> $factors
     */
    private function classify(array $factors): FundamentalChangeVerdict
    {
        $improved = 0;
        $worsened = 0;

        foreach ($factors as $factor) {
            $outcome = $factor->improved();

            if ($outcome === true) {
                ++$improved;
            } elseif ($outcome === false) {
                ++$worsened;
            }
        }

        return match (true) {
            $improved > $worsened => FundamentalChangeVerdict::MEJORANDO,
            $worsened > $improved => FundamentalChangeVerdict::DETERIORANDO,
            default => FundamentalChangeVerdict::ESTABLE,
        };
    }
}
