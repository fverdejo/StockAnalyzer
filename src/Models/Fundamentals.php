<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

class Fundamentals
{
    /**
     * @param ?float $per Price/Earnings (ratio, ej. 18.4)
     * @param ?float $peg PER ajustado al crecimiento (ratio, ej. 1.2)
     * @param ?float $roe Rentabilidad sobre recursos propios (porcentaje, ej. 24.0 = 24%)
     * @param ?float $roic Rentabilidad sobre capital invertido (porcentaje). Yahoo no lo expone
     *                      de forma fiable en sus endpoints publicos: normalmente sera null.
     * @param ?float $eps Beneficio por accion (en la divisa de la empresa)
     * @param ?float $marketCap Capitalizacion bursatil (en la divisa de la empresa)
     * @param ?float $debtToEquity Deuda/Patrimonio (ratio, ej. 0.8)
     * @param ?float $freeCashFlow Flujo de caja libre (en la divisa de la empresa)
     * @param ?float $evToEbitda Enterprise Value / EBITDA (ratio, ej. 11.5)
     * @param ?float $priceToBook Precio/Valor contable (ratio, ej. 3.2)
     * @param ?float $dividendYield Rentabilidad por dividendo (porcentaje, ej. 2.5 = 2.5%)
     * @param ?float $payoutRatio Porcentaje del beneficio destinado a dividendos (ej. 35.0 = 35%)
     * @param ?float $grossMargin Margen bruto (porcentaje)
     * @param ?float $operatingMargin Margen operativo (porcentaje)
     * @param ?float $netMargin Margen neto (porcentaje)
     * @param ?float $revenueGrowth Crecimiento de ingresos interanual (porcentaje)
     * @param ?float $currentRatio Ratio de liquidez corriente (ratio, ej. 1.8)
     * @param ?float $dividendGrowth5y CAGR de dividendo anualizado a 5 años (porcentaje,
     *                      ej. 6.3 = 6.3%), calculado por Services\DividendGrowthCalculator
     *                      a partir del historial real de pagos. Null tanto si la empresa
     *                      no paga dividendo como si tiene menos de 5 años de historial:
     *                      ver DividendGrowthCalculator::calculate() para el criterio
     *                      exacto, tratado como "sin dato" (neutro) en FundamentalAnalyzer.
     * @param ?float $earningsYield Beneficio/precio (ratio, inverso del PER, ej. 0.05 =
     *                      EPS es el 5% del precio). A diferencia de `per` (que exige
     *                      beneficio positivo), ADMITE beneficio negativo: es justo el
     *                      punto de tenerlo por separado (P3.3,
     *                      `REVISION_MOTOR_CODEX_2026-09-02.md`), para no perder de vista
     *                      las empresas con perdidas en un ranking por percentil.
     * @param ?float $cashConversion Flujo de caja libre TTM entre beneficio neto TTM
     *                      (ratio, ej. 1.1 = el flujo de caja libre superó el beneficio
     *                      contable un 10%). Null si el beneficio neto es cero (division
     *                      sin sentido) o si no hay dato de alguno de los dos.
     */
    public function __construct(
        private readonly ?float $per,
        private readonly ?float $peg,
        private readonly ?float $roe,
        private readonly ?float $roic,
        private readonly ?float $eps,
        private readonly ?float $marketCap,
        private readonly ?float $debtToEquity,
        private readonly ?float $freeCashFlow,
        private readonly ?float $evToEbitda = null,
        private readonly ?float $priceToBook = null,
        private readonly ?float $dividendYield = null,
        private readonly ?float $payoutRatio = null,
        private readonly ?float $grossMargin = null,
        private readonly ?float $operatingMargin = null,
        private readonly ?float $netMargin = null,
        private readonly ?float $revenueGrowth = null,
        private readonly ?float $currentRatio = null,
        private readonly ?float $dividendGrowth5y = null,
        private readonly ?float $earningsYield = null,
        private readonly ?float $cashConversion = null
    ) {
    }

    /**
     * Fundamentales vacios: se usa cuando el proveedor no pudo obtener
     * datos fundamentales para un ticker (por ejemplo, si falla la
     * conexion). El resto de la aplicacion trata estos campos null como
     * "dato no disponible", nunca como cero.
     */
    public static function empty(): self
    {
        return new self(null, null, null, null, null, null, null, null);
    }

    public function getPer(): ?float
    {
        return $this->per;
    }

    public function getPeg(): ?float
    {
        return $this->peg;
    }

    public function getRoe(): ?float
    {
        return $this->roe;
    }

    public function getRoic(): ?float
    {
        return $this->roic;
    }

    public function getEps(): ?float
    {
        return $this->eps;
    }

    public function getMarketCap(): ?float
    {
        return $this->marketCap;
    }

    public function getDebtToEquity(): ?float
    {
        return $this->debtToEquity;
    }

    public function getFreeCashFlow(): ?float
    {
        return $this->freeCashFlow;
    }

    public function getEvToEbitda(): ?float
    {
        return $this->evToEbitda;
    }

    public function getPriceToBook(): ?float
    {
        return $this->priceToBook;
    }

    public function getDividendYield(): ?float
    {
        return $this->dividendYield;
    }

    public function getPayoutRatio(): ?float
    {
        return $this->payoutRatio;
    }

    public function getGrossMargin(): ?float
    {
        return $this->grossMargin;
    }

    public function getOperatingMargin(): ?float
    {
        return $this->operatingMargin;
    }

    public function getNetMargin(): ?float
    {
        return $this->netMargin;
    }

    public function getRevenueGrowth(): ?float
    {
        return $this->revenueGrowth;
    }

    public function getCurrentRatio(): ?float
    {
        return $this->currentRatio;
    }

    public function getDividendGrowth5y(): ?float
    {
        return $this->dividendGrowth5y;
    }

    public function getEarningsYield(): ?float
    {
        return $this->earningsYield;
    }

    public function getCashConversion(): ?float
    {
        return $this->cashConversion;
    }

    /**
     * Reconstruye este objeto con dividendGrowth5y actualizado, sin tocar
     * el resto de campos. Fundamentals se construye a partir del payload de
     * quoteSummary (que no tiene el historial de dividendos), asi que
     * StockAnalysisService/BacktestingService completan este campo aparte,
     * a partir de una llamada distinta al proveedor (ver
     * Services\DividendGrowthCalculator), una vez que ya tienen el Stock.
     */
    public function withDividendGrowth5y(?float $dividendGrowth5y): self
    {
        return new self(
            $this->per,
            $this->peg,
            $this->roe,
            $this->roic,
            $this->eps,
            $this->marketCap,
            $this->debtToEquity,
            $this->freeCashFlow,
            $this->evToEbitda,
            $this->priceToBook,
            $this->dividendYield,
            $this->payoutRatio,
            $this->grossMargin,
            $this->operatingMargin,
            $this->netMargin,
            $this->revenueGrowth,
            $this->currentRatio,
            $dividendGrowth5y,
            $this->earningsYield,
            $this->cashConversion
        );
    }

    /**
     * Rentabilidad por flujo de caja libre respecto a la capitalizacion
     * (porcentaje). Se deriva de freeCashFlow y marketCap en lugar de
     * pedirse como campo independiente, porque ambos ya viajan juntos en
     * este objeto y el ratio pierde sentido si se separan.
     */
    public function getFreeCashFlowYield(): ?float
    {
        if ($this->freeCashFlow === null || $this->marketCap === null || $this->marketCap <= 0) {
            return null;
        }

        return ($this->freeCashFlow / $this->marketCap) * 100;
    }
}
