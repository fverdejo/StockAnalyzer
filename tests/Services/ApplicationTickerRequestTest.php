<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Services\Application;
use StockAnalyzer\Utils\TickerNormalizer;

/**
 * `Application::resolveTickerRequest()` decide QUE se analiza en cada
 * peticion del Home, del detalle, de la API y del backtesting, a partir de
 * `?universe=` y `?tickers=`. Es la puerta de entrada del motor y no tenia
 * ni un test, pese a acumular dos incidencias ya corregidas a mano:
 *
 * - `v2.5.2`: el universo "por defecto" nunca era configurable de verdad,
 *   porque un fallback interno forzaba `largecap60` aunque se pidiera otro.
 * - `v2.35`: la pantalla de backtesting precargaba tickers sin que el
 *   usuario hubiera enviado nada, dando la falsa impresion de entrada
 *   manual.
 *
 * `Application` se instancia sin constructor (su constructor es la raiz de
 * composicion: abriria una Connection real) y se le inyectan solo las tres
 * colaboraciones que este metodo usa.
 */
final class ApplicationTickerRequestTest extends TestCase
{
    private Application $application;

    /** @var array<string,mixed> */
    private array $originalGet = [];

    protected function setUp(): void
    {
        $this->originalGet = $_GET;
        $this->application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty(Application::class, 'universeConfig'))
            ->setValue($this->application, new UniverseConfig());
        (new ReflectionProperty(Application::class, 'tickerNormalizer'))
            ->setValue($this->application, new TickerNormalizer());
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
    }

    /**
     * @return array{0: string, 1: list<string>, 2: string}
     */
    private function resolve(array $query): array
    {
        $_GET = $query;

        /** @var array{0: string, 1: list<string>, 2: string} $result */
        $result = (new ReflectionMethod(Application::class, 'resolveTickerRequest'))->invoke($this->application);

        return $result;
    }

    public function testUnUniversoValidoDevuelveSusTickers(): void
    {
        [$raw, $tickers, $universe] = $this->resolve(['universe' => 'magnificent7']);

        self::assertSame('magnificent7', $universe);
        self::assertSame((new UniverseConfig())->tickers('magnificent7'), $tickers);
        self::assertStringContainsString('AAPL', $raw);
    }

    /**
     * La regresion de `v2.5.2`: pedir un universo concreto tiene que
     * respetarse, no caer en el de por defecto.
     */
    public function testUnUniversoValidoNoCaeEnElPorDefecto(): void
    {
        [, $tickers, $universe] = $this->resolve(['universe' => 'ibex35']);

        self::assertSame('ibex35', $universe);
        self::assertContains('SAN.MC', $tickers);
    }

    public function testUnUniversoDesconocidoCaeEnElCuradoPorDefecto(): void
    {
        [, $tickers, $universe] = $this->resolve(['universe' => 'no-existe']);

        self::assertSame('largecap60', $universe);
        self::assertSame((new UniverseConfig())->tickers('largecap60'), $tickers);
    }

    public function testSinParametrosUsaElUniversoCuradoPorDefecto(): void
    {
        [, $tickers, $universe] = $this->resolve([]);

        self::assertSame('largecap60', $universe);
        self::assertSame((new UniverseConfig())->tickers('largecap60'), $tickers);
    }

    /**
     * Tickers escritos a mano mandan sobre el universo, y el universo
     * devuelto queda vacio: es lo que permite a la pantalla de backtesting
     * mostrar "Manual" en vez de un universo que el usuario no eligio
     * (`v2.35`).
     */
    public function testLosTickersManualesMandanYDejanElUniversoVacio(): void
    {
        [$raw, $tickers, $universe] = $this->resolve(['universe' => 'ibex35', 'tickers' => 'AAPL MSFT']);

        self::assertSame('', $universe);
        self::assertSame('AAPL MSFT', $raw);
        self::assertSame(['AAPL', 'MSFT'], $tickers);
    }

    /**
     * El caso contrario: si los tickers recibidos son EXACTAMENTE los de un
     * universo conocido, es que vienen de un enlace interno de la propia
     * app, no de que alguien los escribiera. Ahi el universo se conserva
     * para que el desplegable no se quede en blanco al navegar.
     */
    public function testUnosTickersQueSonUnUniversoConocidoConservanElUniverso(): void
    {
        $magnificent7 = implode(' ', (new UniverseConfig())->tickers('magnificent7'));

        [, $tickers, $universe] = $this->resolve(['universe' => 'magnificent7', 'tickers' => $magnificent7]);

        self::assertSame('magnificent7', $universe);
        self::assertSame((new UniverseConfig())->tickers('magnificent7'), $tickers);
    }

    public function testUnCampoDeTickersEnBlancoNoCuentaComoEntradaManual(): void
    {
        [, , $universe] = $this->resolve(['universe' => 'magnificent7', 'tickers' => '   ']);

        self::assertSame('magnificent7', $universe, 'Un campo con solo espacios no es una entrada manual.');
    }

    /**
     * Mismo bug que `Utils\UniverseTickerResolverTest`, pero en el camino
     * web (2026-09-06, corregido de cara a `msci_world`, ~1.253 tickers):
     * antes de la correccion, `resolveTickerRequest()` pasaba los tickers
     * de CUALQUIER universo por `TickerNormalizer::normalize()`, que trunca
     * a 60 -- invisible mientras ningun universo individual de
     * `config/universes.php` superara ese limite.
     */
    public function testUnUniversoDeMasDe60TickersNoSeTruncaEnElCaminoWeb(): void
    {
        $tickers = array_map(static fn (int $i): string => "TICK$i", range(1, 75));
        (new ReflectionProperty(Application::class, 'universeConfig'))->setValue(
            $this->application,
            new class ($tickers) extends UniverseConfig {
                /** @param list<string> $tickers */
                public function __construct(private readonly array $tickers)
                {
                }

                public function all(): array
                {
                    return ['oversized' => ['label' => 'Oversized', 'tickers' => $this->tickers, 'selectable' => true]];
                }

                public function tickers(string $key): array
                {
                    return $this->all()[$key]['tickers'] ?? [];
                }
            }
        );

        [, $result, $universe] = $this->resolve(['universe' => 'oversized']);

        self::assertSame('oversized', $universe);
        self::assertCount(75, $result);
        self::assertSame('TICK75', $result[74]);
    }

    /**
     * Un universo de "solo cron" (`selectable=false`, ver
     * Config\UniverseConfig::all(), 2026-09-06): pedirlo por `?universe=`
     * en el Home se trata como una clave desconocida, cae en el universo
     * curado por defecto. Existe para universos como `msci_world`
     * (~1.253 tickers): analizarlo en vivo desde el Home arriesgaria
     * timeout/rate-limit del proveedor; `bin/analyze.php` si puede
     * analizarlo porque no pasa por `isValidUniverseKey()`.
     */
    public function testUnUniversoNoSeleccionableCaeEnElPorDefecto(): void
    {
        (new ReflectionProperty(Application::class, 'universeConfig'))->setValue(
            $this->application,
            new class extends UniverseConfig {
                public function all(): array
                {
                    return [
                        'solo_cron' => ['label' => 'Solo cron', 'tickers' => ['XYZ1', 'XYZ2'], 'selectable' => false],
                        'largecap60' => (new UniverseConfig())->all()['largecap60'],
                    ];
                }

                public function tickers(string $key): array
                {
                    return $this->all()[$key]['tickers'] ?? [];
                }
            }
        );

        [, $tickers, $universe] = $this->resolve(['universe' => 'solo_cron']);

        self::assertSame('largecap60', $universe);
        self::assertNotContains('XYZ1', $tickers);
    }
}
