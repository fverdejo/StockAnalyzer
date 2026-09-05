<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Providers\EodhdExchangeSymbolListProvider;

/**
 * `EodhdExchangeSymbolListProvider` (Bloque B6 del plan de Codex del
 * 2026-09-04): archivado crudo de `/api/exchange-symbol-list/{exchange}`.
 * Fixtures confirmadas contra la API real el 2026-09-05 antes de escribir
 * esto (forma de la respuesta: lista JSON de nivel superior, no un objeto).
 */
final class EodhdExchangeSymbolListProviderTest extends TestCase
{
    /**
     * @param list<string> $urls
     */
    private function httpCapturing(string $body, array &$urls, int $status = 200): HttpClient
    {
        $capture = static function (string $url) use (&$urls): void {
            $urls[] = $url;
        };

        return new class ($body, $status, $capture) extends HttpClient {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
                private $onGet
            ) {
            }

            public function get(string $url, array $options = []): Response
            {
                ($this->onGet)($url);

                return new Response($this->status, [], $this->body);
            }
        };
    }

    public function testFetchRawSymbolListJsonPideLaUrlCorrectaYDevuelveElCuerpoOriginal(): void
    {
        $original = '[{"Code":"A","Name":"Agilent Technologies Inc","Country":"USA","Exchange":"NYSE",'
            . '"Currency":"USD","Type":"Common Stock","Isin":"US00846U1016"}]';
        $urls = [];

        $raw = (new EodhdExchangeSymbolListProvider('clave', $this->httpCapturing($original, $urls)))
            ->fetchRawSymbolListJson('US', false);

        self::assertSame($original, $raw);
        self::assertStringContainsString('/exchange-symbol-list/US?', $urls[0]);
        self::assertStringContainsString('delisted=0', $urls[0]);
        self::assertStringContainsString('type=common_stock', $urls[0]);
    }

    public function testDelistedTrueMandaDelisted1(): void
    {
        $urls = [];
        (new EodhdExchangeSymbolListProvider('clave', $this->httpCapturing('[]', $urls)))
            ->fetchRawSymbolListJson('MC', true);

        self::assertStringContainsString('/exchange-symbol-list/MC?', $urls[0]);
        self::assertStringContainsString('delisted=1', $urls[0]);
    }

    /**
     * El exchange se normaliza a mayusculas (misma convencion que el ticker
     * en el resto de proveedores de EODHD de este proyecto).
     */
    public function testElExchangeSeNormalizaAMayusculas(): void
    {
        $urls = [];
        (new EodhdExchangeSymbolListProvider('clave', $this->httpCapturing('[]', $urls)))
            ->fetchRawSymbolListJson('mc', false);

        self::assertStringContainsString('/exchange-symbol-list/MC?', $urls[0]);
    }

    /**
     * Una lista VACIA es un resultado LEGITIMO aqui (una bolsa real sin
     * deslistados de ese tipo), a diferencia de `fetchRawJson()` de
     * Fundamentals: no debe lanzar excepcion.
     */
    public function testUnaListaVaciaNoEsUnError(): void
    {
        $urls = [];
        $raw = (new EodhdExchangeSymbolListProvider('clave', $this->httpCapturing('[]', $urls)))
            ->fetchRawSymbolListJson('MC', true);

        self::assertSame('[]', $raw);
    }

    /**
     * Una bolsa desconocida responde 404 "Exchange Not Found.": llega como
     * error legible, sin filtrar la API key.
     */
    public function testUnaBolsaDesconocidaLlegaComoErrorLegibleSinFiltrarLaApiKey(): void
    {
        $urls = [];
        $provider = new EodhdExchangeSymbolListProvider(
            'CLAVE-SECRETA-123',
            $this->httpCapturing('Exchange Not Found.', $urls, 404)
        );

        try {
            $provider->fetchRawSymbolListJson('ZZ', false);
            self::fail('Se esperaba una MarketDataException.');
        } catch (MarketDataException $exception) {
            self::assertStringContainsString('ZZ', $exception->getMessage());
            self::assertStringContainsString('404', $exception->getMessage());
            self::assertStringNotContainsString('CLAVE-SECRETA-123', $exception->getMessage());
        }
    }

    public function testUnCuerpoQueNoEsJsonLanzaExcepcion(): void
    {
        $urls = [];
        $provider = new EodhdExchangeSymbolListProvider('clave', $this->httpCapturing('esto no es json', $urls));

        $this->expectException(MarketDataException::class);

        $provider->fetchRawSymbolListJson('US', false);
    }

    public function testUnCuerpoQueNoEsUnaListaLanzaExcepcion(): void
    {
        $urls = [];
        $provider = new EodhdExchangeSymbolListProvider('clave', $this->httpCapturing('{"foo":"bar"}', $urls));

        $this->expectException(MarketDataException::class);

        $provider->fetchRawSymbolListJson('US', false);
    }

    public function testUnExchangeVacioNoLlegaAGastarUnaLlamada(): void
    {
        $http = new class extends HttpClient {
            public function __construct()
            {
            }

            public function get(string $url, array $options = []): Response
            {
                throw new \LogicException('No deberia llamarse al proveedor con un exchange vacio.');
            }
        };

        $this->expectException(MarketDataException::class);

        (new EodhdExchangeSymbolListProvider('clave', $http))->fetchRawSymbolListJson('   ', false);
    }
}
