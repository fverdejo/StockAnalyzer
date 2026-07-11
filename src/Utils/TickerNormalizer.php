<?php

declare(strict_types=1);

namespace StockAnalyzer\Utils;

class TickerNormalizer
{
    /**
     * @return list<string>
     */
    public function normalize(string $tickers): array
    {
        $parts = preg_split('/[\s,;]+/', strtoupper(trim($tickers))) ?: [];
        $normalized = [];

        foreach ($parts as $ticker) {
            $ticker = preg_replace('/[^A-Z0-9.\-]/', '', $ticker) ?? '';

            if ($ticker !== '' && !in_array($ticker, $normalized, true)) {
                $normalized[] = $ticker;
            }
        }

        return array_slice($normalized, 0, 10);
    }
}
