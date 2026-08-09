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
    /**
     * Rangos de historico diario que acepta el endpoint de Yahoo. La lista
     * es cerrada a proposito: un rango inventado no falla en la peticion,
     * Yahoo devuelve silenciosamente el rango por defecto, y el llamador
     * creeria estar analizando 10 años cuando en realidad tiene 1 mes.
     */
    private const SUPPORTED_HISTORY_RANGES = ['6mo', '1y', '2y', '5y', '10y', 'max'];

    /**
     * $historyRange es el rango que se pide en getHistoricalQuotes(). Por
     * defecto 2 años, que es lo que necesita la aplicacion web (indicadores
     * tecnicos + grafico) y lo unico que conviene mantener en cache para
     * todo el universo: un historico de 10 años pesa unas 5 veces mas por
     * ticker. Los rangos largos son para investigacion offline
     * (bin/backtest.php), donde el numero de fechas independientes es lo que
     * decide si un resultado significa algo.
     *
     * La serie `close` de Yahoo ya viene ajustada por splits, asi que
     * ampliar el rango no introduce discontinuidades artificiales.
     */
    public function __construct(
        private readonly HttpClient $httpClient = new HttpClient(),
        private readonly YahooParser $parser = new YahooParser(),
        private readonly ?YahooFundamentalsFetcher $fundamentalsFetcher = null,
        private readonly string $historyRange = '2y'
    ) {
        if (!in_array($this->historyRange, self::SUPPORTED_HISTORY_RANGES, true)) {
            throw new \InvalidArgumentException(sprintf(
                "Rango de historico no soportado: '%s'. Valores validos: %s.",
                $this->historyRange,
                implode(', ', self::SUPPORTED_HISTORY_RANGES)
            ));
        }
    }

    /**
     * Rango que pide realmente getHistoricalQuotes(). Lo usa quien envuelve
     * este proveedor en CachedMarketDataProvider para etiquetar la cache con
     * el mismo rango, en vez de repetir la cadena en el punto de montaje y
     * arriesgarse a que ambos se desincronicen.
     */
    public function getHistoryRange(): string
    {
        return $this->historyRange;
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

        [$fundamentals, $sector, $industry] = $this->fetchFundamentalsAndProfileSafely($ticker);

        return $this->parser->parseStock($payload, $ticker, $fundamentals, $sector, $industry);
    }

    public function getHistoricalQuotes(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=1d&range=%s',
            rawurlencode($ticker),
            rawurlencode($this->historyRange)
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
     * Historial de dividendos reales (v8/finance/chart con events=div,
     * mismo endpoint que getHistoricalQuotes() pero con interval=1mo: los
     * dividendos no cambian intradia y un payload mensual pesa ~8KB frente
     * a los ~140KB de interval=1d). range=10y para tener margen suficiente
     * para el CAGR a 5 años que calcula DividendGrowthCalculator (necesita
     * datos de hace 5 años Y del año anterior a ese punto).
     *
     * Best effort: un ticker sin dividendo, o cualquier fallo (red, JSON
     * invalido, formato inesperado), devuelve un array vacio en vez de
     * lanzar, igual que el resto de campos opcionales de este proveedor.
     */
    public function getDividendHistory(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            return [];
        }

        try {
            $url = sprintf(
                'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=1mo&range=10y&events=div',
                rawurlencode($ticker)
            );

            $response = $this->httpClient->get($url);
            $body = (string) $response->getBody();
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($payload)) {
                return [];
            }

            return $this->parser->parseDividendHistory($payload);
        } catch (Throwable) {
            return [];
        }
    }

    public function getIntradayQuotes(string $ticker, string $interval): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $range = match ($interval) {
            '1m' => '1d',
            '5m', '15m' => '5d',
            '1h' => '1mo',
            default => '5d',
        };

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=%s&range=%s',
            rawurlencode($ticker),
            rawurlencode($interval),
            rawurlencode($range)
        );

        $response = $this->httpClient->get($url);
        $body = (string) $response->getBody();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException('Yahoo intraday response is not valid JSON.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new MarketDataException('Yahoo intraday response is not an object.');
        }

        return $this->parser->parseHistoricalQuotes($payload);
    }

    /**
     * Los fundamentales (y, desde que MODULES incluye assetProfile, tambien
     * sector/industria) viajan por un endpoint no oficial y mas fragil que
     * el de cotizacion/historico (ver YahooFundamentalsFetcher). Un fallo
     * aqui nunca debe tumbar el analisis completo de la accion: se registra
     * como fundamentales vacios y sector/industria en '', y el resto de la
     * aplicacion ya sabe tratar esos valores como "dato no disponible".
     *
     * @return array{0: Fundamentals, 1: string, 2: string} Fundamentales, sector e industria.
     */
    private function fetchFundamentalsAndProfileSafely(string $ticker): array
    {
        $fetcher = $this->fundamentalsFetcher ?? new YahooFundamentalsFetcher($this->httpClient);

        try {
            $payload = $fetcher->fetch($ticker);
            $fundamentals = $this->parser->parseFundamentals($payload);
            $profile = $this->parser->parseCompanyProfile($payload);

            return [$fundamentals, $profile['sector'], $profile['industry']];
        } catch (Throwable) {
            return [Fundamentals::empty(), '', ''];
        }
    }
}
