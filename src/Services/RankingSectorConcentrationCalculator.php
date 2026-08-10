<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\DTO\PortfolioConcentration;
use StockAnalyzer\DTO\StockAnalysis;

/**
 * Concentracion por sector de las primeras posiciones del ranking (ver
 * versions.md v2.75).
 *
 * `PortfolioConcentrationCalculator` (v2.61) vigila la cartera YA comprada,
 * pero nadie vigilaba el ranking que la alimenta. Medido sobre
 * `largecap60`, el sector dominante ocupa de media **3,6 de las 10 primeras
 * posiciones, y llega a 6 de 10**: "las 10 mejores de hoy" pueden ser en la
 * practica una apuesta sectorial concentrada sin que la pantalla lo diga.
 *
 * Esto avisa, no filtra. El ranking sigue ordenado por puntuacion: sustituir
 * un valor mejor puntuado por otro peor solo para repartir sectores seria
 * decidir por el usuario, y ademas cambiaria el producto que el backtesting
 * mide.
 */
class RankingSectorConcentrationCalculator
{
    /**
     * Cuantas posiciones del ranking se miran. 10 es el tamaño de la
     * "cabecera" que un usuario lee de verdad antes de decidir, y es el
     * mismo top-N con el que `BacktestingService::runCrossSectional()`
     * mide la alpha del ranking (v2.70): conviene que la pantalla avise
     * sobre exactamente el conjunto que se ha medido.
     */
    public const DEFAULT_TOP_N = 10;

    /**
     * Mismo umbral que `PortfolioConcentration::SECTOR_WARNING_PERCENT`, y
     * por el mismo motivo: pasado el 40% en un solo sector, el resultado
     * depende mas de ese sector que de la seleccion. Se referencia la
     * constante en vez de repetir el numero para que ambos avisos no
     * puedan divergir.
     */
    public const SECTOR_WARNING_PERCENT = PortfolioConcentration::SECTOR_WARNING_PERCENT;

    /**
     * Sectores del top-N ordenados de mayor a menor peso, con su numero de
     * posiciones y su porcentaje.
     *
     * Devuelve null si no hay nada que medir: menos de 2 resultados (con
     * uno solo, "el 100% es de un sector" es una obviedad, no un aviso) o
     * ningun resultado con sector conocido. Mismo criterio de "antes nada
     * que un dato engañoso" que el resto de la app.
     *
     * @param list<StockAnalysis> $results ya ordenados por puntuacion
     * @return list<array{sector: string, count: int, percent: float}>|null
     */
    public function compute(array $results, int $topN = self::DEFAULT_TOP_N): ?array
    {
        return $this->computeFromSectors(
            array_map(
                static fn (StockAnalysis $analysis): string => $analysis->getStock()->getCompany()->getSector(),
                $results
            ),
            $topN
        );
    }

    /**
     * La parte que de verdad calcula, separada de `compute()` porque lo
     * unico que necesita de cada resultado es su sector: asi se puede
     * probar sin construir un `StockAnalysis` completo (que arrastra
     * snapshot tecnico, series de grafico y score) para algo que solo
     * cuenta cadenas.
     *
     * @param list<string> $sectors en el orden del ranking
     * @return list<array{sector: string, count: int, percent: float}>|null
     */
    public function computeFromSectors(array $sectors, int $topN = self::DEFAULT_TOP_N): ?array
    {
        $top = array_slice($sectors, 0, max(1, $topN));

        if (count($top) < 2) {
            return null;
        }

        $counts = [];

        foreach ($top as $sector) {
            $sector = trim($sector);

            if ($sector === '') {
                continue;
            }

            $counts[$sector] = ($counts[$sector] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        // El porcentaje se calcula sobre los valores CON sector conocido, no
        // sobre el top entero: si la mitad no trae sector, decir que un
        // sector pesa el 30% del top-10 cuando en realidad es el 60% de lo
        // que se pudo clasificar seria quedarse corto justo en el aviso.
        $classified = array_sum($counts);
        arsort($counts);

        $weights = [];

        foreach ($counts as $sector => $count) {
            $weights[] = [
                'sector' => (string) $sector,
                'count' => $count,
                'percent' => round(($count / $classified) * 100, 1),
            ];
        }

        return $weights;
    }

    /**
     * Sectores que superan el umbral de aviso, de mayor a menor.
     *
     * @param list<array{sector: string, count: int, percent: float}>|null $weights
     * @return list<array{sector: string, count: int, percent: float}>
     */
    public function overweightSectors(?array $weights): array
    {
        if ($weights === null) {
            return [];
        }

        return array_values(array_filter(
            $weights,
            static fn (array $weight): bool => $weight['percent'] > self::SECTOR_WARNING_PERCENT
        ));
    }
}
