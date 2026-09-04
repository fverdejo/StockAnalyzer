<?php

declare(strict_types=1);

namespace StockAnalyzer\Interfaces;

/**
 * Busqueda de simbolos EN VIVO contra el proveedor de mercado, usada solo
 * como fallback del buscador de texto libre del Home (ver roadmap.md,
 * "Buscador del Home", 2026-09-04): `CompanyDirectory` es un diccionario
 * local cerrado a los tickers que ya aparecen en `config/universes.php`, asi
 * que un nombre de empresa que no este en ningun universo configurado (p.ej.
 * "Nokia") no se resuelve nunca sin esto.
 *
 * Interfaz separada de `MarketDataProviderInterface` a proposito (mismo
 * criterio que `IndexMembershipCheckerInterface` en `BacktestingService`):
 * es una capacidad OPCIONAL que solo tiene sentido para un proveedor con un
 * endpoint de busqueda/autocompletado real, y anadirla a la interfaz
 * principal forzaria a los test doubles del proyecto (que implementan
 * `MarketDataProviderInterface` sin necesitar esto) a implementar un metodo
 * que no usan. Se comprueba con `instanceof` antes de usarla.
 */
interface SymbolSearchProviderInterface
{
    /**
     * Devuelve el mejor ticker encontrado para $query, o null si no hay
     * resultado o si algo falla. Best effort, mismo criterio que
     * `MarketDataProviderInterface::getDividendHistory()`: nunca debe
     * lanzar una excepcion, un fallo de red o un formato de respuesta
     * inesperado se traduce siempre en null.
     */
    public function searchSymbol(string $query): ?string;
}
