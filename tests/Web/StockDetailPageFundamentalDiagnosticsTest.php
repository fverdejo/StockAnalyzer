<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\DTO\CategoryResult;
use StockAnalyzer\DTO\Explanation;
use StockAnalyzer\DTO\FundamentalChangeAssessment;
use StockAnalyzer\DTO\FundamentalChangeFactor;
use StockAnalyzer\DTO\PriceChartSeries;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\DTO\TechnicalSnapshot;
use StockAnalyzer\Enums\FundamentalChangeVerdict;
use StockAnalyzer\Enums\ScoreCategory;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Score;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Web\StockDetailPage;

/**
 * Integracion de D1 ("Salud fundamental") + D2 ("Cambio interanual") en
 * `StockDetailPage::render()` (ver versions.md, correccion de Codex del
 * 2026-09-06 sobre `7f91aef`). `FundamentalHealthAssessorTest` y
 * `FundamentalChangeAssessorTest` ya cubren los servicios que CALCULAN
 * D1/D2 por separado; estos tests ejercitan la PAGINA completa para
 * confirmar que la vista realmente muestra (o esconde) lo que esos
 * servicios calculan -- que es exactamente donde vivian los dos bugs
 * reales encontrados por Codex (la vista cortaba antes de leer la alerta
 * de FCF; la vista usaba el sector base en vez del enriquecido).
 */
final class StockDetailPageFundamentalDiagnosticsTest extends TestCase
{
    /**
     * Bug 1 (Codex, 2026-09-06): FCF negativo como UNICO dato conocido
     * marcaba `datosInsuficientes=true` a la vez que `fcfNegativo=true`, y
     * la vista cortaba en `datosInsuficientes` sin llegar a mostrar la
     * alerta real.
     */
    public function testFcfNegativoComoUnicoDatoConocidoMuestraLaAlerta(): void
    {
        $html = $this->render($this->company(), $this->fundamentals(freeCashFlow: -5_000_000.0));

        self::assertStringContainsString('Flujo de caja libre negativo', $html);
        self::assertStringNotContainsString('Datos insuficientes para evaluar la salud fundamental', $html);
    }

    /**
     * Bug de precision (Codex, 2026-09-06): D1/D2 deben usar el sector
     * ENRIQUECIDO (`$companyProfile`, con el sector real via Yahoo), no el
     * de `$company` a secas -- que puede llegar vacio (p.ej. FmpParser
     * nunca lo rellena) y dejar sin excluir a una entidad financiera o
     * inmobiliaria por simple ausencia de dato.
     */
    public function testSectorEnriquecidoExcluidoSeComportaComoSectorBaseExcluido(): void
    {
        $baseCompanyConSectorVacio = new Company('ACME', 'Acme Corp', '', '', 'NASDAQ', 'USD');
        $companyProfileEnriquecido = new Company('ACME', 'Acme Corp', 'Financial Services', 'Banks', 'NASDAQ', 'USD');

        $html = $this->render($baseCompanyConSectorVacio, Fundamentals::empty(), companyProfile: $companyProfileEnriquecido);

        self::assertStringContainsString('Sector financiero/inmobiliario', $html);
    }

    /**
     * Bug de precision (Codex, 2026-09-06): si D2 fallo o no se pudo
     * calcular, la ficha debe decirlo explicitamente, no omitir la seccion
     * en silencio (el usuario no debe leer el silencio como "sin cambios").
     */
    public function testAusenciaTotalDeHistoricoMuestraNoDisponible(): void
    {
        $html = $this->render($this->company(), $this->fundamentals(roic: 10.0), fundamentalChange: null);

        self::assertStringContainsString('Cambio interanual no disponible', $html);
    }

    public function testVeredictoMejorandoSeRenderizaSinErrores(): void
    {
        $html = $this->renderWithChange($this->verdictAssessment(FundamentalChangeVerdict::MEJORANDO));

        self::assertStringContainsString('Mejorando', $html);
    }

    public function testVeredictoDeteriorandoSeRenderizaSinErrores(): void
    {
        $html = $this->renderWithChange($this->verdictAssessment(FundamentalChangeVerdict::DETERIORANDO));

        self::assertStringContainsString('Deteriorando', $html);
    }

    public function testVeredictoEstableSeRenderizaSinErrores(): void
    {
        $html = $this->renderWithChange($this->verdictAssessment(FundamentalChangeVerdict::ESTABLE));

        self::assertStringContainsString('Estable', $html);
    }

    /**
     * Bug de precision (Codex, 2026-09-06): un empate real entre factores
     * que mejoran y factores que empeoran es "Mixto", no "Estable".
     */
    public function testVeredictoMixtoSeRenderizaSinErrores(): void
    {
        $html = $this->renderWithChange($this->verdictAssessment(FundamentalChangeVerdict::MIXTO));

        self::assertStringContainsString('Mixto', $html);
    }

    public function testVeredictoNoEvaluableSeRenderizaSinErrores(): void
    {
        $html = $this->renderWithChange(FundamentalChangeAssessment::noEvaluableResult());

        self::assertStringContainsString('No evaluable', $html);
    }

    /**
     * El veredicto D2 muestra siempre el aviso de comparabilidad de
     * proveedor (Bug 2, Codex 2026-09-06): el actual viene del proveedor de
     * mercado activo, el snapshot anterior siempre de `fundamentals_history`
     * (EODHD).
     */
    public function testVeredictoRealMuestraElAvisoDeComparabilidadDeProveedor(): void
    {
        $html = $this->renderWithChange($this->verdictAssessment(FundamentalChangeVerdict::MEJORANDO));

        self::assertStringContainsString('proveedor de mercado activo', $html);
        self::assertStringContainsString('EODHD', $html);
    }

    /**
     * Bug de precision (Codex, 2026-09-06): la ficha debe mostrar la fecha
     * REAL del snapshot anterior, nunca fingir literalmente "hace un año".
     */
    public function testMuestraLaFechaRealDelSnapshotAnterior(): void
    {
        $html = $this->renderWithChange($this->verdictAssessment(FundamentalChangeVerdict::MEJORANDO));

        self::assertStringContainsString('2025-08-15', $html);
        self::assertStringNotContainsString('hace un año', $html);
    }

    /**
     * Todo dato dinamico debe pasar por `Layout::escape()` (regla del
     * proyecto, ver project.md): aunque hoy ningun `FundamentalChangeAssessor`
     * real produce una etiqueta de factor con HTML, `renderFundamentalChangeSection()`
     * es generico y debe escapar cualquier valor que reciba.
     */
    public function testEtiquetaDeFactorConHtmlSeEscapaEnLaVista(): void
    {
        $factor = new FundamentalChangeFactor('<script>alert(1)</script>', 12.0, 10.0, higherIsBetter: true, isPercentage: true);
        $change = new FundamentalChangeAssessment(
            false,
            FundamentalChangeVerdict::MEJORANDO,
            [$factor, $this->roicFactor()],
            new DateTimeImmutable('2025-08-15')
        );

        $html = $this->renderWithChange($change);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    private function renderWithChange(FundamentalChangeAssessment $change): string
    {
        return $this->render($this->company(), $this->fundamentals(roic: 10.0, operatingMargin: 15.0), fundamentalChange: $change);
    }

    private function verdictAssessment(FundamentalChangeVerdict $verdict): FundamentalChangeAssessment
    {
        return new FundamentalChangeAssessment(
            false,
            $verdict,
            [$this->roicFactor()],
            new DateTimeImmutable('2025-08-15')
        );
    }

    private function roicFactor(): FundamentalChangeFactor
    {
        return new FundamentalChangeFactor('ROIC', 12.0, 10.0, higherIsBetter: true, isPercentage: true);
    }

    private function company(string $sector = 'Technology'): Company
    {
        return new Company('ACME', 'Acme Corp', $sector, 'Software', 'NASDAQ', 'USD');
    }

    private function fundamentals(
        ?float $roic = null,
        ?float $operatingMargin = null,
        ?float $debtToEquity = null,
        ?float $cashConversion = null,
        ?float $freeCashFlow = null
    ): Fundamentals {
        return new Fundamentals(
            per: null,
            peg: null,
            roe: null,
            roic: $roic,
            eps: null,
            marketCap: 1_000_000_000.0,
            debtToEquity: $debtToEquity,
            freeCashFlow: $freeCashFlow,
            evToEbitda: null,
            priceToBook: null,
            dividendYield: null,
            payoutRatio: null,
            grossMargin: null,
            operatingMargin: $operatingMargin,
            netMargin: null,
            revenueGrowth: null,
            currentRatio: null,
            dividendGrowth5y: null,
            earningsYield: null,
            cashConversion: $cashConversion
        );
    }

    private function render(
        Company $company,
        Fundamentals $fundamentals,
        ?Company $companyProfile = null,
        ?FundamentalChangeAssessment $fundamentalChange = null
    ): string {
        $analysis = $this->analysis($company, $fundamentals);
        $explanation = new Explanation('Resumen de prueba.', [], [], []);

        return StockDetailPage::render(
            analysis: $analysis,
            explanation: $explanation,
            backHref: '?page=dashboard',
            csrfToken: 'token',
            companyProfile: $companyProfile,
            fundamentalChange: $fundamentalChange
        );
    }

    private function analysis(Company $company, Fundamentals $fundamentals): StockAnalysis
    {
        $stock = new Stock(
            $company,
            new Quote(100.0, 99.0, 101.0, 98.0, 100.0, 1_000_000, new DateTimeImmutable('2026-08-01')),
            $fundamentals
        );

        $snapshot = new TechnicalSnapshot(
            null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, 0
        );

        return new StockAnalysis(
            $stock,
            $this->scoreWithPercentage(50.0),
            $snapshot,
            [new CategoryResult(ScoreCategory::TECHNICAL, 0, [])],
            new PriceChartSeries([], [], [], [], [], [], [], [], [])
        );
    }

    /**
     * Mismo criterio que StockDetailPageTest::scoreWithPercentage(): toda
     * la ponderacion en TECHNICAL para no depender de los pesos reales de
     * config/weights.php.
     */
    private function scoreWithPercentage(float $percentage): Score
    {
        $weights = new ScoreWeights([
            'technical' => 100.0,
            'fundamental' => 0.0,
            'valuation' => 0.0,
            'news' => 0.0,
            'momentum' => 0.0,
            'risk' => 0.0,
            'quality' => 0.0,
            'dividend' => 0.0,
        ]);

        return (new Score($weights))->add(ScoreCategory::TECHNICAL, $percentage);
    }
}
