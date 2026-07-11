<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateTimeImmutable;
use RuntimeException;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;

class YahooParser
{
    /**
     * @param array<string,mixed> $payload
     */
    public function parseStock(array $payload, string $ticker): Stock
    {
        $error = $payload['chart']['error'] ?? null;

        if (is_array($error)) {
            $description = $error['description'] ?? 'Unknown Yahoo error.';
            throw new RuntimeException((string) $description);
        }

        $result = $payload['chart']['result'][0] ?? null;

        if (!is_array($result)) {
            throw new RuntimeException('Yahoo response does not contain chart data.');
        }

        $meta = $result['meta'] ?? [];
        $timestamps = $result['timestamp'] ?? [];
        $quoteData = $result['indicators']['quote'][0] ?? [];

        if (!is_array($meta) || !is_array($timestamps) || !is_array($quoteData) || $timestamps === []) {
            throw new RuntimeException('Yahoo response is incomplete.');
        }

        $lastIndex = array_key_last($timestamps);

        if ($lastIndex === null) {
            throw new RuntimeException('Yahoo response does not contain quote timestamps.');
        }

        $price = $this->floatValue($meta['regularMarketPrice'] ?? null);
        $open = $this->floatValueOrFallback($quoteData['open'][$lastIndex] ?? null, $price);
        $high = $this->floatValueOrFallback($quoteData['high'][$lastIndex] ?? null, $price);
        $low = $this->floatValueOrFallback($quoteData['low'][$lastIndex] ?? null, $price);
        $close = $this->floatValueOrFallback($quoteData['close'][$lastIndex] ?? null, $price);
        $volume = $this->intValueOrFallback($quoteData['volume'][$lastIndex] ?? null, 0);
        $date = (new DateTimeImmutable())->setTimestamp($this->intValue($timestamps[$lastIndex]));

        $company = new Company(
            strtoupper($ticker),
            (string) ($meta['shortName'] ?? $meta['symbol'] ?? strtoupper($ticker)),
            '',
            '',
            (string) ($meta['exchangeName'] ?? ''),
            (string) ($meta['currency'] ?? '')
        );

        $quote = new Quote($price, $open, $high, $low, $close, $volume, $date);
        $fundamentals = new Fundamentals(null, null, null, null, null, null, null, null);

        return new Stock($company, $quote, $fundamentals);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<HistoricalQuote>
     */
    public function parseHistoricalQuotes(array $payload): array
    {
        $error = $payload['chart']['error'] ?? null;

        if (is_array($error)) {
            $description = $error['description'] ?? 'Unknown Yahoo error.';
            throw new RuntimeException((string) $description);
        }

        $result = $payload['chart']['result'][0] ?? null;

        if (!is_array($result)) {
            throw new RuntimeException('Yahoo response does not contain historical data.');
        }

        $timestamps = $result['timestamp'] ?? [];
        $quoteData = $result['indicators']['quote'][0] ?? [];

        if (!is_array($timestamps) || !is_array($quoteData)) {
            throw new RuntimeException('Yahoo historical response is incomplete.');
        }

        $quotes = [];

        foreach ($timestamps as $index => $timestamp) {
            $open = $quoteData['open'][$index] ?? null;
            $high = $quoteData['high'][$index] ?? null;
            $low = $quoteData['low'][$index] ?? null;
            $close = $quoteData['close'][$index] ?? null;

            if ($open === null || $high === null || $low === null || $close === null) {
                continue;
            }

            $quotes[] = new HistoricalQuote(
                (new DateTimeImmutable())->setTimestamp($this->intValue($timestamp)),
                $this->floatValue($open),
                $this->floatValue($high),
                $this->floatValue($low),
                $this->floatValue($close),
                $this->intValueOrFallback($quoteData['volume'][$index] ?? null, 0)
            );
        }

        if ($quotes === []) {
            throw new RuntimeException('Yahoo historical response does not contain usable quotes.');
        }

        return $quotes;
    }

    private function floatValue(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('Yahoo response contains a non numeric quote value.');
        }

        return (float) $value;
    }

    private function floatValueOrFallback(mixed $value, float $fallback): float
    {
        if ($value === null) {
            return $fallback;
        }

        return $this->floatValue($value);
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('Yahoo response contains a non numeric integer value.');
        }

        return (int) $value;
    }

    private function intValueOrFallback(mixed $value, int $fallback): int
    {
        if ($value === null) {
            return $fallback;
        }

        return $this->intValue($value);
    }
}
