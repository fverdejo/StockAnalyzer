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
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\User;
use StockAnalyzer\Providers\YahooCorporateProfileProvider;
use StockAnalyzer\Repository\CorporateProfileCacheRepository;
use StockAnalyzer\Services\AlertService;
use StockAnalyzer\Services\Application;
use StockAnalyzer\Services\StockAnalysisService;
use DateTimeImmutable;

/**
 * `Application::analyzeHoldingsForAlerts()` es el bucle que recorre las
 * posiciones abiertas cada vez que se abre "Mi cartera": pide el analisis
 * de cada ticker y de paso dispara las cuatro comprobaciones de alertas
 * (cambio de recomendacion, stop-loss, dividendo y resultados).
 *
 * Tiene un `catch (Throwable)` deliberadamente silencioso, con un
 * comentario que explica por que: un fallo del proveedor en UN ticker no
 * puede dejar al usuario sin ver su cartera entera. Yahoo es un endpoint no
 * oficial que devuelve 404 en valores retirados y 429 cuando le aprieta el
 * ritmo, asi que ese caso no es hipotetico.
 *
 * Ese comportamiento no lo comprobaba nada, y un `catch` vacio es
 * exactamente el tipo de codigo que se rompe sin que nadie se entere: basta
 * mover una linea fuera del `try` para que un ticker malo tumbe la pagina.
 */
final class ApplicationHoldingsAnalysisTest extends TestCase
{
    private Application $application;

    /** @var StockAnalysisService&MockObject */
    private StockAnalysisService $analysisService;

    /** @var AlertService&MockObject */
    private AlertService $alertService;

    protected function setUp(): void
    {
        $this->application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $this->analysisService = $this->createMock(StockAnalysisService::class);
        $this->alertService = $this->createMock(AlertService::class);

        $profileProvider = $this->createMock(YahooCorporateProfileProvider::class);
        $profileProvider->method('fetchCached')->willReturn([null, null]);

        $this->inject('analysisService', $this->analysisService);
        $this->inject('alertService', $this->alertService);
        $this->inject('corporateProfileProvider', $profileProvider);
        $this->inject('corporateProfileCache', $this->createMock(CorporateProfileCacheRepository::class));
    }

    private function inject(string $property, object $value): void
    {
        (new ReflectionProperty(Application::class, $property))->setValue($this->application, $value);
    }

    /**
     * @param list<string> $tickers
     * @return array{recommendations: array<string,string>, riskLevels: array<string,mixed>, sectors: array<string,string>}
     */
    private function analyze(array $tickers): array
    {
        $holdings = array_map(
            static fn (string $ticker): Holding => new Holding($ticker, 1.0, 100.0, 110.0),
            $tickers
        );

        /** @var array{recommendations: array<string,string>, riskLevels: array<string,mixed>, sectors: array<string,string>} $result */
        $result = (new ReflectionMethod(Application::class, 'analyzeHoldingsForAlerts'))->invoke(
            $this->application,
            new User(1, 'test@example.com', new DateTimeImmutable()),
            $holdings
        );

        return $result;
    }

    private function analysisOf(string $sector): StockAnalysis
    {
        $analysis = $this->createMock(StockAnalysis::class);

        $analysis->method('getScore')->willReturn(new Score());
        $analysis->method('getStock')->willReturn(SyntheticStock::withSector($sector));
        $analysis->method('getRiskLevels')->willReturn(null);

        return $analysis;
    }

    /**
     * El caso que motiva el `catch`: tres posiciones, la de en medio falla.
     * Las otras dos tienen que salir igual.
     */
    public function testUnTickerQueFallaNoSeLlevaPorDelanteElRestoDeLaCartera(): void
    {
        $this->analysisService->method('analyze')->willReturnCallback(
            function (string $ticker): StockAnalysis {
                if ($ticker === 'DELISTED') {
                    throw new RuntimeException('404 Not Found: may be delisted.');
                }

                return $this->analysisOf('Technology');
            }
        );

        $resultado = $this->analyze(['AAPL', 'DELISTED', 'MSFT']);

        self::assertArrayHasKey('AAPL', $resultado['recommendations']);
        self::assertArrayHasKey('MSFT', $resultado['recommendations']);
        self::assertArrayNotHasKey('DELISTED', $resultado['recommendations'], 'Sin dato, no una recomendacion inventada.');
    }

    /**
     * Que falle el analisis de un ticker tampoco puede dejar a medias sus
     * niveles de riesgo ni su sector: la pagina los pinta por ticker, y una
     * clave presente con valor basura seria peor que la clave ausente.
     */
    public function testDelTickerQueFallaNoQuedaNingunDatoAMedias(): void
    {
        $this->analysisService->method('analyze')->willThrowException(new RuntimeException('429 Too Many Requests'));

        $resultado = $this->analyze(['AAPL']);

        self::assertSame([], $resultado['recommendations']);
        self::assertSame([], $resultado['riskLevels']);
        self::assertSame([], $resultado['sectors']);
    }

    /**
     * Un fallo del proveedor no puede dejar el estado de alertas de ese
     * ticker actualizado a medias: si no sabemos su recomendacion de hoy,
     * no hay cambio que registrar. Registrarlo generaria una alerta falsa
     * en la siguiente visita.
     */
    public function testUnTickerQueFallaNoActualizaSuEstadoDeAlerta(): void
    {
        $this->analysisService->method('analyze')->willThrowException(new RuntimeException('Sin datos.'));
        $this->alertService->expects(self::never())->method('checkRecommendationChange');
        $this->alertService->expects(self::never())->method('checkStopLossBreach');

        $this->analyze(['AAPL']);
    }

    /**
     * El sector viene en el mismo `Stock` del analisis desde `v2.47`, sin
     * ninguna llamada nueva: alimenta el panel de concentracion de la
     * cartera. Si dejara de recogerse, la concentracion sectorial pasaria a
     * decir "Sin sector" para todo, que es un aviso engañoso (`v2.85`).
     */
    public function testElSectorSeRecogeDelMismoAnalisisSinLlamadasNuevas(): void
    {
        $this->analysisService->method('analyze')->willReturn($this->analysisOf('Financial Services'));
        $this->analysisService->expects(self::once())->method('analyze');

        $resultado = $this->analyze(['JPM']);

        self::assertSame(['JPM' => 'Financial Services'], $resultado['sectors']);
    }

    public function testUnaCarteraSinPosicionesNoLlamaAlProveedor(): void
    {
        $this->analysisService->expects(self::never())->method('analyze');

        $resultado = $this->analyze([]);

        self::assertSame([], $resultado['recommendations']);
    }
}
