<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Interfaces\MarketMoversProviderInterface;
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
        (new ReflectionProperty(Application::class, 'marketMoversProvider'))
            ->setValue($this->application, new class implements MarketMoversProviderInterface {
                public function getTopGainers(int $limit): array
                {
                    return ['GAIN1', 'GAIN2'];
                }

                public function getTopLosers(int $limit): array
                {
                    return ['LOSE1', 'LOSE2'];
                }
            });
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
        self::assertNotContains('GAIN1', $tickers, 'No debe mezclarse con el universo dinamico.');
    }

    public function testUnUniversoDesconocidoCaeEnElCuradoPorDefecto(): void
    {
        [, $tickers, $universe] = $this->resolve(['universe' => 'no-existe']);

        self::assertSame('largecap60', $universe);
        self::assertSame((new UniverseConfig())->tickers('largecap60'), $tickers);
    }

    /**
     * `v2.86`: la pantalla de entrada arranca en la lista curada, no en los
     * movimientos del dia. El motivo esta medido (ver `Application::DEFAULT_UNIVERSE`):
     * la poblacion de movers puntua mucho peor y rota casi entera cada dia.
     */
    public function testSinParametrosUsaElUniversoCuradoNoLosMovimientosDelDia(): void
    {
        [, $tickers, $universe] = $this->resolve([]);

        self::assertSame('largecap60', $universe);
        self::assertSame((new UniverseConfig())->tickers('largecap60'), $tickers);
        self::assertNotContains('GAIN1', $tickers, 'El Home ya no arranca con el screener en vivo.');
    }

    /**
     * El universo dinamico sigue existiendo y funcionando: solo deja de ser
     * la pantalla de entrada.
     */
    public function testElUniversoDeMovimientosSigueResolviendoseEnVivoSiSePide(): void
    {
        [, $tickers, $universe] = $this->resolve(['universe' => 'general']);

        self::assertSame('general', $universe);
        self::assertSame(['GAIN1', 'GAIN2', 'LOSE1', 'LOSE2'], $tickers);
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
     * Si el screener en vivo falla, la peticion no puede romperse: cae en
     * la lista estatica de respaldo de `config/universes.php` (`v2.12`).
     */
    public function testSiElScreenerFallaSeUsaLaListaDeRespaldo(): void
    {
        (new ReflectionProperty(Application::class, 'marketMoversProvider'))
            ->setValue($this->application, new class implements MarketMoversProviderInterface {
                public function getTopGainers(int $limit): array
                {
                    throw new \RuntimeException('El screener no responde.');
                }

                public function getTopLosers(int $limit): array
                {
                    return [];
                }
            });

        [, $tickers, $universe] = $this->resolve(['universe' => 'general']);

        self::assertSame('general', $universe);
        self::assertNotSame([], $tickers);
        self::assertSame((new UniverseConfig())->tickers('general'), $tickers);
    }

    /**
     * Un screener que responde pero sin ningun ticker es tan inservible
     * como uno que falla, y debe tratarse igual.
     */
    public function testUnScreenerVacioTambienCaeEnElRespaldo(): void
    {
        (new ReflectionProperty(Application::class, 'marketMoversProvider'))
            ->setValue($this->application, new class implements MarketMoversProviderInterface {
                public function getTopGainers(int $limit): array
                {
                    return [];
                }

                public function getTopLosers(int $limit): array
                {
                    return [];
                }
            });

        [, $tickers] = $this->resolve(['universe' => 'general']);

        self::assertSame((new UniverseConfig())->tickers('general'), $tickers);
    }
}
