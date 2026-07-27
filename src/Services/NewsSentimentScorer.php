<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

class NewsSentimentScorer
{
    private const POSITIVE = [
        'beats', 'beat', 'record', 'growth', 'upgrade', 'profit', 'profits', 'positive',
        'strong', 'raises', 'raised', 'buyback', 'dividend', 'partnership', 'surge',
        'supera', 'record', 'crecimiento', 'mejora', 'beneficio', 'beneficios', 'sube',
        'alianza', 'dividendo', 'recompra',
    ];

    private const NEGATIVE = [
        'misses', 'miss', 'loss', 'losses', 'downgrade', 'fraud', 'lawsuit', 'weak',
        'cuts', 'cut', 'falls', 'drop', 'plunge', 'warning', 'bankruptcy', 'probe',
        'pierde', 'perdidas', 'demanda', 'fraude', 'debil', 'recorta', 'cae',
        'alerta', 'quiebra', 'investigacion',
    ];

    public function score(string $text): float
    {
        $normalized = strtolower($text);
        $positive = $this->countMatches($normalized, self::POSITIVE);
        $negative = $this->countMatches($normalized, self::NEGATIVE);

        if ($positive === 0 && $negative === 0) {
            return 0.0;
        }

        return max(-1.0, min(1.0, ($positive - $negative) / max(1, $positive + $negative)));
    }

    /**
     * @param list<string> $words
     */
    private function countMatches(string $text, array $words): int
    {
        $count = 0;

        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                $count++;
            }
        }

        return $count;
    }
}
