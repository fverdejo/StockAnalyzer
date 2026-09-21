<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use Generator;
use JsonException;

/**
 * Lectura en STREAMING de un export del archivo EODHD (`.jsonl.gz`, ver
 * `bin/export-eodhd-archive.php`): la primera linea es el manifiesto
 * (`{"__manifest__": {...}}`) y cada linea siguiente una observacion. No usa
 * base de datos, ni red, ni acumula el fichero en memoria (el export real
 * pesa >300MB comprimido).
 *
 * Ademas de dar las filas, comprueba la INTEGRIDAD DEL CONTENEDOR gzip, que
 * es lo que un lector de lineas no ve: el trailer de un `.gz` lleva el CRC32
 * y el tamaño del contenido sin comprimir, y `gzread()` NO lo verifica -- un
 * fichero cortado al final de una fila termina "bien" para el lector de
 * lineas. Aqui se calcula el CRC32 y el tamaño de todo lo leido y se
 * comparan con el trailer al terminar; una ultima linea sin salto de linea
 * (el escritor termina TODAS con `\n`) tambien se marca como truncamiento.
 * Encargo C1 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`.
 *
 * El informe de contenedor (`report()`) solo es fiable cuando el generador se
 * ha consumido HASTA EL FINAL.
 */
final class EodhdArchiveExportReader
{
    private const CHUNK_BYTES = 1_048_576;

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    private ?string $manifestError = null;

    /** @var array{read_error: ?string, trailer_error: ?string, unterminated_last_line: bool, decompressed_bytes: int, lines: int} */
    private array $report = ['read_error' => null, 'trailer_error' => null, 'unterminated_last_line' => false, 'decompressed_bytes' => 0, 'lines' => 0];

    /**
     * Genera cada FILA de datos (a partir de la linea 2) como
     * `['line' => n, 'row' => array|null, 'error' => string|null]`; una linea
     * que no decodifica como objeto JSON sale con `row = null` y su error.
     *
     * @return Generator<int, array{line: int, row: ?array<string, mixed>, error: ?string}>
     */
    public function rows(string $path): Generator
    {
        $this->manifest = null;
        $this->manifestError = null;
        $this->report = ['read_error' => null, 'trailer_error' => null, 'unterminated_last_line' => false, 'decompressed_bytes' => 0, 'lines' => 0];

        if (!is_file($path) || !is_readable($path)) {
            $this->report['read_error'] = "No existe o no se puede leer {$path}.";

            return;
        }

        $magic = @file_get_contents($path, false, null, 0, 2);

        if ($magic !== "\x1f\x8b") {
            $this->report['read_error'] = "{$path} no es un fichero gzip (cabecera incorrecta).";

            return;
        }

        $gz = @gzopen($path, 'rb');

        if ($gz === false) {
            $this->report['read_error'] = "No se pudo abrir {$path} como gzip.";

            return;
        }

        $crc = hash_init('crc32b');
        $size = 0;
        $buffer = '';
        $lineNumber = 0;

        while (true) {
            $chunk = gzread($gz, self::CHUNK_BYTES);

            if ($chunk === false) {
                $this->report['read_error'] = 'Fallo de lectura del flujo gzip.';

                break;
            }

            if ($chunk === '') {
                break;
            }

            hash_update($crc, $chunk);
            $size += strlen($chunk);
            $buffer .= $chunk;

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 1);
                ++$lineNumber;

                yield from $this->handleLine($lineNumber, $line);
            }
        }

        if ($buffer !== '') {
            $this->report['unterminated_last_line'] = true;
            ++$lineNumber;

            yield from $this->handleLine($lineNumber, $buffer);
        }

        gzclose($gz);
        $this->report['lines'] = $lineNumber;
        $this->report['decompressed_bytes'] = $size;
        $this->checkTrailer($path, hash_final($crc), $size);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(): ?array
    {
        return $this->manifest;
    }

    public function manifestError(): ?string
    {
        return $this->manifestError;
    }

    /**
     * @return array{read_error: ?string, trailer_error: ?string, unterminated_last_line: bool, decompressed_bytes: int, lines: int}
     */
    public function report(): array
    {
        return $this->report;
    }

    /**
     * @return Generator<int, array{line: int, row: ?array<string, mixed>, error: ?string}>
     */
    private function handleLine(int $lineNumber, string $line): Generator
    {
        if ($lineNumber === 1) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->manifestError = 'La primera linea no es JSON valido: ' . $exception->getMessage();

                return;
            }

            if (!is_array($decoded) || !isset($decoded['__manifest__']) || !is_array($decoded['__manifest__'])) {
                $this->manifestError = 'La primera linea no es el manifiesto esperado ({"__manifest__": {...}}).';

                return;
            }

            $this->manifest = $decoded['__manifest__'];

            return;
        }

        if (trim($line) === '') {
            yield ['line' => $lineNumber, 'row' => null, 'error' => 'linea vacia'];

            return;
        }

        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            yield ['line' => $lineNumber, 'row' => null, 'error' => 'JSON invalido: ' . $exception->getMessage()];

            return;
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            yield ['line' => $lineNumber, 'row' => null, 'error' => 'la fila no es un objeto JSON'];

            return;
        }

        yield ['line' => $lineNumber, 'row' => $decoded, 'error' => null];
    }

    private function checkTrailer(string $path, string $crcHex, int $size): void
    {
        $length = filesize($path);

        if ($length === false || $length < 18) {
            $this->report['trailer_error'] = 'El fichero es demasiado corto para ser un gzip completo.';

            return;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->report['trailer_error'] = 'No se pudo releer el trailer gzip.';

            return;
        }

        fseek($handle, -8, SEEK_END);
        $trailer = (string) fread($handle, 8);
        fclose($handle);

        $unpacked = unpack('Vcrc/Visize', $trailer);

        if ($unpacked === false) {
            $this->report['trailer_error'] = 'Trailer gzip ilegible.';

            return;
        }

        if (sprintf('%08x', $unpacked['crc']) !== $crcHex || ($size & 0xFFFFFFFF) !== $unpacked['isize']) {
            $this->report['trailer_error'] = 'El CRC32/tamaño del trailer gzip NO coincide con el contenido leido: el fichero esta cortado o alterado.';
        }
    }
}
