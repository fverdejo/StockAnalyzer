<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Escribe un export del archivo EODHD (`.jsonl.gz`) de forma que una
 * exportacion INTERRUMPIDA o fallida no pueda dejar un fichero final
 * parcial ni destruir la ultima copia valida (encargo C1 de
 * `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`):
 *
 * 1. las filas se escriben a un temporal de DATOS (sin manifiesto: sus
 *    totales solo se conocen al terminar);
 * 2. manifiesto + datos se copian a un temporal FINAL en el mismo
 *    directorio;
 * 3. el temporal final se VERIFICA con `EodhdArchiveExportVerifier` (la misma
 *    validacion autonoma que se usara despues);
 * 4. solo entonces se publica con `rename()` (atomico en el mismo sistema de
 *    ficheros, sustituye a la copia anterior);
 * 5. cualquier excepcion -- incluido un `gzwrite()` que devuelva menos bytes
 *    de los pedidos, o el generador de filas fallando a mitad -- borra los
 *    temporales y deja intacto el fichero publicado anterior.
 *
 * Cada fila lleva `observation_id`/`version_id` (identidad estable) y el
 * manifiesto declara el orden binario, el hash de la lista de tickers y el
 * rango de identificadores; el llamador puede añadir esquema y
 * equivalencias de simbolos via `$manifestExtras`.
 */
final class EodhdArchiveExportWriter
{
    private const COPY_CHUNK_BYTES = 1_048_576;

    public function __construct(
        private readonly EodhdArchiveExportVerifier $verifier = new EodhdArchiveExportVerifier()
    ) {
    }

    /**
     * @param iterable<array<string, mixed>> $rows ya ORDENADAS binariamente por (ticker, api_version, section, observed_at_utc, observation_id); cada una con `payload_compressed` binario
     * @param array<string, mixed> $manifestExtras se fusionan en el manifiesto (esquema, equivalencias de simbolos, revision del codigo...)
     * @return array<string, mixed> el manifiesto publicado
     */
    public function write(string $outPath, iterable $rows, array $manifestExtras = []): array
    {
        $directory = dirname($outPath);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException("El directorio de salida no existe o no es escribible: {$directory}");
        }

        $dataTmp = $outPath . '.data.tmp';
        $finalTmp = $outPath . '.tmp';

        try {
            $manifest = $this->writeData($dataTmp, $rows, $manifestExtras);
            $this->writeFinal($finalTmp, $dataTmp, $manifest);

            $verification = $this->verifier->verify($finalTmp);

            if (!$verification['ok']) {
                throw new RuntimeException('El export recien escrito NO supera su propia verificacion: ' . implode(' | ', array_slice($verification['errors'], 0, 5)));
            }

            if (!rename($finalTmp, $outPath)) {
                throw new RuntimeException("No se pudo publicar {$finalTmp} como {$outPath}.");
            }

            return $manifest;
        } finally {
            foreach ([$dataTmp, $finalTmp] as $temporary) {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @param array<string, mixed> $manifestExtras
     * @return array<string, mixed>
     */
    private function writeData(string $dataTmp, iterable $rows, array $manifestExtras): array
    {
        $gz = @gzopen($dataTmp, 'wb9');

        if ($gz === false) {
            throw new RuntimeException("No se pudo abrir {$dataTmp} para escritura.");
        }

        $rowCount = 0;
        $byGroup = [];
        $tickers = [];
        $minId = null;
        $maxId = null;

        try {
            foreach ($rows as $row) {
                $line = json_encode([
                    'observation_id' => $row['observation_id'],
                    'version_id' => $row['version_id'],
                    'ticker' => $row['ticker'],
                    'api_version' => $row['api_version'],
                    'section' => $row['section'],
                    'observed_at_utc' => $row['observed_at_utc'],
                    'source_symbol' => $row['source_symbol'],
                    'request_from' => $row['request_from'],
                    'request_to' => $row['request_to'],
                    'payload_hash' => $row['payload_hash'],
                    'payload_compressed_base64' => base64_encode((string) $row['payload_compressed']),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

                $this->writeAll($gz, $line . "\n", $dataTmp);
                ++$rowCount;
                $group = $row['api_version'] . '/' . $row['section'];
                $byGroup[$group] = ($byGroup[$group] ?? 0) + 1;
                $tickers[(string) $row['ticker']] = true;
                $id = (int) $row['observation_id'];
                $minId = $minId === null ? $id : min($minId, $id);
                $maxId = $maxId === null ? $id : max($maxId, $id);
            }
        } catch (Throwable $throwable) {
            gzclose($gz);

            throw $throwable;
        }

        if (!gzclose($gz)) {
            throw new RuntimeException("No se pudo cerrar {$dataTmp} (¿disco lleno?).");
        }

        $tickerList = array_keys($tickers);
        sort($tickerList, SORT_STRING);
        ksort($byGroup);

        return array_merge(
            [
                'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'code_revision' => 'unknown',
                'source' => 'eodhd_raw_fundamental_versions + eodhd_raw_fundamental_version_observations',
                'ordering' => EodhdArchiveExportVerifier::ORDERING_BINARY,
            ],
            $manifestExtras,
            [
                'row_count' => $rowCount,
                'by_api_version_section' => $byGroup,
                'distinct_tickers' => count($tickers),
                'tickers_sha256' => hash('sha256', implode("\n", $tickerList)),
                'observation_id_min' => $minId,
                'observation_id_max' => $maxId,
            ]
        );
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeFinal(string $finalTmp, string $dataTmp, array $manifest): void
    {
        $in = @gzopen($dataTmp, 'rb');
        $out = @gzopen($finalTmp, 'wb9');

        if ($in === false || $out === false) {
            throw new RuntimeException("No se pudo abrir {$dataTmp} o {$finalTmp}.");
        }

        try {
            $this->writeAll($out, json_encode(['__manifest__' => $manifest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", $finalTmp);

            while (true) {
                $chunk = gzread($in, self::COPY_CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException("Fallo de lectura de {$dataTmp} al copiar.");
                }

                if ($chunk === '') {
                    break;
                }

                $this->writeAll($out, $chunk, $finalTmp);
            }
        } finally {
            gzclose($in);
        }

        if (!gzclose($out)) {
            throw new RuntimeException("No se pudo cerrar {$finalTmp} (¿disco lleno?).");
        }
    }

    /**
     * @param resource $gz
     */
    private function writeAll($gz, string $data, string $path): void
    {
        $written = gzwrite($gz, $data);

        if ($written === false || $written !== strlen($data)) {
            throw new RuntimeException("Escritura incompleta en {$path}.");
        }
    }
}
