<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use JsonException;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Interfaces\MarketMoversProviderInterface;

/**
 * Obtiene los tickers que mas suben ("day_gainers") y mas bajan
 * ("day_losers") en la sesion actual, usando el "screener" predefinido de
 * Yahoo Finance (mercado de EEUU). A diferencia de quoteSummary (ver
 * YahooFundamentalsFetcher), este endpoint no exige crumb ni cookies de
 * sesion, asi que es mas sencillo, pero sigue siendo un endpoint no
 * oficial y puede cambiar sin aviso.
 *
 * Fuente de los datos (se muestra tambien en el Home, ver
 * Web/DashboardPage): https://finance.yahoo.com (screener "Day Gainers" /
 * "Day Losers"), via query1.finance.yahoo.com.
 */
class YahooMarketMoversProvider implements MarketMoversProviderInterface
{
    private const BROWSER_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept' => '*/*',
    ];

    public function __construct(
        private readonly HttpClient $httpClient = new HttpClient()
    ) {
    }

    public function getTopGainers(int $count): array
    {
        return $this->fetch('day_gainers', $count);
    }

    public function getTopLosers(int $count): array
    {
        return $this->fetch('day_losers', $count);
    }

    /**
     * @return list<string>
     */
    private function fetch(string $screenerId, int $count): array
    {
        $count = max(1, min(100, $count));

        $url = sprintf(
            'https://query1.finance.yahoo.com/v1/finance/screener/predefined/saved?lang=en-US&region=US&scrIds=%s&count=%d',
            rawurlencode($screenerId),
            $count
        );

        $response = $this->httpClient->get($url, ['headers' => self::BROWSER_HEADERS]);
        $body = (string) $response->getBody();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException("La respuesta del screener {$screenerId} no es JSON valido.", 0, $exception);
        }

        $quotes = $payload['finance']['result'][0]['quotes'] ?? null;

        if (!is_array($quotes)) {
            throw new MarketDataException("La respuesta del screener {$screenerId} no contiene resultados.");
        }

        $tickers = [];

        foreach ($quotes as $quote) {
            $symbol = is_array($quote) ? ($quote['symbol'] ?? null) : null;

            if (is_string($symbol) && $symbol !== '') {
                $tickers[] = strtoupper($symbol);
            }
        }

        if ($tickers === []) {
            throw new MarketDataException("El screener {$screenerId} no devolvio ningun ticker.");
        }

        return $tickers;
    }
}
