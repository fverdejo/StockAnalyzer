<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * La periodicidad de un `FiscalPeriod`: ejercicio completo o un unico
 * trimestre. `PointInTimeFundamentalsBuilder` la necesita para no volver a
 * tratar un trimestre aislado como si fuera un año: las formulas de flujo
 * (PER, margenes, ROE, ROIC, EV/EBITDA, rentabilidad por dividendo,
 * crecimientos...) exigen TTM (doce meses moviles) cuando el dato de origen
 * es trimestral, y exigen exactamente el periodo tal cual cuando es anual.
 *
 * Bug real corregido el 2026-09-01: `EodhdFiscalPeriodProvider` empezo a
 * entregar trimestres ademas de los ejercicios anuales de
 * `FmpFiscalPeriodProvider`, pero `FiscalPeriod` no declaraba la
 * periodicidad y `PointInTimeFundamentalsBuilder` -escrito solo para
 * anuales- trataba el ultimo trimestre publicado como un ejercicio
 * completo, distorsionando por ~4x el PER, ROE, ROIC, EV/EBITDA y los
 * crecimientos de todo el historico rellenado con EODHD.
 */
enum FiscalPeriodType: string
{
    case Annual = 'annual';
    case Quarterly = 'quarterly';
}
