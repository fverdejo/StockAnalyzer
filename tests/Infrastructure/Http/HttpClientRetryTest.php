<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Infrastructure\Http;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Infrastructure\Http\HttpClient;

/**
 * El 429 de Yahoo es rate limiting, no un problema del ticker (ver
 * fiabilidad-datos-mercado.md): antes de este reintento se propagaba tal
 * cual en el primer intento, igual que cualquier otro codigo de error.
 * Estos casos usan un MockHandler de Guzzle (sin red real) y un sleeper
 * inyectado que solo registra la espera pedida, para no bloquear la suite
 * con esperas reales.
 */
final class HttpClientRetryTest extends TestCase
{
    /**
     * @param array<int,Response> $queue
     * @param list<float> $sleeps Se rellena por referencia con cada espera pedida.
     */
    private function clientWithQueue(array $queue, array &$sleeps): HttpClient
    {
        $mock = new MockHandler($queue);
        $handlerStack = HandlerStack::create($mock);

        $sleeps = [];
        $sleeper = static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        };

        return new HttpClient($handlerStack, $sleeper);
    }

    public function testUn429SeguidoDeUn200SeResuelveConReintento(): void
    {
        $sleeps = [];
        $client = $this->clientWithQueue([
            new Response(429),
            new Response(200, [], 'ok'),
        ], $sleeps);

        $response = $client->get('https://query1.finance.yahoo.com/v8/finance/chart/AAPL');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $sleeps);
    }

    public function testRespetaElHeaderRetryAfterEnVezDelBackoffExponencial(): void
    {
        $sleeps = [];
        $client = $this->clientWithQueue([
            new Response(429, ['Retry-After' => '3']),
            new Response(200, [], 'ok'),
        ], $sleeps);

        $client->get('https://query1.finance.yahoo.com/v8/finance/chart/AAPL');

        self::assertSame([3.0], $sleeps);
    }

    public function testSinRetryAfterUsaBackoffExponencialCorto(): void
    {
        $sleeps = [];
        $client = $this->clientWithQueue([
            new Response(429),
            new Response(429),
            new Response(200, [], 'ok'),
        ], $sleeps);

        $client->get('https://query1.finance.yahoo.com/v8/finance/chart/AAPL');

        self::assertSame([1.0, 2.0], $sleeps);
    }

    /**
     * Acotado a 3 intentos en total (1 inicial + 2 reintentos): un 429
     * persistente debe seguir propagandose como excepcion, no colgar la
     * peticion indefinidamente.
     */
    public function testUn429PersistenteAcabaPropagandoLaExcepcionTrasElLimiteDeIntentos(): void
    {
        $sleeps = [];
        $client = $this->clientWithQueue([
            new Response(429),
            new Response(429),
            new Response(429),
        ], $sleeps);

        $this->expectException(RequestException::class);

        try {
            $client->get('https://query1.finance.yahoo.com/v8/finance/chart/AAPL');
        } finally {
            self::assertCount(2, $sleeps);
        }
    }

    /**
     * 404/402 no son un problema temporal (ticker delistado o plan sin
     * cobertura, ver fiabilidad-datos-mercado.md): deben seguir
     * propagandose tal cual en el primer intento, sin reintento.
     */
    public function testUn404NoReintentaYSePropagaEnElPrimerIntento(): void
    {
        $sleeps = [];
        $client = $this->clientWithQueue([
            new Response(404),
            new Response(200, [], 'no deberia llegar a pedirse'),
        ], $sleeps);

        $this->expectException(RequestException::class);

        try {
            $client->get('https://query1.finance.yahoo.com/v8/finance/chart/DELISTED');
        } finally {
            self::assertCount(0, $sleeps);
        }
    }
}
