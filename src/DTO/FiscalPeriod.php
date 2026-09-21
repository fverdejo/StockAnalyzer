<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use DateTimeImmutable;

/**
 * Un ejercicio contable de una empresa, con las cifras en bruto de sus tres
 * estados financieros y —lo que hace posible todo esto— **la fecha en que
 * se publico**.
 *
 * `$endDate` es cuando cerro el ejercicio; `$filingDate` cuando llego al
 * mercado. Entre una y otra pasan semanas (Apple cerro su FY2025 el
 * 2025-09-27 y lo publico el 2025-10-31). Reconstruir el pasado usando
 * `endDate` seria darle al backtest informacion un mes antes de que
 * existiera: exactamente el sesgo que se esta eliminando. Por eso
 * `PointInTimeFundamentalsBuilder` filtra siempre por `filingDate`.
 *
 * Cifras en bruto y no ratios ya calculados: los ratios se derivan aqui
 * con las mismas convenciones que usa `YahooParser` (porcentajes 0-100
 * para margenes y rentabilidades, ratio puro para deuda/patrimonio), de
 * modo que un `Fundamentals` reconstruido sea indistinguible de uno
 * servido por el proveedor en vivo. Si se guardasen los ratios del
 * proveedor, cada uno vendria con su propia definicion y el score
 * historico no seria comparable con el actual.
 *
 * `$periodType` declara si estas cifras son de un ejercicio completo o de
 * un unico trimestre (ver `FiscalPeriodType`). No es un dato decorativo:
 * `PointInTimeFundamentalsBuilder` calcula TTM (doce meses moviles) para
 * toda cifra de flujo cuando es trimestral, y usa el periodo tal cual
 * cuando es anual. Un `PointInTimeFundamentalsBuilder` se niega a mezclar
 * periodos de las dos periodicidades para el mismo ticker.
 *
 * `$statementCurrency` (añadido el 2026-09-18, hallazgo de Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B3): la moneda en
 * la que EODHD reporto ESTE estado financiero -- confirmado con datos
 * reales que NO siempre coincide con la moneda de cotizacion (AZN.L
 * cotiza en GBX/peniques, sus estados en USD; ULVR.L cotiza en
 * GBX, sus estados en EUR). `PointInTimeFundamentalsBuilder` compara esto
 * contra la moneda del precio antes de mezclar los dos en un ratio.
 *
 * `$incomeCurrency`/`$balanceCurrency`/`$cashFlowCurrency` (añadidos el
 * 2026-09-22, encargo C2 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`):
 * la moneda de CADA estado por separado. Una sola moneda por periodo no bastaba
 * (un trimestre con resultados y balance en GBP y flujo de caja en USD hacia que
 * FCF/beneficio saliera 2 en vez de 1) y el TTM suma cuatro periodos que pueden
 * haber cambiado de moneda entre si (EUR/EUR/EUR/USD daba un ROE del 25% donde
 * los mismos importes en base comun dan 40%). `statementCurrency` se conserva
 * (balance, y si no resultados) para lo que ya la usa.
 */
class FiscalPeriod
{
    public function __construct(
        public readonly string $ticker,
        public readonly DateTimeImmutable $endDate,
        public readonly DateTimeImmutable $filingDate,
        public readonly FiscalPeriodType $periodType,
        // Cuenta de resultados
        public readonly ?float $revenue,
        public readonly ?float $grossProfit,
        public readonly ?float $operatingIncome,
        public readonly ?float $netIncome,
        public readonly ?float $ebitda,
        public readonly ?float $ebit,
        public readonly ?float $incomeBeforeTax,
        public readonly ?float $incomeTaxExpense,
        public readonly ?float $epsDiluted,
        public readonly ?float $sharesDiluted,
        // Balance
        public readonly ?float $totalStockholdersEquity,
        public readonly ?float $totalDebt,
        public readonly ?float $netDebt,
        public readonly ?float $totalCurrentAssets,
        public readonly ?float $totalCurrentLiabilities,
        // Flujo de caja
        public readonly ?float $freeCashFlow,
        public readonly ?float $commonDividendsPaid,
        public readonly ?string $statementCurrency = null,
        public readonly ?string $incomeCurrency = null,
        public readonly ?string $balanceCurrency = null,
        public readonly ?string $cashFlowCurrency = null
    ) {
    }

    /**
     * Moneda de la CUENTA DE RESULTADOS de este periodo (C2, encargo de Astra
     * del 2026-09-21): la propia de ese estado si el proveedor la dio; si no,
     * cae en `statementCurrency` (proveedores anteriores, que solo daban una
     * moneda por periodo). `null` = desconocida.
     */
    public function incomeStatementCurrency(): ?string
    {
        return $this->incomeCurrency ?? $this->statementCurrency;
    }

    /** Moneda del BALANCE de este periodo (ver `incomeStatementCurrency()`). */
    public function balanceSheetCurrency(): ?string
    {
        return $this->balanceCurrency ?? $this->statementCurrency;
    }

    /** Moneda del ESTADO DE FLUJOS DE CAJA de este periodo (ver `incomeStatementCurrency()`). */
    public function cashFlowStatementCurrency(): ?string
    {
        return $this->cashFlowCurrency ?? $this->statementCurrency;
    }

    /**
     * Dividendo por accion del ejercicio. `commonDividendsPaid` viene
     * NEGATIVO en el estado de flujos (es una salida de caja), asi que se
     * invierte el signo.
     */
    public function dividendPerShare(): ?float
    {
        if ($this->commonDividendsPaid === null || $this->sharesDiluted === null || $this->sharesDiluted <= 0.0) {
            return null;
        }

        $paid = abs($this->commonDividendsPaid);

        return $paid <= 0.0 ? null : $paid / $this->sharesDiluted;
    }
}
