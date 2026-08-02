<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateInterval;
use DateTimeImmutable;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Stock;
use Throwable;

/**
 * Proveedor de mercado alternativo a Yahoo, via la API oficial de Finnhub
 * (https://finnhub.io/api/v1). Verificado contra la API real (plan
 * gratuito) el 2026-08-02; ver el informe de fiabilidad-datos-mercado para
 * el detalle completo. Resumen honesto de lo que SI y NO funciona en ese
 * plan (importante antes de activarlo como proveedor por defecto):
 *
 *  - /quote, /stock/profile2 y /stock/metric?metric=all funcionan bien
 *    para tickers de EEUU (probado con AAPL).
 *  - /stock/candle (velas, tanto diarias como intradia) devuelve HTTP 403
 *    "You don't have access to this resource." para CUALQUIER simbolo y
 *    CUALQUIER resolucion en el plan gratuito, incluido AAPL. Esto
 *    significa que getHistoricalQuotes() y getIntradayQuotes() NO pueden
 *    devolver datos reales con esta clave: siempre lanzaran
 *    MarketDataException. Activar Finnhub como proveedor activo hoy
 *    romperia el ranking, el analisis tecnico y el backtesting (todos
 *    dependen de historico), que no fallan con Yahoo.
 *  - Los tickers con sufijo de mercado explicito (".MC", etc, usados por
 *    el universo ibex35 de esta app) devuelven el mismo 403 en TODOS los
 *    endpoints probados (quote, profile2, metric, candle,
 *    calendar/earnings), tanto si el ticker es valido (SAN.MC) como si
 *    Finnhub lo reconoce por busqueda (/search). Sin sufijo, algunos
 *    tickers "coinciden" con el listado principal (ej. "SAN" resuelve a
 *    Banco Santander), pero esto no es fiable ni generalizable (otros
 *    tickers como "ITX" devuelven una cotizacion vacia sin avisar del
 *    motivo). En la practica, Finnhub (plan gratuito) no cubre el universo
 *    ibex35 de esta app.
 *  - /stock/dividend devuelve 403 en el plan gratuito para cualquier
 *    rango, pasado o futuro, incluso para AAPL: no puede usarse como
 *    fuente de fecha ex-dividendo (ver DTO\CorporateEvents, que usa
 *    siempre Yahoo para ese dato independientemente del proveedor activo).
 *  - /calendar/earnings SI funciona en el plan gratuito para tickers de
 *    EEUU (con from/to en el futuro devuelve la proxima fecha estimada),
 *    pero no para tickers con sufijo de mercado (mismo 403 de arriba).
 */
class FinnhubProvider implements MarketDataProviderInterface
{
    private const BASE_URL = 'https://finnhub.io/api/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient = new HttpClient(),
        private readonly FinnhubParser $parser = new FinnhubParser()
    ) {
    }

    public function getStock(string $ticker): Stock
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $quote = $this->getJson('/quote', ['symbol' => $ticker]);
        $profile = $this->fetchSafely('/stock/profile2', ['symbol' => $ticker]);
        $metricPayload = $this->fetchSafely('/stock/metric', ['symbol' => $ticker, 'metric' => 'all']);
        $metric = is_array($metricPayload['metric'] ?? null) ? $metricPayload['metric'] : [];

        return $this->parser->parseStock($quote, $profile, $metric, $ticker);
    }

    public function getHistoricalQuotes(string $ticker): array
    {
        return $this->getCandles($ticker, 'D', new DateInterval('P2Y'));
    }

    public function getIntradayQuotes(string $ticker, string $interval): array
    {
        [$resolution, $lookback] = match ($interval) {
            '1m' => ['1', new DateInterval('P1D')],
            '5m' => ['5', new DateInterval('P5D')],
            '15m' => ['15', new DateInterval('P5D')],
            '1h' => ['60', new DateInterval('P1M')],
            default => ['5', new DateInterval('P5D')],
        };

        return $this->getCandles($ticker, $resolution, $lookback);
    }

    /**
     * @return list<\StockAnalyzer\Models\HistoricalQuote>
     */
    private function getCandles(string $ticker, string $resolution, DateInterval $lookback): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $to = new DateTimeImmutable();
        $from = $to->sub($lookback);

        $payload = $this->getJson('/stock/candle', [
            'symbol' => $ticker,
            'resolution' => $resolution,
            'from' => (string) $from->getTimestamp(),
            'to' => (string) $to->getTimestamp(),
        ]);

        return $this->parser->parseCandles($payload);
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function fetchSafely(string $path, array $query): array
    {
        try {
            return $this->getJson($path, $query);
        } catch (Throwable) {
            // Los datos de perfil/fundamentales son un complemento a la
            // cotizacion, no un requisito: si fallan (por ejemplo, un
            // ticker sin sufijo de mercado soportado en el plan gratuito),
            // getStock() sigue devolviendo la cotizacion con fundamentales
            // vacios en lugar de romper el analisis completo, igual que
            // hace YahooFinanceProvider con sus fundamentales.
            return [];
        }
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function getJson(string $path, array $query): array
    {
        $query['token'] = $this->apiKey;
        $url = self::BASE_URL . $path . '?' . http_build_query($query);

        try {
            $response = $this->httpClient->get($url);
        } catch (RequestException $exception) {
            $reason = $this->extractErrorReason($exception);
            throw new MarketDataException(sprintf('Finnhub error on %s: %s', $path, $reason), 0, $exception);
        }

        $body = (string) $response->getBody();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException('Finnhub response is not valid JSON.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new MarketDataException('Finnhub response is not an object.');
        }

        if (isset($payload['error'])) {
            throw new MarketDataException(sprintf('Finnhub error on %s: %s', $path, (string) $payload['error']));
        }

        return $payload;
    }

    private function extractErrorReason(RequestException $exception): string
    {
        $response = $exception->getResponse();

        if ($response === null) {
            return $exception->getMessage();
        }

        $body = (string) $response->getBody();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $body !== '' ? $body : $exception->getMessage();
        }

        if (is_array($payload) && isset($payload['error'])) {
            return (string) $payload['error'];
        }

        return $body !== '' ? $body : $exception->getMessage();
    }
}
