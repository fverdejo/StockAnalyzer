<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Providers\EodhdCalendarProvider;

/**
 * `EodhdCalendarProvider` (Bloques B2/B3 del plan de Codex del 2026-09-04):
 * archivado crudo de `/api/calendar/earnings` y `/api/calendar/trends`.
 * Mismo estilo de fixtures que `EodhdFiscalPeriodProviderTest`: un
 * `HttpClient` anonimo que captura la URL pedida y devuelve un cuerpo fijo,
 * confirmado contra la API real el 2026-09-05 antes de escribir esto.
 */
final class EodhdCalendarProviderTest extends TestCase
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

    public function testFetchRawEarningsJsonPideSymbolsFromToYDevuelveElCuerpoOriginal(): void
    {
        $original = '{"type":"Earnings","symbols":"AAPL.US","earnings":[{"code":"AAPL.US","date":"2024-09-30"}]}';
        $urls = [];

        $raw = (new EodhdCalendarProvider('clave', $this->httpCapturing($original, $urls)))
            ->fetchRawEarningsJson('AAPL', '1970-01-01', '2028-12-31');

        self::assertSame($original, $raw);
        self::assertStringContainsString('/calendar/earnings?', $urls[0]);
        self::assertStringContainsString('symbols=AAPL.US', $urls[0]);
        self::assertStringContainsString('from=1970-01-01', $urls[0]);
        self::assertStringContainsString('to=2028-12-31', $urls[0]);
    }

    public function testFetchRawTrendsJsonPideSymbolsSinFechasYDevuelveElCuerpoOriginal(): void
    {
        $original = '{"type":"Trends","symbols":"AAPL.US","trends":[[{"code":"AAPL.US","date":"2024-09-30"}]]}';
        $urls = [];

        $raw = (new EodhdCalendarProvider('clave', $this->httpCapturing($original, $urls)))
            ->fetchRawTrendsJson('AAPL');

        self::assertSame($original, $raw);
        self::assertStringContainsString('/calendar/trends?', $urls[0]);
        self::assertStringContainsString('symbols=AAPL.US', $urls[0]);
        self::assertStringNotContainsString('from=', $urls[0]);
        self::assertStringNotContainsString('to=', $urls[0]);
    }

    /**
     * Los tickers sin punto (EEUU) reciben ".US"; los que ya traen sufijo
     * de bolsa (SAN.MC) se usan tal cual -- mismo criterio que
     * `EodhdFiscalPeriodProvider`.
     */
    public function testElTickerSinPuntoRecibeUsYElQueYaTraeSufijoSeRespeta(): void
    {
        $urls = [];
        $provider = new EodhdCalendarProvider('clave', $this->httpCapturing('{"trends":[[]]}', $urls));

        $provider->fetchRawTrendsJson('AAPL');
        $provider->fetchRawTrendsJson('SAN.MC');

        self::assertStringContainsString('symbols=AAPL.US', $urls[0]);
        self::assertStringContainsString('symbols=SAN.MC', $urls[1]);
        self::assertStringNotContainsString('SAN.MC.US', $urls[1]);
    }

    /**
     * El override de simbolo (tickers `_old`) se usa EXACTAMENTE tal cual,
     * igual que en `EodhdFiscalPeriodProvider::fetchRawJson()`.
     */
    public function testRespetaElOverrideDeSimboloParaTickersOld(): void
    {
        $urls = [];
        $provider = new EodhdCalendarProvider('clave', $this->httpCapturing('{"earnings":[]}', $urls));

        $provider->fetchRawEarningsJson('APC_OLD', '1970-01-01', '2028-12-31', 'APC_old.US');

        self::assertStringContainsString('symbols=APC_old.US', $urls[0]);
    }

    public function testUnErrorHttpLlegaComoErrorLegibleSinFiltrarLaApiKey(): void
    {
        $urls = [];
        $provider = new EodhdCalendarProvider('CLAVE-SECRETA-123', $this->httpCapturing('Not Found', $urls, 404));

        try {
            $provider->fetchRawEarningsJson('XYZQ', '1970-01-01', '2028-12-31');
            self::fail('Se esperaba una MarketDataException.');
        } catch (MarketDataException $exception) {
            self::assertStringContainsString('XYZQ', $exception->getMessage());
            self::assertStringContainsString('404', $exception->getMessage());
            self::assertStringNotContainsString('CLAVE-SECRETA-123', $exception->getMessage());
        }
    }

    public function testUnCuerpoQueNoEsJsonLanzaExcepcion(): void
    {
        $urls = [];
        $provider = new EodhdCalendarProvider('clave', $this->httpCapturing('esto no es json', $urls));

        $this->expectException(MarketDataException::class);

        $provider->fetchRawTrendsJson('AAPL');
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

        (new EodhdCalendarProvider('clave', $http))->fetchRawTrendsJson('   ');
    }
}
