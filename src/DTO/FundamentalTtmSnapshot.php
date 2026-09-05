<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * Los cuatro factores TTM que consume `Services\FundamentalDeteriorationFlagger`
 * (E1 del plan de aprovechamiento de EODHD, "Deterioro fundamental",
 * `PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md` Bloque E) en un
 * unico punto en el tiempo: bien el TTM actual de una muestra de backtest,
 * bien el TTM de hace ~365 dias.
 *
 * No es un `Models\Fundamentals` completo a proposito: solo transporta los
 * cuatro valores ya extraidos (margen operativo, ROIC, FCF yield,
 * deuda/patrimonio) para que quien construye este DTO
 * (`Services\BacktestingService::runDeteriorationRiskAnalysis()`) no tenga
 * que fabricar un `Fundamentals` de mentira con campos inventados solo para
 * poder llamar a `getFreeCashFlowYield()` -- ahi es un valor DERIVADO de
 * `freeCashFlow`/`marketCap`, no un campo propio, y el llamador ya tiene el
 * yield calculado de dos fuentes distintas (la muestra del backtest para el
 * TTM actual, `FundamentalsHistoryRepository::fromArray()` para el de hace
 * un año).
 *
 * `null` en cualquier campo significa "sin dato en esta fecha para este
 * factor" -- el mismo significado que en todo el proyecto, nunca cero ni
 * neutral.
 */
final class FundamentalTtmSnapshot
{
    public function __construct(
        public readonly ?float $operatingMargin,
        public readonly ?float $roic,
        public readonly ?float $freeCashFlowYield,
        public readonly ?float $debtToEquity
    ) {
    }
}
