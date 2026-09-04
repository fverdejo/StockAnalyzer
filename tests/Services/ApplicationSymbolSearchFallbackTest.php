<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Interfaces\SymbolSearchProviderInterface;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Services\Application;
use StockAnalyzer\Services\StockAnalysisService;

/**
 * `Application::analyzeTickers()` (llamado desde renderDashboard() y
 * renderApiRanking()) es donde vive el fallback del buscador de texto libre
 * del Home (ver roadmap.md, "Buscador del Home", 2026-09-04): "Nokia" no
 * resuelve a ningun ticker de Yahoo directamente, pero si a traves de una
 * busqueda de simbolo en vivo (`SymbolSearchProviderInterface`, capacidad
 * opcional del proveedor de mercado, mismo patron ya usado para
 * `IndexMembershipCheckerInterface` en `BacktestingService`).
 */
final class ApplicationSymbolSearchFallbackTest extends TestCase
{
    private Application $application;

    /** @var StockAnalysisService&MockObject */
    private StockAnalysisService $analysisService;

    protected function setUp(): void
    {
        $this->application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $this->analysisService = $this->createMock(StockAnalysisService::class);
        $this->inject('analysisService', $this->analysisService);
    }

    private function inject(string $property, object $value): void
    {
        (new ReflectionProperty(Application::class, $property))->setValue($this->application, $value);
    }

    /**
     * @return array{0: list<StockAnalysis>, 1: array<string,string>}
     */
    private function analyzeTickers(array $tickers, string $universe): array
    {
        /** @var array{0: list<StockAnalysis>, 1: array<string,string>} $result */
        $result = (new ReflectionMethod(Application::class, 'analyzeTickers'))
            ->invoke($this->application, $tickers, $universe);

        return $result;
    }

    private function analysisStub(): StockAnalysis
    {
        return $this->createMock(StockAnalysis::class);
    }

    /**
     * Doble minimo de MarketDataProviderInterface + SymbolSearchProviderInterface:
     * ningun metodo de MarketDataProviderInterface se usa en analyzeTickers()
     * (pasa por StockAnalysisService), asi que todos menos searchSymbol()
     * lanzan si se llegasen a invocar por error.
     *
     * El contador de llamadas vive en `$calls` (un `\stdClass` mutable
     * pasado por el llamador), no en una propiedad publica del doble: el
     * doble se devuelve tipado como `MarketDataProviderInterface`
     * (mismo tipo que `Application::$marketDataProvider`), y ese tipo no
     * declara ninguna propiedad de conteo -- PHPStan marcaria
     * `property.notFound` si el test leyera una propiedad que solo existe
     * en la clase anonima concreta.
     */
    private function providerWithSearch(?string $searchResult, \stdClass $calls): MarketDataProviderInterface
    {
        $calls->count = 0;

        return new class ($searchResult, $calls) implements MarketDataProviderInterface, SymbolSearchProviderInterface {
            public function __construct(
                private readonly ?string $searchResult,
                private readonly \stdClass $calls
            ) {
            }

            public function searchSymbol(string $query): ?string
            {
                $this->calls->count++;

                return $this->searchResult;
            }

            public function getStock(string $ticker): Stock
            {
                throw new \LogicException('No usado en este test.');
            }

            public function getHistoricalQuotes(string $ticker): array
            {
                throw new \LogicException('No usado en este test.');
            }

            public function getIntradayQuotes(string $ticker, string $interval): array
            {
                throw new \LogicException('No usado en este test.');
            }

            public function getDividendHistory(string $ticker): array
            {
                throw new \LogicException('No usado en este test.');
            }
        };
    }

    /**
     * El caso motivador: `analyze('NOKIA')` falla, `searchSymbol('NOKIA')`
     * encuentra `'0K8D.L'`, `analyze('0K8D.L')` funciona. Aparece en el
     * ranking con su propio ticker resuelto, sin tratamiento especial.
     */
    public function testUnaBusquedaManualQueFallaSeResuelveConElSimboloEncontrado(): void
    {
        $resolved = $this->analysisStub();

        $this->analysisService->method('analyze')->willReturnCallback(
            function (string $ticker) use ($resolved): StockAnalysis {
                if ($ticker === 'NOKIA') {
                    throw new RuntimeException('Yahoo: not found.');
                }

                if ($ticker === '0K8D.L') {
                    return $resolved;
                }

                throw new \LogicException('Ticker inesperado: ' . $ticker);
            }
        );

        $calls = new \stdClass();
        $provider = $this->providerWithSearch('0K8D.L', $calls);
        $this->inject('marketDataProvider', $provider);

        [$results, $errors] = $this->analyzeTickers(['NOKIA'], '');

        self::assertSame([$resolved], $results);
        self::assertArrayNotHasKey('NOKIA', $errors);
        self::assertSame(1, $calls->count);
    }

    /**
     * Un universo configurado (no busqueda manual) nunca debe gastar una
     * llamada de red extra: un universo grande puede tener tickers ya
     * conocidos como rotos/deslistados (roadmap.md, "Segundo bloque").
     */
    public function testUnUniversoConfiguradoNoIntentaLaBusquedaDeSimbolo(): void
    {
        $this->analysisService->method('analyze')->willThrowException(
            new RuntimeException('Yahoo: not found.')
        );

        $calls = new \stdClass();
        $provider = $this->providerWithSearch('ALGO', $calls);
        $this->inject('marketDataProvider', $provider);

        [$results, $errors] = $this->analyzeTickers(['BROKEN'], 'largecap60');

        self::assertSame([], $results);
        self::assertArrayHasKey('BROKEN', $errors);
        self::assertSame(0, $calls->count);
    }

    /**
     * Sin match (null) o con el mismo ticker que ya fallo, se deja el error
     * original tal cual, sin un segundo intento inutil.
     */
    public function testSinMatchDeBusquedaSeConservaElErrorOriginal(): void
    {
        $this->analysisService->method('analyze')->willThrowException(
            new RuntimeException('Yahoo: not found.')
        );

        $provider = $this->providerWithSearch(null, new \stdClass());
        $this->inject('marketDataProvider', $provider);

        [$results, $errors] = $this->analyzeTickers(['ZZZNOEXISTE'], '');

        self::assertSame([], $results);
        self::assertSame('Yahoo: not found.', $errors['ZZZNOEXISTE']);
    }

    /**
     * Si el reintento con el ticker resuelto tambien falla, se deja el
     * error ORIGINAL (el del ticker escrito por el usuario), no el del
     * ticker resuelto.
     */
    public function testSiElReintentoTambienFallaSeConservaElErrorOriginal(): void
    {
        $this->analysisService->method('analyze')->willReturnCallback(
            function (string $ticker): StockAnalysis {
                throw new RuntimeException($ticker === 'NOKIA' ? 'error original' : 'error del reintento');
            }
        );

        $provider = $this->providerWithSearch('0K8D.L', new \stdClass());
        $this->inject('marketDataProvider', $provider);

        [$results, $errors] = $this->analyzeTickers(['NOKIA'], '');

        self::assertSame([], $results);
        self::assertSame('error original', $errors['NOKIA']);
    }

    /**
     * Un proveedor de mercado que NO implementa SymbolSearchProviderInterface
     * (el caso normal antes de este cambio) no debe romper nada: el fallback
     * simplemente no se intenta.
     */
    public function testUnProveedorSinCapacidadDeBusquedaNoRompeNada(): void
    {
        $this->analysisService->method('analyze')->willThrowException(
            new RuntimeException('Yahoo: not found.')
        );

        $plainProvider = new class implements MarketDataProviderInterface {
            public function getStock(string $ticker): Stock
            {
                throw new \LogicException('No usado en este test.');
            }

            public function getHistoricalQuotes(string $ticker): array
            {
                throw new \LogicException('No usado en este test.');
            }

            public function getIntradayQuotes(string $ticker, string $interval): array
            {
                throw new \LogicException('No usado en este test.');
            }

            public function getDividendHistory(string $ticker): array
            {
                throw new \LogicException('No usado en este test.');
            }
        };

        $this->inject('marketDataProvider', $plainProvider);

        [$results, $errors] = $this->analyzeTickers(['NOKIA'], '');

        self::assertSame([], $results);
        self::assertArrayHasKey('NOKIA', $errors);
    }
}
