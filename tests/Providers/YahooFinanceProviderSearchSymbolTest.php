<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Providers\YahooFinanceProvider;

/**
 * `YahooFinanceProvider::searchSymbol()` (ver roadmap.md, "Buscador del
 * Home", 2026-09-04): fallback de busqueda en vivo contra el endpoint no
 * oficial de autocompletado de Yahoo Finance (v1/finance/search), distinto
 * del de cotizacion/historico. Forma real de la respuesta confirmada con una
 * llamada de control antes de escribir el metodo: buscar "Nokia" devuelve
 * `quotes: [{"symbol": "NOK", ...}]`.
 *
 * Sin red real: MockHandler de Guzzle inyectado en HttpClient, mismo patron
 * que tests/Infrastructure/Http/HttpClientRetryTest.php.
 */
final class YahooFinanceProviderSearchSymbolTest extends TestCase
{
    private function providerWithResponse(Response $response): YahooFinanceProvider
    {
        $handlerStack = HandlerStack::create(new MockHandler([$response]));

        return new YahooFinanceProvider(new HttpClient($handlerStack));
    }

    public function testUnaRespuestaConResultadoDevuelveElSimbolo(): void
    {
        $body = json_encode([
            'quotes' => [
                [
                    'exchange' => 'NYQ',
                    'shortname' => 'Nokia Corporation Sponsored',
                    'quoteType' => 'EQUITY',
                    'symbol' => 'NOK',
                    'longname' => 'Nokia Oyj',
                ],
            ],
        ]);

        $provider = $this->providerWithResponse(new Response(200, [], $body ?: '{}'));

        self::assertSame('NOK', $provider->searchSymbol('Nokia'));
    }

    public function testQuotesVacioDevuelveNull(): void
    {
        $body = json_encode(['quotes' => []]);

        $provider = $this->providerWithResponse(new Response(200, [], $body ?: '{}'));

        self::assertNull($provider->searchSymbol('zzzznoexiste'));
    }

    public function testJsonMalformadoDevuelveNull(): void
    {
        $provider = $this->providerWithResponse(new Response(200, [], 'esto no es json'));

        self::assertNull($provider->searchSymbol('Nokia'));
    }

    public function testUnErrorHttpDevuelveNull(): void
    {
        $handlerStack = HandlerStack::create(new MockHandler([new Response(500)]));
        $provider = new YahooFinanceProvider(new HttpClient($handlerStack));

        self::assertNull($provider->searchSymbol('Nokia'));
    }

    public function testUnaQueryVaciaNoLlegaAGastarUnaLlamada(): void
    {
        $http = new class extends HttpClient {
            public function __construct()
            {
            }

            public function get(string $url, array $options = []): \Psr\Http\Message\ResponseInterface
            {
                throw new \LogicException('No deberia llamarse con una query vacia.');
            }
        };

        $provider = new YahooFinanceProvider($http);

        self::assertNull($provider->searchSymbol('   '));
    }
}
