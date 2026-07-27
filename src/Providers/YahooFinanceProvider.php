<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use JsonException;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Stock;
use Throwable;

class YahooFinanceProvider implements MarketDataProviderInterface
{
    public function __construct(
        private readonly HttpClient $httpClient = new HttpClient(),
        private readonly YahooParser $parser = new YahooParser(),
        private readonly ?YahooFundamentalsFetcher $fundamentalsFetcher = null
    ) {
    }

    public function getStock(string $ticker): Stock
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
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
            throw new MarketDataException('Yahoo response is not valid JSON.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new MarketDataException('Yahoo response is not an object.');
        }

        $fundamentals = $this->fetchFundamentalsSafely($ticker);

        return $this->parser->parseStock($payload, $ticker, $fundamentals);
    }

    public function getHistoricalQuotes(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=1d&range=2y',
            rawurlencode($ticker)
        );

        $response = $this->httpClient->get($url);
        $body = (string) $response->getBody();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException('Yahoo historical response is not valid JSON.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new MarketDataException('Yahoo historical response is not an object.');
        }

        return $this->parser->parseHistoricalQuotes($payload);
    }

    /**
     * Los fundamentales viajan por un endpoint no oficial y mas fragil que
     * el de cotizacion/historico (ver YahooFundamentalsFetcher). Un fallo
     * aqui nunca debe tumbar el analisis completo de la accion: se
     * registra como fundamentales vacios y el resto de la aplicacion ya
     * sabe tratar los campos null como "dato no disponible".
     */
    private function fetchFundamentalsSafely(string $ticker): Fundamentals
    {
        $fetcher = $this->fundamentalsFetcher ?? new YahooFundamentalsFetcher($this->httpClient);

        try {
            $payload = $fetcher->fetch($ticker);

            return $this->parser->parseFundamentals($payload);
        } catch (Throwable) {
            return Fundamentals::empty();
        }
    }
}
