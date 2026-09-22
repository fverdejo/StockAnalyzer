<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\MeasurementAnalysisManifest;

/**
 * `MeasurementAnalysisManifest` (C4, `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`).
 */
final class MeasurementAnalysisManifestTest extends TestCase
{
    /** "hashes ordenados": la huella de las filas NO depende del orden en que se pasen. */
    public function testEntriesHashNoDependeDelOrdenDeLasFilas(): void
    {
        $manifest = new MeasurementAnalysisManifest();
        $canon = static fn (array $row): string => $row['ticker'] . '|' . $row['diff'];
        $ordered = [['ticker' => 'AAA', 'diff' => 1.0], ['ticker' => 'BBB', 'diff' => 2.0]];
        $reversed = array_reverse($ordered);

        self::assertSame($manifest->entriesHash($ordered, $canon), $manifest->entriesHash($reversed, $canon));
    }

    public function testEntriesHashCambiaSiCambiaCualquierFila(): void
    {
        $manifest = new MeasurementAnalysisManifest();
        $canon = static fn (array $row): string => $row['ticker'] . '|' . $row['diff'];
        $before = $manifest->entriesHash([['ticker' => 'AAA', 'diff' => 1.0]], $canon);
        $after = $manifest->entriesHash([['ticker' => 'AAA', 'diff' => 1.01]], $canon);

        self::assertNotSame($before, $after);
    }

    /** "Repetir desde el mismo paquete debe producir los mismos resultados." */
    public function testDosConstruccionesConLosMismosDatosSonReproducibles(): void
    {
        $manifest = new MeasurementAnalysisManifest();
        $a = $manifest->build('dataset-hash', 'entries-hash', 100, ['h' => 45], 20260920, 5000, 'rev1');
        // `generated_at` tiene precision de segundo (DATE_ATOM): se fuerza un
        // valor DISTINTO a mano para probar que reproducible() lo ignora,
        // sin depender de que el reloj cruce un segundo durante el test.
        $b = $a;
        $b['generated_at'] = (new \DateTimeImmutable($a['generated_at']))->modify('+1 second')->format(DATE_ATOM);

        self::assertNotSame($a['generated_at'], $b['generated_at']);
        self::assertTrue($manifest->reproducible($a, $b));
        self::assertSame($manifest->versionSuffix($a), $manifest->versionSuffix($b));
    }

    /** "Un cambio de metodo conserva ambos analisis y su procedencia." */
    public function testUnCambioDeConfiguracionODeDatosDaUnSufijoDeVersionDistinto(): void
    {
        $manifest = new MeasurementAnalysisManifest();
        $base = $manifest->build('dataset-hash', 'entries-hash', 100, ['h' => 45], 20260920, 5000, 'rev1');
        $otroCodigo = $manifest->build('dataset-hash', 'entries-hash', 100, ['h' => 45], 20260920, 5000, 'rev2');
        $otrosDatos = $manifest->build('otro-dataset-hash', 'entries-hash', 100, ['h' => 45], 20260920, 5000, 'rev1');
        $otraConfig = $manifest->build('dataset-hash', 'entries-hash', 100, ['h' => 90], 20260920, 5000, 'rev1');
        $otraSemilla = $manifest->build('dataset-hash', 'entries-hash', 100, ['h' => 45], 99999999, 5000, 'rev1');

        self::assertFalse($manifest->reproducible($base, $otroCodigo));
        self::assertFalse($manifest->reproducible($base, $otrosDatos));
        self::assertFalse($manifest->reproducible($base, $otraConfig));
        self::assertFalse($manifest->reproducible($base, $otraSemilla));

        $suffixes = array_map($manifest->versionSuffix(...), [$base, $otroCodigo, $otrosDatos, $otraConfig, $otraSemilla]);
        self::assertSame($suffixes, array_unique($suffixes), 'Cada variante debe tener un sufijo de version distinto (nunca pisa a otra).');
    }

    public function testBuildReferenciaElHashDelManifiestoDeGeneracion(): void
    {
        $manifest = (new MeasurementAnalysisManifest())->build('el-hash-de-generacion', 'entries-hash', 50, [], null, null, 'rev1');

        self::assertSame('analysis', $manifest['kind']);
        self::assertSame('el-hash-de-generacion', $manifest['generation_dataset_hash']);
        self::assertSame(50, $manifest['entries_count']);
        self::assertNull($manifest['seed']);
        self::assertNull($manifest['bootstrap_replicates']);
    }
}
