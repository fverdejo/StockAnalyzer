<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use Throwable;

/**
 * Obtiene el crumb y las cookies de sesion que Yahoo exige para consultar
 * quoteSummary (donde viven los datos fundamentales). Es la pieza mas
 * fragil de todo el proveedor: quoteSummary no es una API publica ni
 * documentada, y este mecanismo (cookie de consentimiento + crumb) ha
 * cambiado varias veces en los ultimos años sin aviso previo, a veces con
 * limites de peticiones agresivos. Por eso vive aislada en su propia
 * clase: si deja de funcionar, es el unico sitio que hay que tocar, o se
 * puede sustituir el proveedor entero por otro (Finnhub, Alpha Vantage,
 * Twelve Data...) gracias a MarketDataProviderInterface sin tocar el
 * resto de la aplicacion.
 *
 * El crumb se obtiene una sola vez por instancia y se reutiliza en las
 * siguientes llamadas, para no repetir el hallazgo de crumb en cada
 * ticker de un mismo ranking.
 */
class YahooFundamentalsFetcher
{
    private const BROWSER_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept' => '*/*',
        'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
    ];

    private const MODULES = 'defaultKeyStatistics,financialData,summaryDetail';

    private ?string $crumb = null;
    private bool $crumbFetchAttempted = false;

    public function __construct(
        private readonly HttpClient $httpClient
    ) {
    }

    /**
     * @return array<string,mixed> Payload JSON de quoteSummary ya decodificado.
     */
    public function fetch(string $ticker): array
    {
        $crumb = $this->getCrumb();

        $url = sprintf(
            'https://query1.finance.yahoo.com/v10/finance/quoteSummary/%s?modules=%s&crumb=%s',
            rawurlencode($ticker),
            self::MODULES,
            rawurlencode($crumb)
        );

        $response = $this->httpClient->get($url, ['headers' => self::BROWSER_HEADERS]);
        $body = (string) $response->getBody();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new MarketDataException('La respuesta de quoteSummary no es JSON valido.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new MarketDataException('La respuesta de quoteSummary no es un objeto.');
        }

        return $payload;
    }

    private function getCrumb(): string
    {
        if ($this->crumb !== null) {
            return $this->crumb;
        }

        if ($this->crumbFetchAttempted) {
            throw new MarketDataException('No se pudo obtener un crumb valido de Yahoo Finance (ya se intento antes en esta peticion).');
        }

        $this->crumbFetchAttempted = true;

        // Peticion de calentamiento: solo sirve para que Yahoo asigne
        // cookies de sesion al CookieJar del HttpClient. Si falla, seguimos
        // igualmente: a veces el crumb se obtiene con las cookies por
        // defecto.
        try {
            $this->httpClient->get('https://fc.yahoo.com', ['headers' => self::BROWSER_HEADERS]);
        } catch (Throwable) {
            // Intencionadamente ignorado, ver comentario anterior.
        }

        try {
            $response = $this->httpClient->get(
                'https://query2.finance.yahoo.com/v1/test/getcrumb',
                ['headers' => self::BROWSER_HEADERS]
            );
        } catch (Throwable $exception) {
            throw new MarketDataException('No se pudo contactar el endpoint de crumb de Yahoo Finance.', 0, $exception);
        }

        $crumb = trim((string) $response->getBody());

        if ($crumb === '' || str_contains($crumb, '<') || strlen($crumb) > 40) {
            throw new MarketDataException('Yahoo Finance no devolvio un crumb valido (puede haber cambiado el mecanismo de autenticacion).');
        }

        $this->crumb = $crumb;

        return $crumb;
    }
}
