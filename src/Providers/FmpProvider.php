<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateTimeImmutable;
use JsonException;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;
use Throwable;

/**
 * Segundo proveedor real de MarketDataProviderInterface, junto a Yahoo
 * Finance (ver config/provider.php, ProviderConfigPage). El plan gratuito
 * de Financial Modeling Prep (250 llamadas/dia, 512MB/30 dias) solo cubre
 * grandes cotizadas de EEUU y no ofrece velas intradia: ver
 * getIntradayQuotes() y las notas de cada metodo.
 */
class FmpProvider implements MarketDataProviderInterface
{
    private const BASE_URL = 'https://financialmodelingprep.com/stable/';

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient = new HttpClient(),
        private readonly FmpParser $parser = new FmpParser()
    ) {
    }

    public function getStock(string $ticker): Stock
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $quotePayload = $this->fetchJson('quote', ['symbol' => $ticker], $ticker);
        $quoteData = $this->parser->parseQuote($quotePayload, $ticker);

        $profile = $this->fetchProfileSafely($ticker);
        $fundamentals = $this->fetchFundamentalsSafely($ticker, $quoteData['marketCap']);

        $company = new Company(
            $ticker,
            $profile['name'] !== '' ? $profile['name'] : $quoteData['name'],
            $profile['sector'],
            $profile['industry'],
            $quoteData['exchange'],
            $profile['currency']
        );

        $quote = new Quote(
            $quoteData['price'],
            $quoteData['open'],
            $quoteData['high'],
            $quoteData['low'],
            $quoteData['price'],
            $quoteData['volume'],
            (new DateTimeImmutable())->setTimestamp($quoteData['timestamp'])
        );

        return new Stock($company, $quote, $fundamentals);
    }

    public function getHistoricalQuotes(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $to = new DateTimeImmutable();
        $from = $to->modify('-2 years');

        $payload = $this->fetchJson('historical-price-eod/full', [
            'symbol' => $ticker,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ], $ticker);

        // FMP entrega el historico en orden descendente (mas reciente
        // primero); el resto de la app (TechnicalAnalyzer, BacktestingService)
        // asume orden ascendente, igual que ya devuelve YahooParser.
        $quotes = array_reverse($this->parser->parseHistoricalQuotes($payload));

        if ($quotes === []) {
            throw new MarketDataException(sprintf('Financial Modeling Prep no devolvio historico para %s.', $ticker));
        }

        return $quotes;
    }

    public function getIntradayQuotes(string $ticker, string $interval): array
    {
        throw new MarketDataException(
            'Financial Modeling Prep no ofrece velas intradia en el plan gratuito. Cambia a Yahoo Finance para ver este grafico.'
        );
    }

    /**
     * @return array{name: string, sector: string, industry: string, currency: string}
     */
    private function fetchProfileSafely(string $ticker): array
    {
        try {
            $payload = $this->fetchJson('profile', ['symbol' => $ticker], $ticker);

            return $this->parser->parseProfile($payload);
        } catch (Throwable) {
            return ['name' => '', 'sector' => '', 'industry' => '', 'currency' => ''];
        }
    }

    private function fetchFundamentalsSafely(string $ticker, ?float $marketCapFallback): Fundamentals
    {
        try {
            $ratiosPayload = $this->fetchJson('ratios-ttm', ['symbol' => $ticker], $ticker);
            $keyMetricsPayload = $this->fetchJson('key-metrics-ttm', ['symbol' => $ticker], $ticker);

            return $this->parser->parseFundamentals($ratiosPayload, $keyMetricsPayload, $marketCapFallback);
        } catch (Throwable) {
            // Fundamentales vacios salvo el marketCap ya obtenido en el
            // quote (paso 1), mismo criterio de resiliencia que
            // YahooFinanceProvider::fetchFundamentalsAndProfileSafely().
            return new Fundamentals(
                per: null,
                peg: null,
                roe: null,
                roic: null,
                eps: null,
                marketCap: $marketCapFallback,
                debtToEquity: null,
                freeCashFlow: null
            );
        }
    }

    /**
     * @param array<string,string> $query
     * @return array<int|string,mixed>
     */
    private function fetchJson(string $endpoint, array $query, string $ticker): array
    {
        $query['apikey'] = $this->apiKey;

        $url = self::BASE_URL . $endpoint . '?' . http_build_query($query);

        $response = $this->httpClient->get($url);
        $body = (string) $response->getBody();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException(sprintf(
                'Financial Modeling Prep no devolvio JSON para %s (posible ticker no soportado en el plan gratuito o endpoint restringido): %s',
                $ticker,
                substr($body, 0, 200)
            ), 0, $exception);
        }

        if (is_array($payload) && isset($payload['Error Message'])) {
            throw new MarketDataException((string) $payload['Error Message']);
        }

        if (!is_array($payload)) {
            throw new MarketDataException(sprintf('Financial Modeling Prep response for %s is not an array.', $ticker));
        }

        if ($payload === []) {
            throw new MarketDataException(sprintf('Financial Modeling Prep no devolvio datos para %s.', $ticker));
        }

        return $payload;
    }
}
