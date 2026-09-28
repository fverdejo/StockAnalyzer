<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Enums\StudyVerdictStatus;
use StockAnalyzer\Services\FormalTestRegistry;

/**
 * `FormalTestRegistry` (C10.4, predeclaracion cerrada de
 * `auditor-estadistico`, 2026-09-28 -- ver `versions.md`).
 */
final class FormalTestRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'formal_test_registry') . '.jsonl';
        @unlink($this->path); // tempnam() ya crea el fichero vacio; el registro debe crearlo el solo en el primer append.
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testLaPrimeraVezQueSeTocaUnaFamiliaDaSecuencia1(): void
    {
        $registry = new FormalTestRegistry($this->path);

        $sequence = $registry->record('familia_x', 'estudio', 'primaria', 'sp500', 45, 'ref-1', StudyVerdictStatus::NO_INFORMATIVO, 'legado_2xp90');

        self::assertSame(1, $sequence);
    }

    public function testCadaRegistroDeLaMismaFamiliaAutoincrementaLaSecuencia(): void
    {
        $registry = new FormalTestRegistry($this->path);

        $registry->record('familia_x', 'estudio 1', 'primaria', 'sp500', 45, 'ref-1', StudyVerdictStatus::NO_INFORMATIVO, 'legado_2xp90');
        $registry->record('familia_x', 'estudio 2', 'primaria', 'sp500', 90, 'ref-2', StudyVerdictStatus::NO_INFORMATIVO, 'legado_2xp90');
        $third = $registry->record('familia_x', 'estudio 3', 'primaria', 'sp500', 120, 'ref-3', StudyVerdictStatus::METODO_NO_CALIBRADO, 'legado_2xp90');

        self::assertSame(3, $third);
    }

    public function testFamiliasDistintasNoComparteElContador(): void
    {
        $registry = new FormalTestRegistry($this->path);

        $registry->record('familia_x', 'estudio', 'primaria', 'sp500', 45, 'ref-1', StudyVerdictStatus::NO_INFORMATIVO, 'legado_2xp90');
        $sequence = $registry->record('familia_y', 'otro estudio', 'primaria', 'sp500', 45, 'ref-2', StudyVerdictStatus::NO_INFORMATIVO, 'legado_2xp90');

        self::assertSame(1, $sequence, 'familia_y nunca se ha tocado antes: debe empezar en 1, sin importar cuanto lleve familia_x.');
    }

    public function testCadaLineaEsJsonValidoConLosCamposDeclarados(): void
    {
        $registry = new FormalTestRegistry($this->path);
        $registry->record('familia_x', 'estudio', 'p_cond', 'sp500', 45, 'ref-1', StudyVerdictStatus::VALIDADO_PROVISIONAL, 'pw_mercado');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->path))));
        self::assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        self::assertSame('familia_x', $decoded['family_id']);
        self::assertSame(1, $decoded['family_sequence_number']);
        self::assertSame('p_cond', $decoded['metric_name']);
        self::assertSame(45, $decoded['horizon_days']);
        self::assertSame('validado_provisional', $decoded['verdict_status']);
        self::assertSame('pw_mercado', $decoded['bootstrap_method_id']);
        self::assertArrayHasKey('recorded_at', $decoded);
    }

    public function testElRegistroEsAppendOnlyNuncaReescribeLoYaEscrito(): void
    {
        $registry = new FormalTestRegistry($this->path);
        $registry->record('familia_x', 'estudio 1', 'primaria', 'sp500', 45, 'ref-1', StudyVerdictStatus::NO_INFORMATIVO, 'legado_2xp90');
        $firstLine = trim((string) file_get_contents($this->path));

        $registry->record('familia_x', 'estudio 2', 'primaria', 'sp500', 90, 'ref-2', StudyVerdictStatus::NO_INFORMATIVO, 'legado_2xp90');
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->path))));

        self::assertCount(2, $lines);
        self::assertSame($firstLine, $lines[0], 'La primera linea ya escrita no debe cambiar cuando se añade una segunda.');
    }

    public function testCountExistingSinFicheroDaCero(): void
    {
        $registry = new FormalTestRegistry($this->path);

        self::assertSame(0, $registry->countExisting('familia_x'));
    }
}
