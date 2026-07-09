<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

use StockAnalyzer\Enums\ScoreCategory;

class Score
{
    /**
     * @var array<string,float>
     */
    private array $scores = [];

    public function add(ScoreCategory $category, float $value): self
    {
        $value = max(0, min($value, $category->maxScore()));

        $this->scores[$category->value] = round($value, 2);

        return $this;
    }

    public function get(ScoreCategory $category): float
    {
        return $this->scores[$category->value] ?? 0;
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

    public function getRecommendation(): string
    {
        return match (true) {
            $this->getTotal() >= 90 => 'STRONG BUY',
            $this->getTotal() >= 75 => 'BUY',
            $this->getTotal() >= 60 => 'HOLD',
            $this->getTotal() >= 40 => 'SELL',
            default => 'STRONG SELL',
        };
    }

    public function isStrongBuy(): bool
    {
        return $this->getTotal() >= 90;
    }

    public function isBuy(): bool
    {
        return $this->getTotal() >= 75;
    }

    public function isHold(): bool
    {
        return $this->getTotal() >= 60 && $this->getTotal() < 75;
    }

    public function isSell(): bool
    {
        return $this->getTotal() < 60;
    }

    public function toArray(): array
    {
        $scores = [];

        foreach (ScoreCategory::cases() as $category) {
            $scores[] = [
                'key' => $category->value,
                'label' => $category->label(),
                'score' => $this->get($category),
                'max' => $category->maxScore(),
            ];
        }

        return [
            'categories' => $scores,
            'total' => $this->getTotal(),
            'recommendation' => $this->getRecommendation(),
        ];
    }
}