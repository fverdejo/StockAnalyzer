<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use JsonException;
use RuntimeException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Stock;

class YahooFinanceProvider implements MarketDataProviderInterface
{
    public function __construct(
        private readonly HttpClient $httpClient = new HttpClient(),
        private readonly YahooParser $parser = new YahooParser()
    ) {
    }

    public function getStock(string $ticker): Stock
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new RuntimeException('Ticker cannot be empty.');
        }

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=1d&range=5d',
            rawurlencode($ticker)
        );

        $response = $this->httpClient->get($url);
        $body = (string) $response->getBody();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Yahoo response is not valid JSON.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new RuntimeException('Yahoo response is not an object.');
        }

        return $this->parser->parseStock($payload, $ticker);
    }

    public function getHistoricalQuotes(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new RuntimeException('Ticker cannot be empty.');
        }

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=1d&range=1y',
            rawurlencode($ticker)
        );

        $response = $this->httpClient->get($url);
        $body = (string) $response->getBody();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Yahoo historical response is not valid JSON.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new RuntimeException('Yahoo historical response is not an object.');
        }

        return $this->parser->parseHistoricalQuotes($payload);
    }
}
