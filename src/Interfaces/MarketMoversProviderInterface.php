<?php

declare(strict_types=1);

namespace StockAnalyzer\Interfaces;

/**
 * Fuente de "que sube y que baja hoy" (ver versions.md v2.12), usada para
 * construir el universo dinamico de la busqueda general del Home. Aparte
 * de MarketDataProviderInterface (cotizacion/historico por ticker) porque
 * es un tipo de dato distinto: una lista de simbolos que cambia varias
 * veces al dia, no el detalle de una accion concreta.
 */
interface MarketMoversProviderInterface
{
    /**
     * @return list<string> tickers, ordenados de mayor a menor subida
     */
    public function getTopGainers(int $count): array;

    /**
     * @return list<string> tickers, ordenados de mayor a menor bajada
     */
    public function getTopLosers(int $count): array;
}
