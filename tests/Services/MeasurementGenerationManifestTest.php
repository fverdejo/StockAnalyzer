<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\MeasurementGenerationManifest;

/**
 * `MeasurementGenerationManifest` (C4, `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`).
 * "Aceptacion" citado literalmente en los nombres de test.
 */
final class MeasurementGenerationManifestTest extends TestCase
{
    private string $universeFile;

    protected function setUp(): void
    {
        $this->universeFile = tempnam(sys_get_temp_dir(), 'universe');
        file_put_contents($this->universeFile, "AAA\nBBB\nCCC\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->universeFile);
    }

    public function testElHashDelConjuntoNoDependeDelOrdenDeEntrada(): void
    {
        $manifest = new MeasurementGenerationManifest();
        $a = ['AAA' => 'h1', 'BBB' => 'h2'];
        $b = ['BBB' => 'h2', 'AAA' => 'h1'];

        self::assertSame($manifest->datasetHash($a), $manifest->datasetHash($b));
    }

    public function testCambiarLaHuellaDeUnTickerCambiaElHashDelConjunto(): void
    {
        $manifest = new MeasurementGenerationManifest();
        $before = $manifest->datasetHash(['AAA' => 'h1', 'BBB' => 'h2']);
        $after = $manifest->datasetHash(['AAA' => 'h1-modificado', 'BBB' => 'h2']);

        self::assertNotSame($before, $after, 'Modificar los datos de un ticker debe cambiar el hash del conjunto entero.');
    }

    public function testQuitarOAnadirUnTickerCambiaElHashDelConjunto(): void
    {
        $manifest = new MeasurementGenerationManifest();
        $full = $manifest->datasetHash(['AAA' => 'h1', 'BBB' => 'h2']);
        $missing = $manifest->datasetHash(['AAA' => 'h1']);
        $extra = $manifest->datasetHash(['AAA' => 'h1', 'BBB' => 'h2', 'CCC' => 'h3']);

        self::assertNotSame($full, $missing);
        self::assertNotSame($full, $extra);
    }

    public function testBuildIncluyeElUniversoLasExclusionesYElHashDelConjunto(): void
    {
        $manifest = (new MeasurementGenerationManifest())->build(
            $this->universeFile,
            ['AAA' => 'ha', 'BBB' => 'hb'],
            ['CCC' => 'AUSENCIA_LEGITIMA: sin cache'],
            ['as_of' => '2026-09-18', 'step' => 5],
            'deadbeef'
        );

        self::assertSame('generation', $manifest['kind']);
        self::assertSame('deadbeef', $manifest['code_revision']);
        self::assertSame(3, $manifest['universe_count']);
        self::assertSame(hash('sha256', "AAA\nBBB\nCCC\n"), $manifest['universe_sha256']);
        self::assertSame(['as_of' => '2026-09-18', 'step' => 5], $manifest['config']);
        self::assertSame(['CCC' => 'AUSENCIA_LEGITIMA: sin cache'], $manifest['excluded']);
        self::assertSame(2, $manifest['tickers_with_result']);
        self::assertSame((new MeasurementGenerationManifest())->datasetHash(['AAA' => 'ha', 'BBB' => 'hb']), $manifest['dataset_hash']);
        self::assertSame(['AAA' => 'ha', 'BBB' => 'hb'], $manifest['fingerprints'], 'Huella por ticker, para poder diagnosticar cual cambio si dataset_hash no coincide.');
    }

    /** "quitar un ticker ... debe impedir publicar un estudio completo compatible" */
    public function testUnTickerFaltanteSinExclusionDocumentadaFalla(): void
    {
        $result = (new MeasurementGenerationManifest())->verifyComplete(['AAA', 'BBB', 'CCC'], ['AAA', 'BBB'], []);

        self::assertFalse($result['ok']);
        self::assertSame(['CCC'], $result['missing']);
    }

    /** Un ticker faltante SI documentado (ausencia legitima) no es un fallo. */
    public function testUnTickerFaltanteConExclusionDocumentadaNoFalla(): void
    {
        $result = (new MeasurementGenerationManifest())->verifyComplete(['AAA', 'BBB', 'CCC'], ['AAA', 'BBB'], ['CCC' => 'sin cache']);

        self::assertTrue($result['ok']);
        self::assertSame([], $result['missing']);
    }

    /** "introducir uno extra ... debe impedir publicar un estudio completo compatible" */
    public function testUnTickerExtraFueraDelUniversoFalla(): void
    {
        $result = (new MeasurementGenerationManifest())->verifyComplete(['AAA', 'BBB'], ['AAA', 'BBB', 'ZZZ'], []);

        self::assertFalse($result['ok']);
        self::assertSame(['ZZZ'], $result['unexpected']);
    }

    public function testUnTickerConResultadoYExclusionALaVezEsUnaContradiccion(): void
    {
        $result = (new MeasurementGenerationManifest())->verifyComplete(['AAA', 'BBB'], ['AAA', 'BBB'], ['BBB' => 'motivo']);

        self::assertFalse($result['ok']);
        self::assertSame(['BBB'], $result['present_but_excluded']);
    }

    public function testUnConjuntoCompletoYExactoPasa(): void
    {
        $result = (new MeasurementGenerationManifest())->verifyComplete(['AAA', 'BBB', 'CCC'], ['aaa', 'bbb'], ['ccc' => 'motivo']);

        self::assertTrue($result['ok']);
        self::assertSame([], $result['errors']);
    }

    public function testParseUniverseIgnoraLineasVaciasYNormalizaAMayusculas(): void
    {
        $tickers = (new MeasurementGenerationManifest())->parseUniverse("aaa\n\nbbb\n \n");

        self::assertSame(['AAA', 'BBB'], $tickers);
    }
}
