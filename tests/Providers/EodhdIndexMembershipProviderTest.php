<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Providers\EodhdIndexMembershipProvider;

/**
 * Cubre EodhdIndexMembershipProvider (roadmap.md, "Segundo bloque" punto 1,
 * 2026-09-02): construccion de URL/simbolo, propagacion de errores sin
 * filtrar la api_key, y el parametro historical=1.
 */
final class EodhdIndexMembershipProviderTest extends TestCase
{
    /**
     * @param array<string,mixed> $payload
     */
    private function provider(
        array $payload,
        int $status = 200,
        ?callable $captureUrl = null
    ): EodhdIndexMembershipProvider {
        $http = new class ($payload, $status, $captureUrl) extends HttpClient {
            /**
             * @param array<string,mixed> $payload
             */
            public function __construct(
                private readonly array $payload,
                private readonly int $status,
                private $captureUrl
            ) {
            }

            public function get(string $url, array $options = []): Response
            {
                if ($this->captureUrl !== null) {
                    ($this->captureUrl)($url);
                }

                return new Response($this->status, [], json_encode($this->payload) ?: '{}');
            }
        };

        return new EodhdIndexMembershipProvider('clave-secreta-de-prueba', $http);
    }

    public function testAnadeSufijoIndxCuandoElCodigoNoLoTrae(): void
    {
        $capturedUrl = null;
        $provider = $this->provider(['General' => ['Code' => 'GSPC']], 200, function (string $url) use (&$capturedUrl): void {
            $capturedUrl = $url;
        });

        $provider->fetchRawJson('GSPC');

        self::assertStringContainsString('GSPC.INDX', (string) $capturedUrl);
    }

    public function testHistoricalTrueAnadeParametrosDeRango(): void
    {
        $capturedUrl = null;
        $provider = $this->provider(['General' => []], 200, function (string $url) use (&$capturedUrl): void {
            $capturedUrl = $url;
        });

        $provider->fetchRawJson('GSPC', true, '2026-09-02');

        self::assertStringContainsString('historical=1', (string) $capturedUrl);
        self::assertStringContainsString('from=2012-01-01', (string) $capturedUrl);
        self::assertStringContainsString('to=2026-09-02', (string) $capturedUrl);
    }

    public function testSinHistoricalNoAnadeParametrosDeRango(): void
    {
        $capturedUrl = null;
        $provider = $this->provider(['General' => []], 200, function (string $url) use (&$capturedUrl): void {
            $capturedUrl = $url;
        });

        $provider->fetchRawJson('MID');

        self::assertStringNotContainsString('historical=', (string) $capturedUrl);
    }

    public function testDevuelveElCuerpoOriginalSinReordenar(): void
    {
        $provider = $this->provider(['General' => ['Code' => 'GSPC'], 'HistoricalTickerComponents' => ['0' => ['Code' => 'A']]]);

        $raw = $provider->fetchRawJson('GSPC');

        self::assertStringContainsString('"HistoricalTickerComponents"', $raw);
    }

    public function test404LanzaConMensajeClaroYSinLaApiKey(): void
    {
        $provider = $this->provider([], 404);

        try {
            $provider->fetchRawJson('NOEXISTE');
            self::fail('Se esperaba MarketDataException.');
        } catch (MarketDataException $exception) {
            self::assertStringContainsString('404', $exception->getMessage());
            self::assertStringNotContainsString('clave-secreta-de-prueba', $exception->getMessage());
        }
    }

    public function testCodigoVacioLanzaSinLlamarALaRed(): void
    {
        $this->expectException(MarketDataException::class);

        $this->provider(['General' => []])->fetchRawJson('   ');
    }

    public function testCodigoConPuntoNoDuplicaElSufijo(): void
    {
        $capturedUrl = null;
        $provider = $this->provider(['General' => []], 200, function (string $url) use (&$capturedUrl): void {
            $capturedUrl = $url;
        });

        $provider->fetchRawJson('GSPC.INDX');

        self::assertStringNotContainsString('GSPC.INDX.INDX', (string) $capturedUrl);
    }
}
