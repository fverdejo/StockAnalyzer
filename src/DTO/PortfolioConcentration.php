<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * Concentracion de una cartera: cuanto pesa cada posicion, cada sector y
 * cada divisa sobre el valor total, mas los dos indicadores agregados
 * habituales (indice de Herfindahl-Hirschman y numero de posiciones
 * efectivas).
 *
 * Todos los pesos llegan ya expresados en porcentaje (0-100) sobre un
 * total en euros: convertir cada posicion a euros y decidir cuando no hay
 * datos suficientes es responsabilidad de
 * Services\PortfolioConcentrationCalculator, no de este DTO. Aqui solo
 * viven las metricas derivadas (formulas puras, mismo criterio que
 * DTO\RiskLevels) y la respuesta a "que entradas superan el umbral de
 * aviso"; el texto y el HTML de esos avisos son cosa de Web\PortfolioPage.
 */
class PortfolioConcentration
{
    /**
     * Umbrales de aviso no bloqueante (nada se oculta ni se impide por
     * superarlos): son referencias habituales de diversificacion, no
     * reglas del motor de puntuacion, y por eso no pasan por
     * Config\ScoreWeights ni alteran ninguna recomendacion.
     */
    public const POSITION_WARNING_PERCENT = 20.0;
    public const SECTOR_WARNING_PERCENT = 40.0;
    public const FOREIGN_CURRENCY_WARNING_PERCENT = 70.0;

    /**
     * Divisa de referencia de la cartera: los pesos se calculan sobre el
     * valor convertido a euros, asi que el aviso de divisa solo aplica a
     * la exposicion en divisa distinta de esta.
     */
    public const BASE_CURRENCY = 'EUR';

    /**
     * Etiqueta del grupo que recoge los tickers para los que el proveedor
     * no devuelve sector, para que los pesos por sector sigan sumando 100%
     * en vez de ignorar esas posiciones.
     */
    public const UNKNOWN_SECTOR = 'Sin sector';

    /**
     * @param float $totalValueEur valor de mercado total de las posiciones abiertas, ya en euros
     * @param array<string,float> $positionWeights ticker => % del total, orden descendente
     * @param array<string,float> $sectorWeights sector => % del total, orden descendente
     * @param array<string,float> $currencyWeights divisa nativa => % del total, orden descendente
     */
    public function __construct(
        private readonly float $totalValueEur,
        private readonly array $positionWeights,
        private readonly array $sectorWeights,
        private readonly array $currencyWeights
    ) {
    }

    public function getTotalValueEur(): float
    {
        return $this->totalValueEur;
    }

    /**
     * @return array<string,float>
     */
    public function getPositionWeights(): array
    {
        return $this->positionWeights;
    }

    /**
     * @return array<string,float>
     */
    public function getSectorWeights(): array
    {
        return $this->sectorWeights;
    }

    /**
     * @return array<string,float>
     */
    public function getCurrencyWeights(): array
    {
        return $this->currencyWeights;
    }

    public function getPositionCount(): int
    {
        return count($this->positionWeights);
    }

    /**
     * Indice de Herfindahl-Hirschman: suma de los cuadrados de los pesos
     * por posicion expresados en tanto por uno. 1 con una unica posicion,
     * 1/n con n posiciones identicas.
     */
    public function getHerfindahlIndex(): float
    {
        $index = 0.0;

        foreach ($this->positionWeights as $weight) {
            $index += ($weight / 100) ** 2;
        }

        return $index;
    }

    /**
     * Numero de posiciones "efectivas" (1/HHI): cuantas posiciones
     * igualmente ponderadas darian la misma concentracion que la cartera
     * real. Una cartera de 13 posiciones muy desequilibrada puede
     * comportarse como si tuviera 5.
     */
    public function getEffectivePositions(): float
    {
        $index = $this->getHerfindahlIndex();

        return $index <= 0.0 ? 0.0 : 1 / $index;
    }

    /**
     * Peso acumulado de las $count posiciones mas grandes (las mas
     * grandes, porque $positionWeights ya viene en orden descendente).
     */
    public function getTopPositionsWeight(int $count): float
    {
        if ($count <= 0) {
            return 0.0;
        }

        return array_sum(array_slice($this->positionWeights, 0, $count, true));
    }

    /**
     * @return array<string,float> posiciones que superan el umbral de aviso
     */
    public function getOverweightPositions(): array
    {
        return array_filter(
            $this->positionWeights,
            static fn (float $weight): bool => $weight > self::POSITION_WARNING_PERCENT
        );
    }

    /**
     * Sectores que superan el umbral de aviso, EXCLUYENDO el grupo
     * UNKNOWN_SECTOR (ver versions.md v2.85): "Sin sector" no es un sector,
     * es la ausencia del dato, asi que avisar de que la cartera esta
     * concentrada ahi no dice nada sobre su concentracion sectorial y
     * ademas suena a un riesgo que no se ha medido. Sigue contando en
     * getSectorWeights() (los pesos deben sumar 100%) y en el HHI: lo que se
     * suprime es el veredicto, no el dato.
     *
     * @return array<string,float> sectores que superan el umbral de aviso
     */
    public function getOverweightSectors(): array
    {
        return array_filter(
            $this->sectorWeights,
            static fn (float $weight, string $sector): bool
                => $sector !== self::UNKNOWN_SECTOR && $weight > self::SECTOR_WARNING_PERCENT,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @return array<string,float> divisas distintas de la de referencia que superan el umbral de aviso
     */
    public function getOverweightForeignCurrencies(): array
    {
        return array_filter(
            $this->currencyWeights,
            static fn (float $weight, string $currency): bool
                => $currency !== self::BASE_CURRENCY && $weight > self::FOREIGN_CURRENCY_WARNING_PERCENT,
            ARRAY_FILTER_USE_BOTH
        );
    }
}
