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
 * (`FundamentalsHistoryRepository::findAsOfWithDate()`, version fechada del
 * mismo metodo que usa el backtest point-in-time) para margen operativo,
 * ROIC, deuda/patrimonio y conversion de caja.
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
 *
 * ADVERTENCIA DE COMPARABILIDAD (revision de Codex, 2026-09-06): `$current`
 * llega del proveedor de mercado activo (Yahoo hoy, FMP si se cambia la
 * configuracion), pero el snapshot anterior sale siempre de
 * `fundamentals_history`, reconstruido enteramente desde EODHD. El
 * `verdict` resultante puede estar midiendo una diferencia de PROVEEDOR y
 * de FORMULA de calculo, no (solo) un cambio real de la empresa. No hay
 * solucion barata hoy -- haria falta que ambos extremos compartan
 * proveedor, version de formula y definicion contable -- asi que la vista
 * (`Web\StockDetailPage::renderFundamentalChangeSection()`) DEBE mostrar
 * este aviso junto a cualquier veredicto que no sea `NO_EVALUABLE`.
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

    /**
     * Mas alla de esta antigüedad (2 años), el snapshot anterior mas
     * cercano disponible en `fundamentals_history` deja de representar
     * razonablemente "hace un año" -- comparar contra un dato asi de viejo
     * mezclaria un cambio interanual real con anios adicionales de deriva.
     * `findAsOf()`/`findAsOfWithDate()` devuelven el snapshot anterior mas
     * cercano SIN limite de antigüedad (ver su docblock), asi que este
     * corte vive aqui, no en el repositorio. Criterio propio, no una cifra
     * calibrada estadisticamente: documentado a proposito para poder
     * revisarlo si la cadencia real de captura de `fundamentals_history`
     * cambia.
     */
    private const MAX_SNAPSHOT_AGE_DAYS = 730;

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
        $previousSnapshot = $this->fundamentalsHistoryRepository->findAsOfWithDate(
            $ticker,
            $asOf->modify(sprintf('-%d days', self::DAYS_LOOKBACK))
        );

        if ($previousSnapshot === null) {
            // "No evaluable: sin historico suficiente" -- ni siquiera hay
            // un snapshot de hace un año contra el que comparar.
            return FundamentalChangeAssessment::noEvaluableResult();
        }

        $previousSnapshotDate = $previousSnapshot['snapshotDate'];

        if ($asOf->diff($previousSnapshotDate)->days > self::MAX_SNAPSHOT_AGE_DAYS) {
            // Hay un snapshot, pero es demasiado viejo para llamarlo
            // razonablemente "cambio interanual" (ver MAX_SNAPSHOT_AGE_DAYS).
            // Se conserva la fecha real en el resultado para que la vista
            // pueda explicar por que se descarto, no solo decir "no hay
            // historico".
            return FundamentalChangeAssessment::noEvaluableResult($previousSnapshotDate);
        }

        $previous = FundamentalsHistoryRepository::fromArray($previousSnapshot['payload']);
        $factors = $this->buildFactors($current, $previous);

        if (count($factors) < self::MIN_FACTORS) {
            // Se expone la lista aunque tenga 0 o 1 elemento (transparencia
            // de "que se comparo"), pero el veredicto agregado no es
            // concluyente con menos de MIN_FACTORS factores disponibles.
            return new FundamentalChangeAssessment(false, FundamentalChangeVerdict::NO_EVALUABLE, $factors, $previousSnapshotDate);
        }

        return new FundamentalChangeAssessment(false, $this->classify($factors), $factors, $previousSnapshotDate);
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
     * `ESTABLE` y `MIXTO` son casos distintos (correccion de Codex,
     * 2026-09-06, ver `Enums\FundamentalChangeVerdict`): `ESTABLE` es para
     * cuando NINGUN factor cambio de verdad (todos nulos por
     * `FundamentalChangeFactor::improved()`, ruido de redondeo incluido);
     * `MIXTO` es para el empate real entre factores que mejoran y factores
     * que empeoran. Antes ambos casos caian en `default => ESTABLE`, lo
     * que etiquetaba "Estable" a una empresa con la mitad de sus factores
     * mejorando y la mitad empeorando -- justo lo contrario de "sin
     * cambio".
     *
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
            $improved === 0 && $worsened === 0 => FundamentalChangeVerdict::ESTABLE,
            default => FundamentalChangeVerdict::MIXTO,
        };
    }
}
