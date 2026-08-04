<?php

declare(strict_types=1);

namespace StockAnalyzer\Interfaces;

use StockAnalyzer\DTO\DividendPayment;
use StockAnalyzer\Models\Stock;

interface MarketDataProviderInterface
{
    public function getStock(string $ticker): Stock;

    /**
     * @return list<\StockAnalyzer\Models\HistoricalQuote>
     */
    public function getHistoricalQuotes(string $ticker): array;

    /**
     * Velas intradia (ver versions.md v2.9), para temporalidades inferiores
     * a un dia. $interval acepta los valores que soporta Yahoo Finance
     * ("1m", "5m", "15m", "1h"...); cada implementacion elige el rango que
     * pide al proveedor para ese intervalo.
     *
     * @return list<\StockAnalyzer\Models\HistoricalQuote>
     */
    public function getIntradayQuotes(string $ticker, string $interval): array;

    /**
     * Historial de pagos de dividendo reales, para calcular crecimiento de
     * dividendo (ver Services\DividendGrowthCalculator). Best effort, igual
     * criterio que el resto de campos opcionales de esta interfaz: si el
     * ticker no paga dividendo o el proveedor no puede obtener el dato,
     * debe devolver un array vacio, nunca lanzar una excepcion.
     *
     * @return list<DividendPayment>
     */
    public function getDividendHistory(string $ticker): array;
}
