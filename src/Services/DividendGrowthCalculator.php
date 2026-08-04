<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateInterval;
use DateTimeImmutable;
use StockAnalyzer\DTO\DividendPayment;

/**
 * CAGR de dividendo anualizado a 5 años a partir del historial real de
 * pagos (ver DTO\DividendPayment, obtenido de
 * MarketDataProviderInterface::getDividendHistory()).
 *
 * "Anualizado" = suma de los pagos reales en una ventana movil de 12 meses,
 * NUNCA se asume una periodicidad fija (4 pagos trimestrales): muchos
 * valores de ibex35 pagan en 1 o 2 plazos al año, y asumir 4 infravaloraria
 * su dividendo anual real.
 *
 * CAGR = (dividendo_anualizado_actual / dividendo_anualizado_hace_5_años)^(1/5) - 1
 *
 * Calibrado por analista-mercado con ~79 pagadores de dividendo reales de
 * varios universos de config/universes.php (ver versions.md): CAGR de
 * dividendo anual 2020-2025 con p25=4,0%/p50=6,3%/p75=9,0%/p90=13,0%.
 * FundamentalAnalyzer::dividend() traduce el resultado de calculate() a
 * puntos sobre esos percentiles.
 *
 * LIMITACION CONOCIDA: un dividendo especial (pago puntual, no parte del
 * reparto regular) puede distorsionar la ventana en la que cae, mostrando
 * una caida de crecimiento que no es un recorte real (caso real detectado
 * en la calibracion: COST pago un dividendo especial en dic-2020 que hacia
 * parecer un -16,9% de "crecimiento" en vez del crecimiento real). Mitigado
 * con una heuristica simple (excludeOutliers()): un pago se excluye de la
 * suma de su ventana si es mas del doble de la mediana de los demas pagos
 * de esa misma ventana. No es deteccion perfecta (un pago especial que no
 * duplique al resto no se detecta), pero cubre el caso mas comun sin
 * complejidad adicional.
 */
class DividendGrowthCalculator
{
    private const YEARS = 5;

    /**
     * @param list<DividendPayment> $payments Historial completo del ticker,
     *        en cualquier orden (se ordena internamente).
     * @return ?float CAGR en porcentaje (ej. 6.3 = 6.3%), o null si no hay
     *         dividendo, o si el historial disponible cubre menos de
     *         self::YEARS años (dato insuficiente, no un recorte).
     */
    public function calculate(array $payments, ?DateTimeImmutable $referenceDate = null): ?float
    {
        if ($payments === []) {
            return null;
        }

        $payments = $this->sortedByDate($payments);
        $referenceDate ??= new DateTimeImmutable();
        $baseDate = $referenceDate->sub(new DateInterval('P' . self::YEARS . 'Y'));

        $earliestPayment = $payments[0]->getDate();

        if ($earliestPayment > $baseDate) {
            // Menos de 5 años de historial (ej. empresa que empezo a pagar
            // dividendo hace poco): neutro, no penalizar.
            return null;
        }

        $currentAnnualized = $this->annualizedDividend($payments, $referenceDate);
        $baseAnnualized = $this->annualizedDividend($payments, $baseDate);

        if ($currentAnnualized === null || $baseAnnualized === null || $baseAnnualized <= 0) {
            return null;
        }

        $cagr = (($currentAnnualized / $baseAnnualized) ** (1 / self::YEARS)) - 1;

        return $cagr * 100;
    }

    /**
     * Suma de los pagos reales en la ventana movil de 12 meses que termina
     * en $endDate (excluye pagos anteriores a esa ventana), con outliers de
     * dividendo especial excluidos. Null si no hay ningun pago en la
     * ventana (ticker sin dividendo en ese periodo).
     *
     * @param list<DividendPayment> $payments Ya ordenados por fecha ascendente.
     */
    private function annualizedDividend(array $payments, DateTimeImmutable $endDate): ?float
    {
        $windowStart = $endDate->sub(new DateInterval('P1Y'));

        $windowPayments = array_values(array_filter(
            $payments,
            static fn (DividendPayment $payment): bool => $payment->getDate() > $windowStart
                && $payment->getDate() <= $endDate
        ));

        if ($windowPayments === []) {
            return null;
        }

        return array_sum($this->excludeOutliers($windowPayments));
    }

    /**
     * @param list<DividendPayment> $windowPayments
     * @return list<float> Importes de la ventana, sin los que se consideran
     *         dividendo especial (ver limitacion conocida en el docblock de
     *         la clase).
     */
    private function excludeOutliers(array $windowPayments): array
    {
        $amounts = array_map(static fn (DividendPayment $payment): float => $payment->getAmount(), $windowPayments);
        $filtered = [];

        foreach ($amounts as $index => $amount) {
            $others = $amounts;
            unset($others[$index]);

            if ($others === []) {
                $filtered[] = $amount;
                continue;
            }

            $medianOfOthers = $this->median(array_values($others));

            if ($medianOfOthers > 0 && $amount > $medianOfOthers * 2) {
                // Probable dividendo especial: no forma parte del reparto
                // regular, se excluye de la suma anualizada.
                continue;
            }

            $filtered[] = $amount;
        }

        return $filtered;
    }

    /**
     * @param list<float> $values No vacio.
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    /**
     * @param list<DividendPayment> $payments
     * @return list<DividendPayment>
     */
    private function sortedByDate(array $payments): array
    {
        usort($payments, static fn (DividendPayment $a, DividendPayment $b): int => $a->getDate() <=> $b->getDate());

        return $payments;
    }
}
