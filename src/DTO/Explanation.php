<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * Salida de RecommendationExplainer: un resumen en una frase mas las
 * Signals agrupadas por veredicto, listas para pintarse en la pantalla de
 * detalle.
 */
class Explanation
{
    /**
     * @param list<Signal> $positives
     * @param list<Signal> $negatives
     * @param list<Signal> $neutrals
     */
    public function __construct(
        private readonly string $summary,
        private readonly array $positives,
        private readonly array $negatives,
        private readonly array $neutrals
    ) {
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    /**
     * @return list<Signal>
     */
    public function getPositives(): array
    {
        return $this->positives;
    }

    /**
     * @return list<Signal>
     */
    public function getNegatives(): array
    {
        return $this->negatives;
    }

    /**
     * @return list<Signal>
     */
    public function getNeutrals(): array
    {
        return $this->neutrals;
    }
}
