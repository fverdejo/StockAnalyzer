<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Providers\EodhdSecFilingsProvider;

/**
 * `EodhdSecFilingsProvider` (Bloque B7 del plan de Codex del 2026-09-04):
 * una pagina de `/api/sec-filings/{simbolo}/form4`. La paginacion real
 * (`page[limit]` maximo 100, `meta.total`) se confirmo contra la API real
 * el 2026-09-05 antes de escribir esto (AAPL.US: 602 filings/7 paginas,
 * MSFT.US: 99/1 pagina).
 */
final class EodhdSecFilingsProviderTest extends TestCase
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

    public function testFetchRawForm4PagePideLaUrlConPaginacionYDevuelveElCuerpoOriginal(): void
    {
        $original = '{"data":[{"accession_number":"1"}],"meta":{"total":602,"page":{"offset":0,"limit":100}}}';
        $urls = [];

        $raw = (new EodhdSecFilingsProvider('clave', $this->httpCapturing($original, $urls)))
            ->fetchRawForm4Page('AAPL', 100, 0);

        self::assertSame($original, $raw);
        self::assertStringContainsString('/sec-filings/AAPL.US/form4?', $urls[0]);
        self::assertStringContainsString('page%5Blimit%5D=100', $urls[0]);
        self::assertStringContainsString('page%5Boffset%5D=0', $urls[0]);
    }

    public function testElLimiteSeAcotaAMaxPageLimit(): void
    {
        $urls = [];
        $provider = new EodhdSecFilingsProvider('clave', $this->httpCapturing('{"data":[]}', $urls));

        $provider->fetchRawForm4Page('AAPL', 9999, 0);

        self::assertStringContainsString('page%5Blimit%5D=' . EodhdSecFilingsProvider::MAX_PAGE_LIMIT, $urls[0]);
    }

    public function testElOffsetNegativoSeAcotaACero(): void
    {
        $urls = [];
        $provider = new EodhdSecFilingsProvider('clave', $this->httpCapturing('{"data":[]}', $urls));

        $provider->fetchRawForm4Page('AAPL', 100, -5);

        self::assertStringContainsString('page%5Boffset%5D=0', $urls[0]);
    }

    /**
     * Un ticker no estadounidense (Form 4 es solo para emisores listados en
     * EEUU): confirmado en vivo que EODHD responde 404 "Symbol not found"
     * para SAN.MC.
     */
    public function testUnTickerNoEstadounidenseLlegaComoErrorLegible(): void
    {
        $urls = [];
        $provider = new EodhdSecFilingsProvider('clave', $this->httpCapturing('Symbol not found', $urls, 404));

        $this->expectException(MarketDataException::class);

        $provider->fetchRawForm4Page('SAN.MC');
    }

    /**
     * El sufijo de desambiguacion `_old` (valido en Fundamentals/Calendar)
     * NO es un simbolo valido para sec-filings: confirmado en vivo con
     * `APC_old.US` -> 422 "The symbol must be a valid ticker symbol.".
     */
    public function testUnTickerConSufijoOldLlegaComoErrorLegible(): void
    {
        $urls = [];
        $provider = new EodhdSecFilingsProvider(
            'clave',
            $this->httpCapturing('{"errors":{"symbol":["invalido"]}}', $urls, 422)
        );

        $this->expectException(MarketDataException::class);

        $provider->fetchRawForm4Page('APC_OLD', 100, 0, 'APC_old.US');
    }

    public function testUnCuerpoSinClaveDataLanzaExcepcion(): void
    {
        $urls = [];
        $provider = new EodhdSecFilingsProvider('clave', $this->httpCapturing('{"foo":"bar"}', $urls));

        $this->expectException(MarketDataException::class);

        $provider->fetchRawForm4Page('AAPL');
    }

    /**
     * Un ticker con 0 filings (legitimo, no un error) trae `data: []`: no
     * debe lanzar excepcion, solo devolver el cuerpo tal cual.
     */
    public function testUnaListaDeDatosVaciaNoEsUnError(): void
    {
        $urls = [];
        $original = '{"data":[],"meta":{"total":0,"page":{"offset":0,"limit":100}}}';
        $provider = new EodhdSecFilingsProvider('clave', $this->httpCapturing($original, $urls));

        $raw = $provider->fetchRawForm4Page('O');

        self::assertSame($original, $raw);
    }

    public function testUnTickerVacioNoLlegaAGastarUnaLlamada(): void
    {
        $http = new class extends HttpClient {
            public function __construct()
            {
            }

            public function get(string $url, array $options = []): Response
            {
                throw new \LogicException('No deberia llamarse al proveedor con un ticker vacio.');
            }
        };

        $this->expectException(MarketDataException::class);

        (new EodhdSecFilingsProvider('clave', $http))->fetchRawForm4Page('   ');
    }
}
