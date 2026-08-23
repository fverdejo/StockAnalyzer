<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use StockAnalyzer\Services\Application;
use StockAnalyzer\Services\PortfolioService;

/**
 * `Application::resolveTradeQuantity()` es el cableado, dentro de
 * `handleTrade()`, entre el formulario de "Comprar o vender" y
 * `PortfolioService::convertEurToNativeCurrency()` (v2.96, ver
 * tests/Services/PortfolioServiceCurrencyConversionTest.php para la
 * conversion en si). Ese metodo del servicio ya tenia sus tests; este
 * fichero cubre lo que le faltaba: el cableado que decide CUANDO se llama
 * (importe tiene prioridad sobre cantidad) y que ocurre con lo que devuelve
 * (redondeo a 6 decimales, o el mensaje de error cuando no hay tipo de
 * cambio, que hasta ahora no verificaba nada).
 *
 * `handleTrade()` en si (el metodo publico) no se prueba aqui porque acaba
 * en `redirect()` -> `header()`/`exit`, que no se puede ejercitar sin tocar
 * superglobals/salida real de PHP; `resolveTradeQuantity()` es la parte
 * aislable de esa logica, mismo patron que
 * tests/Services/ApplicationTickerRequestTest.php.
 */
final class ApplicationTradeQuantityTest extends TestCase
{
    private Application $application;

    /** @var PortfolioService&MockObject */
    private PortfolioService $portfolioService;

    /** @var array<string,mixed> */
    private array $originalPost = [];

    protected function setUp(): void
    {
        $this->originalPost = $_POST;
        $this->application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $this->portfolioService = $this->createMock(PortfolioService::class);

        (new ReflectionProperty(Application::class, 'portfolioService'))
            ->setValue($this->application, $this->portfolioService);
    }

    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
    }

    private function resolve(string $ticker, float $price): float
    {
        /** @var float $result */
        $result = (new ReflectionMethod(Application::class, 'resolveTradeQuantity'))
            ->invoke($this->application, $ticker, $price);

        return $result;
    }

    /**
     * El importe en euros tiene prioridad sobre la cantidad si el
     * formulario llega con los dos rellenos (ver versions.md v2.6): se
     * convierte a la divisa nativa (v2.96) y se divide por el precio.
     */
    public function testConImporteRellenoIgnoraLaCantidadYConvierteAdivisaNativa(): void
    {
        $_POST = ['amount' => '200', 'quantity' => '999'];

        $this->portfolioService
            ->expects(self::once())
            ->method('convertEurToNativeCurrency')
            ->with('AAPL', 200.0)
            ->willReturn(217.391304);

        $quantity = $this->resolve('AAPL', 230.0);

        self::assertEqualsWithDelta(217.391304 / 230.0, $quantity, 0.000001);
    }

    /**
     * El resultado se redondea a 6 decimales: sin este redondeo, el ruido
     * de coma flotante de la division acabaria en la cantidad realmente
     * comprada.
     */
    public function testElResultadoSeRedondeaASeisDecimales(): void
    {
        $_POST = ['amount' => '100'];

        $this->portfolioService->method('convertEurToNativeCurrency')->willReturn(100.0 / 3.0);

        $quantity = $this->resolve('AAPL', 1.0);

        self::assertSame(round(100.0 / 3.0, 6), $quantity);
    }

    /**
     * Sin tipo de cambio disponible (`convertEurToNativeCurrency()` ==
     * null, ver versions.md v2.96), la operacion no puede seguir con un
     * importe inventado: tiene que lanzar, con un mensaje que identifique
     * el ticker, en vez de comprar 0 acciones o interpretar el importe en
     * divisa nativa por error.
     */
    public function testSinTipoDeCambioLanzaEnVezDeContinuar(): void
    {
        $_POST = ['amount' => '200'];

        $this->portfolioService->method('convertEurToNativeCurrency')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AAPL/');

        $this->resolve('AAPL', 230.0);
    }

    /**
     * Sin importe (el campo vacio o a 0), se usa la cantidad de acciones tal
     * cual, sin pasar por conversion de divisa alguna: es el modo "compro N
     * acciones", no "invierto N euros".
     */
    public function testSinImporteUsaLaCantidadSinConvertirDivisa(): void
    {
        $_POST = ['amount' => '0', 'quantity' => '3,5'];

        $this->portfolioService->expects(self::never())->method('convertEurToNativeCurrency');

        self::assertSame(3.5, $this->resolve('AAPL', 230.0));
    }
}
