<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use StockAnalyzer\DTO\FiscalPeriod;
use StockAnalyzer\Models\Fundamentals;

/**
 * Reconstruye los `Fundamentals` de un ticker tal y como se veian en una
 * fecha pasada, a partir de dos cosas: los ejercicios contables ya
 * publicados en esa fecha y la cotizacion de ese dia.
 *
 * Es el nucleo del relleno historico (`v2.93`). Todo lo delicado del
 * problema vive aqui, y por eso la clase es **pura**: no toca red, base de
 * datos ni reloj, y se puede probar entera con datos escritos a mano.
 *
 * Dos reglas gobiernan la correccion:
 *
 * 1. **Solo se usa lo publicado.** Para la fecha D se toma el ejercicio
 *    mas reciente cuya `filingDate` sea anterior o igual a D. Nunca el que
 *    cerro antes de D pero se publico despues: eso seria informacion del
 *    futuro y es justo el sesgo que este trabajo elimina. La diferencia no
 *    es teorica —Apple cerro su FY2025 el 27 de septiembre y lo publico el
 *    31 de octubre—, y usar la fecha equivocada no produce ningun error:
 *    solo un backtest que parece mejor de lo que fue.
 *
 * 2. **Las unidades son las de `YahooParser`.** Margenes, ROE, ROIC,
 *    rentabilidad por dividendo y payout en porcentaje 0-100; deuda entre
 *    patrimonio como ratio puro; PER, PEG, EV/EBITDA, precio/valor
 *    contable y current ratio como ratios. Un `Fundamentals` reconstruido
 *    tiene que ser indistinguible de uno servido en vivo, o el score
 *    historico no seria comparable con el de hoy.
 *
 * Lo que NO se inventa: cualquier ratio cuyo denominador sea nulo, cero o
 * negativo se devuelve como `null`. `FundamentalAnalyzer` ya trata `null`
 * como "sin dato" y lo puntua de forma neutra; un cero o un numero
 * disparatado se puntuaria como si fuera una medida real.
 */
class PointInTimeFundamentalsBuilder
{
    /**
     * @param list<FiscalPeriod> $periods ejercicios del ticker, en cualquier orden
     */
    public function __construct(
        private readonly array $periods
    ) {
    }

    /**
     * Los `Fundamentals` conocidos en `$date`, con la cotizacion `$price`
     * de ese mismo dia. `null` si en esa fecha no se habia publicado
     * todavia ningun ejercicio: preferible saltar la muestra a inventarla.
     */
    public function buildFor(DateTimeImmutable $date, float $price): ?Fundamentals
    {
        $current = $this->latestFiledBefore($date);

        if ($current === null) {
            return null;
        }

        $previous = $this->previousOf($current);
        $shares = $this->positive($current->sharesDiluted);
        $equity = $this->positive($current->totalStockholdersEquity);
        $revenue = $this->positive($current->revenue);
        $marketCap = ($shares !== null && $price > 0.0) ? $price * $shares : null;

        $per = ($current->epsDiluted !== null && $current->epsDiluted > 0.0 && $price > 0.0)
            ? $price / $current->epsDiluted
            : null;
        $earningsGrowth = $this->growth($current->netIncome, $previous?->netIncome);

        return new Fundamentals(
            per: $per,
            // PEG solo tiene sentido con crecimiento positivo: con
            // beneficios cayendo, el ratio sale negativo y se leeria como
            // "baratisima" en vez de como "en problemas".
            peg: ($per !== null && $earningsGrowth !== null && $earningsGrowth > 0.0)
                ? $per / $earningsGrowth
                : null,
            roe: $this->percentOf($current->netIncome, $equity),
            roic: $this->roic($current),
            eps: $current->epsDiluted,
            marketCap: $marketCap,
            // Ratio puro, no porcentaje: asi lo normaliza YahooParser.
            debtToEquity: ($current->totalDebt !== null && $equity !== null)
                ? $current->totalDebt / $equity
                : null,
            freeCashFlow: $current->freeCashFlow,
            // EV = capitalizacion + deuda neta. Con EBITDA negativo el
            // multiplo no significa nada.
            evToEbitda: ($marketCap !== null && $current->netDebt !== null && $this->positive($current->ebitda) !== null)
                ? ($marketCap + $current->netDebt) / $current->ebitda
                : null,
            priceToBook: ($marketCap !== null && $equity !== null) ? $marketCap / $equity : null,
            dividendYield: $this->dividendYield($current, $price),
            payoutRatio: $this->percentOf(
                $current->dividendPerShare() !== null && $current->epsDiluted !== null
                    ? $current->dividendPerShare()
                    : null,
                $this->positive($current->epsDiluted)
            ),
            grossMargin: $this->percentOf($current->grossProfit, $revenue),
            operatingMargin: $this->percentOf($current->operatingIncome, $revenue),
            netMargin: $this->percentOf($current->netIncome, $revenue),
            revenueGrowth: $this->growth($current->revenue, $previous?->revenue),
            currentRatio: ($current->totalCurrentAssets !== null && $this->positive($current->totalCurrentLiabilities) !== null)
                ? $current->totalCurrentAssets / $current->totalCurrentLiabilities
                : null,
            dividendGrowth5y: $this->dividendGrowth($current)
        );
    }

    /**
     * La fecha de publicacion mas antigua de todas: antes de ella no hay
     * nada que reconstruir, y el relleno puede empezar ahi en vez de
     * recorrer años vacios.
     */
    public function earliestFilingDate(): ?DateTimeImmutable
    {
        $dates = array_map(static fn (FiscalPeriod $p): DateTimeImmutable => $p->filingDate, $this->periods);

        if ($dates === []) {
            return null;
        }

        usort($dates, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $dates[0];
    }

    /**
     * El ejercicio vigente en una fecha: el ultimo **publicado** hasta
     * entonces. La comparacion es por `filingDate`, nunca por `endDate`.
     */
    private function latestFiledBefore(DateTimeImmutable $date): ?FiscalPeriod
    {
        $candidates = array_filter(
            $this->periods,
            static fn (FiscalPeriod $p): bool => $p->filingDate <= $date
        );

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (FiscalPeriod $a, FiscalPeriod $b): int => $a->filingDate <=> $b->filingDate);

        return $candidates[array_key_last($candidates)];
    }

    /**
     * El ejercicio inmediatamente anterior al dado, para los crecimientos.
     * Se ordena por cierre de ejercicio y no por publicacion: lo que se
     * compara son dos periodos contables consecutivos.
     */
    private function previousOf(FiscalPeriod $current): ?FiscalPeriod
    {
        $earlier = array_filter(
            $this->periods,
            static fn (FiscalPeriod $p): bool => $p->endDate < $current->endDate
        );

        if ($earlier === []) {
            return null;
        }

        usort($earlier, static fn (FiscalPeriod $a, FiscalPeriod $b): int => $a->endDate <=> $b->endDate);

        return $earlier[array_key_last($earlier)];
    }

    /**
     * ROIC = NOPAT / capital empleado, en porcentaje. NOPAT se aproxima
     * como EBIT despues de la tasa impositiva efectiva del propio
     * ejercicio; el capital empleado, como deuda total mas patrimonio.
     * Yahoo no da ROIC (`YahooParser` lo deja en `null`), asi que aqui el
     * historico sera mas rico que el dato en vivo. `FundamentalAnalyzer`
     * lo trata como opcional, de modo que tenerlo suma y no tenerlo no
     * resta.
     */
    private function roic(FiscalPeriod $period): ?float
    {
        $capital = ($period->totalDebt ?? 0.0) + ($period->totalStockholdersEquity ?? 0.0);

        if ($period->ebit === null || $capital <= 0.0) {
            return null;
        }

        $taxRate = 0.0;

        if ($period->incomeBeforeTax !== null && $period->incomeBeforeTax > 0.0 && $period->incomeTaxExpense !== null) {
            $taxRate = max(0.0, min(1.0, $period->incomeTaxExpense / $period->incomeBeforeTax));
        }

        return ($period->ebit * (1 - $taxRate)) / $capital * 100;
    }

    private function dividendYield(FiscalPeriod $period, float $price): ?float
    {
        $perShare = $period->dividendPerShare();

        return ($perShare === null || $price <= 0.0) ? null : $perShare / $price * 100;
    }

    /**
     * Crecimiento anualizado del dividendo por accion entre el ejercicio
     * mas antiguo con reparto y el actual (mismo espiritu que
     * `DividendGrowthCalculator`, que trabaja sobre repartos individuales).
     * Con datos anuales la ventana es la que haya, nunca mas de 5 años.
     */
    private function dividendGrowth(FiscalPeriod $current): ?float
    {
        $currentDps = $current->dividendPerShare();

        if ($currentDps === null) {
            return null;
        }

        $olderWithDividend = array_filter(
            $this->periods,
            static fn (FiscalPeriod $p): bool => $p->endDate < $current->endDate && $p->dividendPerShare() !== null
        );

        if ($olderWithDividend === []) {
            return null;
        }

        usort($olderWithDividend, static fn (FiscalPeriod $a, FiscalPeriod $b): int => $a->endDate <=> $b->endDate);
        $oldest = $olderWithDividend[array_key_first($olderWithDividend)];
        $oldestDps = $oldest->dividendPerShare();
        $years = ($current->endDate->getTimestamp() - $oldest->endDate->getTimestamp()) / (365.25 * 86400);

        if ($oldestDps === null || $oldestDps <= 0.0 || $years < 0.9) {
            return null;
        }

        return ((($currentDps / $oldestDps) ** (1 / min($years, 5.0))) - 1) * 100;
    }

    private function growth(?float $current, ?float $previous): ?float
    {
        // Con base negativa o cero el porcentaje de variacion no significa
        // nada (pasar de -100 a -50 no es "crecer un 50%").
        if ($current === null || $previous === null || $previous <= 0.0) {
            return null;
        }

        return (($current / $previous) - 1) * 100;
    }

    private function percentOf(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0.0) {
            return null;
        }

        return $numerator / $denominator * 100;
    }

    private function positive(?float $value): ?float
    {
        return ($value === null || $value <= 0.0) ? null : $value;
    }
}
