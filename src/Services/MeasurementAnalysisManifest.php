<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;

/**
 * Manifiesto de ANALISIS de un estudio offline (C4 de
 * `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`): fija el conjunto de
 * operaciones consumidas (por su huella ORDENADA, no por como se hayan
 * iterado), el codigo estadistico (`code_revision`, ya que este proyecto no
 * versiona formulas por separado), la configuracion, la semilla y las
 * replicas del bootstrap -- distinto del manifiesto de GENERACION
 * (`MeasurementGenerationManifest`, que fija los DATOS de entrada). Cada
 * manifiesto de analisis referencia el `dataset_hash` del de generacion del
 * que parte (`generation_dataset_hash`), para poder comprobar que un
 * resultado publicado corresponde de verdad al paquete de datos que dice
 * usar.
 */
final class MeasurementAnalysisManifest
{
    /**
     * Huella ORDENADA de una lista de filas de analisis (p.ej. las
     * diferencias pareadas que entran en `PolicyReplayStatistics`, o las
     * filas de `PolicyReplayHorizonRows`): cada fila se convierte primero a
     * una cadena canonica con `$canonicalizer`, y las cadenas se ORDENAN
     * antes de hashear -- asi la huella no depende del orden en que
     * `$rows` llegaron (ver "Aceptacion" de C4: "hashes ordenados").
     *
     * @param list<mixed> $rows
     * @param callable(mixed): string $canonicalizer
     */
    public function entriesHash(array $rows, callable $canonicalizer): string
    {
        $lines = array_map($canonicalizer, $rows);
        sort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function build(
        string $generationDatasetHash,
        string $entriesHash,
        int $entriesCount,
        array $config,
        ?int $seed,
        ?int $bootstrapReplicates = null,
        ?string $codeRevision = null
    ): array {
        return [
            'kind' => 'analysis',
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'code_revision' => $codeRevision ?? trim((string) shell_exec('git rev-parse HEAD 2>&1')),
            'generation_dataset_hash' => $generationDatasetHash,
            'config' => $config,
            'seed' => $seed,
            'bootstrap_replicates' => $bootstrapReplicates,
            'entries_hash' => $entriesHash,
            'entries_count' => $entriesCount,
        ];
    }

    /**
     * Identificador corto y ESTABLE de un manifiesto de analisis, para
     * nombrar el fichero de resultados sin sobrescribir uno de otra version
     * ("Aceptacion" de C4: "un cambio de metodo conserva ambos analisis y su
     * procedencia"). Dos manifiestos con el MISMO codigo, datos, config y
     * semilla dan el mismo sufijo (una repeticion sobrescribe su propia
     * salida, que es identica); cualquier diferencia real cambia el
     * sufijo, asi que nunca pisa un analisis DISTINTO.
     *
     * @param array<string, mixed> $manifest
     */
    public function versionSuffix(array $manifest): string
    {
        return substr(hash('sha256', json_encode($this->stable($manifest), JSON_THROW_ON_ERROR)), 0, 12);
    }

    /**
     * Dos manifiestos son el MISMO analisis (misma version, mismos datos)
     * si coinciden en todo salvo `generated_at` (la hora de ejecucion nunca
     * puede ser parte de la identidad). Base de "repetir desde el mismo
     * paquete debe producir los mismos resultados": si `reproducible()` es
     * `true` pero los RESULTADOS numericos difieren, el analisis no es
     * determinista y eso es un defecto a corregir, no un cambio de version.
     *
     * @param array<string, mixed> $manifestA
     * @param array<string, mixed> $manifestB
     */
    public function reproducible(array $manifestA, array $manifestB): bool
    {
        return $this->stable($manifestA) === $this->stable($manifestB);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function stable(array $manifest): array
    {
        unset($manifest['generated_at']);
        ksort($manifest);

        return $manifest;
    }
}
