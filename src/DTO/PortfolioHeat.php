<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * "Calor de cartera" (portfolio heat, Van Tharp): cuanto se perderia en
 * total si TODOS los stop-loss sugeridos saltaran a la vez, como % del
 * valor de la cartera. Complementa a PortfolioConcentration (que mira
 * COMO esta repartida la cartera) con la pregunta que ninguna metrica
 * existente responde: RiskLevels calibra el 1,5% de riesgo POSICION A
 * POSICION, pero nada suma ese riesgo cuando varios stops saltan juntos
 * en una caida de mercado amplia — justo cuando es mas probable que pase
 * (v2.103, medido por `gestor-riesgo` el 2026-08-25: el riesgo de gap de
 * apertura es transversal a los 6 sectores medidos, no un caso raro).
 *
 * Formulas puras (mismo criterio que DTO\RiskLevels y
 * DTO\PortfolioConcentration): decidir cuando no hay datos suficientes es
 * responsabilidad de Services\PortfolioHeatCalculator.
 */
class PortfolioHeat
{
    /**
     * Umbral de aviso no bloqueante, calibrado con datos de mercado reales
     * (no con backtest predictivo: esto no es una señal de puntuacion, es
     * una metrica de riesgo, mismo estatus epistemico que
     * PortfolioConcentration::POSITION_WARNING_PERCENT). El 6% de
     * referencia de Van Tharp quedaba por DEBAJO del calor tipico de una
     * cartera `largecap60` normal de 10-15 posiciones (6,5%-7,6% medido),
     * lo que habria disparado el aviso casi siempre; 15% queda por encima
     * de toda cartera diversificada medida (maximo observado 12,92% en
     * `semiconductors_global`) y por debajo de lo que sale de concentrar
     * el riesgo siguiendo al pie de la letra el propio dimensionador de la
     * app (~22% con el tope de peso del 20% tocado en la mayoria de
     * posiciones).
     */
    public const WARNING_PERCENT = 15.0;

    /**
     * @param float $totalValueEur valor de mercado total de las posiciones abiertas, ya en euros
     * @param array<string,float> $riskWeights ticker => % del valor total en riesgo si su stop-loss se ejecuta, orden descendente
     * @param list<string> $excludedTickers posiciones sin RiskLevels/precio/tipo de cambio, excluidas de la suma: el calor mostrado es una cota inferior, no el riesgo completo
     */
    public function __construct(
        private readonly float $totalValueEur,
        private readonly array $riskWeights,
        private readonly array $excludedTickers = []
    ) {
    }

    public function getTotalValueEur(): float
    {
        return $this->totalValueEur;
    }

    /**
     * @return array<string,float>
     */
    public function getRiskWeights(): array
    {
        return $this->riskWeights;
    }

    /**
     * @return list<string>
     */
    public function getExcludedTickers(): array
    {
        return $this->excludedTickers;
    }

    public function getTotalHeatPercent(): float
    {
        return array_sum($this->riskWeights);
    }

    public function isHot(): bool
    {
        return $this->getTotalHeatPercent() > self::WARNING_PERCENT;
    }
}
