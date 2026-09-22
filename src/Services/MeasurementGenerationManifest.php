<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;

/**
 * Manifiesto de GENERACION de un estudio offline (C4 de
 * `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`): fija el universo, las
 * exclusiones y una referencia VERIFICABLE a los datos (huella combinada por
 * ticker de `MeasurementDataFingerprint`), distinto del manifiesto de
 * ANALISIS (`MeasurementAnalysisManifest`, que fija el metodo estadistico
 * sobre esos datos). Los manifiestos previos de este proyecto (los `manifest.json`
 * de cada lote) ya fijaban configuracion y `code_revision`, pero no
 * identificaban de forma inmutable los datos en si -- este es el que lo
 * hace.
 *
 * **Politica declarada, C4**: esto se aplica a partir de ahora, hacia
 * DELANTE. No se inventan retroactivamente huellas para los datos que
 * consumieron las mediciones ya generadas y documentadas (fijo/episodios de
 * 636 tickers del `2026-09-18`, trailing del `2026-09-20`): esos datos ya no
 * se pueden re-observar tal como estaban ese dia, y fabricar una huella
 * ahora seria una garantia falsa, no una real.
 */
final class MeasurementGenerationManifest
{
    /**
     * Huella de TODO el conjunto: sha256 de `TICKER:huella` ordenado por
     * ticker. Cambia si se quita, se añade o se modifica el resultado de
     * CUALQUIER ticker -- es lo que hace inmutable al conjunto entero, no
     * solo a cada ticker por separado.
     *
     * @param array<string, string> $fingerprintsByTicker
     */
    public function datasetHash(array $fingerprintsByTicker): string
    {
        $normalized = $this->normalized($fingerprintsByTicker);
        $lines = [];

        foreach ($normalized as $ticker => $fingerprint) {
            $lines[] = $ticker . ':' . $fingerprint;
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * @param array<string, string> $fingerprintsByTicker ticker => huella combinada (`MeasurementDataFingerprint::combine()`)
     * @param array<string, string> $excludedTickers ticker => motivo (ausencia legitima documentada, nunca en silencio)
     * @param array<string, mixed> $config lo que ya guardaban los manifiestos de lote (as_of, step, index_code, coste, historyRange...)
     * @return array<string, mixed>
     */
    public function build(string $universeFilePath, array $fingerprintsByTicker, array $excludedTickers, array $config, ?string $codeRevision = null): array
    {
        $universeContent = (string) file_get_contents($universeFilePath);
        $universeTickers = $this->parseUniverse($universeContent);

        return [
            'kind' => 'generation',
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'code_revision' => $codeRevision ?? trim((string) shell_exec('git rev-parse HEAD 2>&1')),
            'universe_file' => basename($universeFilePath),
            'universe_sha256' => hash('sha256', $universeContent),
            'universe_count' => count($universeTickers),
            'config' => $config,
            'excluded' => $excludedTickers,
            'tickers_with_result' => count($fingerprintsByTicker),
            'dataset_hash' => $this->datasetHash($fingerprintsByTicker),
            // Huella POR TICKER (no solo la del conjunto): sin esto, una
            // verificacion que detecta que `dataset_hash` ya no coincide no
            // puede decir QUE ticker cambio, solo que "algo" cambio.
            'fingerprints' => $this->normalized($fingerprintsByTicker),
        ];
    }

    /**
     * @param array<string, string> $fingerprintsByTicker
     * @return array<string, string>
     */
    private function normalized(array $fingerprintsByTicker): array
    {
        $normalized = [];

        foreach ($fingerprintsByTicker as $ticker => $fingerprint) {
            $normalized[strtoupper($ticker)] = $fingerprint;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @return list<string>
     */
    public function parseUniverse(string $universeFileContent): array
    {
        $tickers = array_values(array_filter(array_map('trim', explode("\n", $universeFileContent))));

        return array_map('strtoupper', $tickers);
    }

    /**
     * Comprueba que el conjunto de tickers con resultado es EXACTAMENTE
     * `universo - exclusiones documentadas`, ni menos (falta alguno) ni mas
     * (aparece uno que no estaba en el universo congelado). Ver el
     * "Aceptacion" de C4: "quitar un ticker, introducir uno extra... debe
     * impedir publicar un estudio completo compatible".
     *
     * @param list<string> $universeTickers
     * @param list<string> $actualTickers los que de verdad tienen resultado
     * @param array<string, string> $documentedExclusions ticker => motivo
     * @return array{ok: bool, missing: list<string>, unexpected: list<string>, present_but_excluded: list<string>, errors: list<string>}
     */
    public function verifyComplete(array $universeTickers, array $actualTickers, array $documentedExclusions): array
    {
        $universe = array_unique(array_map('strtoupper', $universeTickers));
        $actual = array_unique(array_map('strtoupper', $actualTickers));
        $excluded = array_unique(array_map('strtoupper', array_keys($documentedExclusions)));

        $expectedPresent = array_values(array_diff($universe, $excluded));
        $missing = array_values(array_diff($expectedPresent, $actual));
        $unexpected = array_values(array_diff($actual, $universe));
        $presentButExcluded = array_values(array_intersect($actual, $excluded));

        $errors = [];

        if ($missing !== []) {
            $errors[] = sprintf('Faltan %d tickers del universo sin exclusion documentada: %s', count($missing), implode(',', $missing));
        }

        if ($unexpected !== []) {
            $errors[] = sprintf('%d tickers con resultado NO pertenecen al universo congelado: %s', count($unexpected), implode(',', $unexpected));
        }

        if ($presentButExcluded !== []) {
            $errors[] = sprintf('%d tickers tienen resultado Y una exclusion documentada a la vez (contradiccion): %s', count($presentButExcluded), implode(',', $presentButExcluded));
        }

        return [
            'ok' => $errors === [],
            'missing' => $missing,
            'unexpected' => $unexpected,
            'present_but_excluded' => $presentButExcluded,
            'errors' => $errors,
        ];
    }
}
