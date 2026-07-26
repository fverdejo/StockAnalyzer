<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\Enums\ScoreCategory;

class Score
{
    /**
     * @var array<string,float>
     */
    private array $scores = [];

    public function __construct(
        private readonly ScoreWeights $weights = new ScoreWeights()
    ) {
    }

    public function add(ScoreCategory $category, float $value): self
    {
        $value = max(0, min($value, $this->weights->getMax($category)));

        $this->scores[$category->value] = round($value, 2);

        return $this;
    }

    public function get(ScoreCategory $category): float
    {
        return $this->scores[$category->value] ?? 0;
    }

    public function getWeights(): ScoreWeights
    {
        return $this->weights;
    }

    /**
     * @return array<string,float>
     */
    public function getScores(): array
    {
        return $this->scores;
    }

    public function getTotal(): float
    {
        return round(array_sum($this->scores), 2);
    }

    /**
     * Suma de los maximos configurados (ScoreWeights) de todas las
     * categorias. Se recalcula en cada llamada (no sobre lo que se haya
     * añadido con add()) para que siga siendo correcta aunque cambien los
     * pesos o se añadan categorias nuevas, tal y como pide project.md: "el
     * algoritmo podra modificarse sin cambiar la clase Score".
     */
    public function getMaxTotal(): float
    {
        return $this->weights->getTotalMax();
    }

    /**
     * Puntuacion total expresada como porcentaje del maximo posible. Es la
     * base de la recomendacion: usar el total en bruto contra umbrales
     * pensados para una escala fija de 100 daria recomendaciones mal
     * calibradas en cuanto cambiasen los pesos configurados.
     */
    public function getPercentage(): float
    {
        $maxTotal = $this->getMaxTotal();

        if ($maxTotal <= 0) {
            return 0.0;
        }

        return round(($this->getTotal() / $maxTotal) * 100, 2);
    }

    public function getRecommendation(): string
    {
        return match (true) {
            $this->getPercentage() >= 90 => 'STRONG BUY',
            $this->getPercentage() >= 75 => 'BUY',
            $this->getPercentage() >= 60 => 'HOLD',
            $this->getPercentage() >= 40 => 'SELL',
            default => 'STRONG SELL',
        };
    }

    public function isStrongBuy(): bool
    {
        return $this->getPercentage() >= 90;
    }

    public function isBuy(): bool
    {
        return $this->getPercentage() >= 75;
    }

    public function isHold(): bool
    {
        return $this->getPercentage() >= 60 && $this->getPercentage() < 75;
    }

    public function isSell(): bool
    {
        return $this->getPercentage() < 60;
    }

    public function toArray(): array
    {
        $scores = [];

        foreach (ScoreCategory::cases() as $category) {
            $scores[] = [
                'key' => $category->value,
                'label' => $category->label(),
                'score' => $this->get($category),
                'max' => $this->weights->getMax($category),
            ];
        }

        return [
            'categories' => $scores,
            'total' => $this->getTotal(),
            'max_total' => $this->getMaxTotal(),
            'percentage' => $this->getPercentage(),
            'recommendation' => $this->getRecommendation(),
        ];
    }
}
