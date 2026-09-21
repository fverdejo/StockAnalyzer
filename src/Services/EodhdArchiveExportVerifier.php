<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;

/**
 * Validacion AUTONOMA de un export del archivo EODHD (`bin/export-eodhd-archive.php`):
 * lee SOLO el fichero (streaming, sin base de datos ni red) y decide si esta
 * completo y es integro. Una comparacion con la base de datos, si se pide, es
 * un paso APARTE que nunca puede convertir un fichero corrupto en exito
 * (encargo C1 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`,
 * que reprodujo dos falsos exitos del verificador anterior: un manifiesto de
 * 2 filas con 1 sola presente salia "1/1 verificadas", y una fila con hash
 * incorrecto salia con codigo 0 si la BD de comparacion no tenia muestra).
 *
 * Comprueba: contenedor gzip (CRC32/tamaño del trailer, ultima linea con
 * salto de linea), manifiesto valido con sus campos obligatorios, cada fila
 * (campos obligatorios, base64 estricto, gzip valido, sha256 del contenido
 * igual a `payload_hash`), que los RECUENTOS del manifiesto coinciden con lo
 * leido (filas totales, tickers distintos, filas por `api_version/section`,
 * y el hash de la lista de tickers cuando el manifiesto lo lleva), y, cuando
 * el manifiesto declara un orden binario, que las filas vienen ordenadas y
 * con `observation_id` unico.
 *
 * Un export en el formato ANTERIOR (sin `tickers_sha256`/`ordering`/
 * `observation_id`) sigue validandose con todas las comprobaciones que si
 * aplican y deja un AVISO por cada garantia que ese formato no puede dar.
 */
final class EodhdArchiveExportVerifier
{
    private const MAX_ERRORS_LISTED = 50;

    /** Orden que el escritor DECLARA en el manifiesto y que este verificador comprueba (comparacion binaria, no la collation de la BD). */
    public const ORDERING_BINARY = 'binary(ticker,api_version,section,observed_at_utc,observation_id)';

    public function __construct(
        private readonly EodhdArchiveExportReader $reader = new EodhdArchiveExportReader()
    ) {
    }

    /**
     * @return array{ok: bool, errors: list<string>, warnings: list<string>, manifest: ?array<string, mixed>, rows_read: int, rows_valid: int, distinct_tickers: int, by_api_version_section: array<string, int>, tickers_sha256: string, exclusively_v11_tickers: list<string>, container: array<string, mixed>}
     */
    public function verify(string $path): array
    {
        $errors = [];
        $errorCount = 0;
        $warnings = [];
        $addError = static function (string $message) use (&$errors, &$errorCount): void {
            ++$errorCount;

            if (count($errors) < self::MAX_ERRORS_LISTED) {
                $errors[] = $message;
            }
        };

        $rowsRead = 0;
        $rowsValid = 0;
        $tickers = [];
        $groupsByTicker = [];
        $byGroup = [];
        $ids = [];
        $previousKey = null;
        $manifestChecked = false;
        $manifest = null;

        foreach ($this->reader->rows($path) as $item) {
            if (!$manifestChecked) {
                // La primera fila de datos llega DESPUES de haber leido la linea 1.
                $manifest = $this->reader->manifest();
                $manifestChecked = true;
            }

            ++$rowsRead;
            $label = 'linea ' . $item['line'];

            if ($item['row'] === null) {
                $addError("{$label}: {$item['error']}");

                continue;
            }

            $row = $item['row'];
            $problem = $this->rowProblem($row);

            if ($problem !== null) {
                $addError("{$label}: {$problem}");

                continue;
            }

            ++$rowsValid;
            $ticker = (string) $row['ticker'];
            $tickers[$ticker] = true;
            $group = $row['api_version'] . '/' . $row['section'];
            $groupsByTicker[$ticker][$group] = true;
            $byGroup[$group] = ($byGroup[$group] ?? 0) + 1;

            if (isset($row['observation_id'])) {
                $id = (int) $row['observation_id'];

                if (isset($ids[$id])) {
                    $addError("{$label}: observation_id {$id} repetido.");
                }

                $ids[$id] = true;
            }

            $orderingBinary = ($manifest['ordering'] ?? null) === self::ORDERING_BINARY;

            if ($orderingBinary) {
                $key = [(string) $row['ticker'], (string) $row['api_version'], (string) $row['section'], (string) $row['observed_at_utc'], (int) ($row['observation_id'] ?? 0)];

                if ($previousKey !== null && $this->compareKeys($previousKey, $key) > 0) {
                    $addError("{$label}: filas fuera del orden declarado en el manifiesto.");
                }

                $previousKey = $key;
            }
        }

        $container = $this->reader->report();
        $manifest ??= $this->reader->manifest();

        if ($container['read_error'] !== null) {
            $addError($container['read_error']);
        }

        if ($container['trailer_error'] !== null) {
            $addError($container['trailer_error']);
        }

        if ($container['unterminated_last_line']) {
            $addError('La ultima linea no termina en salto de linea: el fichero esta truncado.');
        }

        if ($this->reader->manifestError() !== null) {
            $addError($this->reader->manifestError());
        }

        $tickerList = array_keys($tickers);
        sort($tickerList, SORT_STRING);
        $tickersSha = hash('sha256', implode("\n", $tickerList));

        if ($manifest !== null) {
            $this->compareManifest($manifest, $rowsRead, $rowsValid, count($tickers), $byGroup, $tickersSha, $ids !== [], $addError, $warnings);
        } elseif ($this->reader->manifestError() === null && $container['read_error'] === null) {
            $addError('El fichero no tiene manifiesto (esta vacio).');
        }

        if ($errorCount > count($errors)) {
            $errors[] = '... y ' . ($errorCount - count($errors)) . ' errores mas.';
        }

        // "Exclusivamente v1.1" (Astra: "159 simbolos exclusivamente v1.1"): tienen
        // fundamentales v1.1 pero NO la captura legacy/full de la API antigua
        // (los demas grupos, calendario/insiders/listas, no cuentan).
        $exclusivelyV11 = [];

        foreach ($groupsByTicker as $ticker => $groups) {
            if (isset($groups['v1.1/full']) && !isset($groups['legacy/full'])) {
                $exclusivelyV11[] = (string) $ticker;
            }
        }

        sort($exclusivelyV11, SORT_STRING);
        ksort($byGroup);

        return [
            'ok' => $errorCount === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'manifest' => $manifest,
            'rows_read' => $rowsRead,
            'rows_valid' => $rowsValid,
            'distinct_tickers' => count($tickers),
            'by_api_version_section' => $byGroup,
            'tickers_sha256' => $tickersSha,
            'exclusively_v11_tickers' => $exclusivelyV11,
            'container' => $container,
        ];
    }

    /**
     * Ultima observacion (por `observed_at_utc`, y a igualdad por
     * `observation_id` o, en el formato anterior, por posicion en el fichero,
     * el mismo criterio que `EodhdRawFundamentalVersionsRepository::latestFor()`)
     * de un `(ticker, api_version, section)`, SOLO desde el fichero.
     *
     * @return array{payload: string, payload_hash: string, observed_at_utc: string}|null
     */
    public function latestPayload(string $path, string $ticker, string $apiVersion, string $section): ?array
    {
        $best = null;
        $bestOrder = null;
        $position = 0;

        foreach ($this->reader->rows($path) as $item) {
            ++$position;
            $row = $item['row'];

            if ($row === null || $this->rowProblem($row) !== null) {
                continue;
            }

            if ($row['ticker'] !== strtoupper($ticker) || $row['api_version'] !== $apiVersion || $row['section'] !== $section) {
                continue;
            }

            $order = [(string) $row['observed_at_utc'], (int) ($row['observation_id'] ?? $position)];

            if ($bestOrder === null || $order >= $bestOrder) {
                $bestOrder = $order;
                $best = $row;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'payload' => (string) gzdecode((string) base64_decode((string) $best['payload_compressed_base64'], true)),
            'payload_hash' => (string) $best['payload_hash'],
            'observed_at_utc' => (string) $best['observed_at_utc'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowProblem(array $row): ?string
    {
        foreach (['ticker', 'api_version', 'section', 'observed_at_utc', 'payload_hash', 'payload_compressed_base64'] as $required) {
            if (!isset($row[$required]) || !is_string($row[$required]) || $row[$required] === '') {
                return "falta el campo obligatorio '{$required}' (o no es texto).";
            }
        }

        foreach (['source_symbol', 'request_from', 'request_to'] as $nullable) {
            if (!array_key_exists($nullable, $row) || ($row[$nullable] !== null && !is_string($row[$nullable]))) {
                return "falta el campo '{$nullable}' (puede ser null) o no es texto.";
            }
        }

        if (!$this->validDateTime((string) $row['observed_at_utc'], 'Y-m-d H:i:s')) {
            return "observed_at_utc '{$row['observed_at_utc']}' no es una fecha-hora valida.";
        }

        foreach (['request_from', 'request_to'] as $date) {
            if ($row[$date] !== null && !$this->validDateTime((string) $row[$date], 'Y-m-d')) {
                return "{$date} '{$row[$date]}' no es una fecha valida.";
            }
        }

        foreach (['observation_id', 'version_id'] as $optionalId) {
            if (array_key_exists($optionalId, $row) && (!is_int($row[$optionalId]) || $row[$optionalId] < 1)) {
                return "{$optionalId} no es un entero positivo.";
            }
        }

        if (preg_match('/^[0-9a-f]{64}$/', (string) $row['payload_hash']) !== 1) {
            return 'payload_hash no es un sha256 hexadecimal.';
        }

        $compressed = base64_decode((string) $row['payload_compressed_base64'], true);

        if ($compressed === false) {
            return 'payload_compressed_base64 no es base64 valido.';
        }

        $decompressed = @gzdecode($compressed);

        if ($decompressed === false) {
            return 'el payload comprimido no es un gzip valido.';
        }

        if (hash('sha256', $decompressed) !== $row['payload_hash']) {
            return 'el sha256 del contenido NO coincide con payload_hash (fila corrupta).';
        }

        return null;
    }

    private function validDateTime(string $value, string $format): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $parsed !== false && $parsed->format($format) === $value;
    }

    /**
     * @param list<string|int> $a
     * @param list<string|int> $b
     */
    private function compareKeys(array $a, array $b): int
    {
        foreach ($a as $i => $left) {
            $right = $b[$i];
            $comparison = is_int($left) ? $left <=> $right : strcmp((string) $left, (string) $right);

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, int> $byGroup
     * @param callable(string): void $addError
     * @param list<string> $warnings
     */
    private function compareManifest(array $manifest, int $rowsRead, int $rowsValid, int $distinctTickers, array $byGroup, string $tickersSha, bool $hasIds, callable $addError, array &$warnings): void
    {
        foreach (['row_count', 'distinct_tickers', 'by_api_version_section', 'generated_at', 'code_revision'] as $required) {
            if (!array_key_exists($required, $manifest)) {
                $addError("El manifiesto no tiene el campo obligatorio '{$required}'.");
            }
        }

        if (isset($manifest['row_count']) && (!is_int($manifest['row_count']) || $manifest['row_count'] !== $rowsRead)) {
            $addError(sprintf('El manifiesto declara %s filas y el fichero tiene %d.', json_encode($manifest['row_count']), $rowsRead));
        }

        if (isset($manifest['distinct_tickers']) && $manifest['distinct_tickers'] !== $distinctTickers) {
            $addError(sprintf('El manifiesto declara %s tickers distintos y el fichero tiene %d.', json_encode($manifest['distinct_tickers']), $distinctTickers));
        }

        if (isset($manifest['by_api_version_section'])) {
            $declared = is_array($manifest['by_api_version_section']) ? $manifest['by_api_version_section'] : [];
            $expected = $byGroup;
            ksort($declared);
            ksort($expected);

            if ($declared !== $expected) {
                $addError('Las filas por api_version/section del manifiesto no coinciden con el fichero: manifiesto=' . json_encode($declared) . ' fichero=' . json_encode($expected));
            }
        }

        if (isset($manifest['tickers_sha256'])) {
            if ($manifest['tickers_sha256'] !== $tickersSha) {
                $addError('El hash de la lista de tickers del manifiesto NO coincide con los tickers del fichero.');
            }
        } else {
            $warnings[] = 'Formato anterior: el manifiesto no lleva `tickers_sha256`; solo se comparan los recuentos de tickers, no su identidad.';
        }

        if (($manifest['ordering'] ?? null) === null) {
            $warnings[] = 'Formato anterior: el manifiesto no declara orden; no se comprueba el orden de las observaciones.';
        }

        if (!$hasIds) {
            $warnings[] = 'Formato anterior: las filas no llevan `observation_id`; el desempate de observaciones con la misma fecha se hace por posicion en el fichero.';
        }

        if (!isset($manifest['schema']) || !isset($manifest['symbol_equivalences'])) {
            $warnings[] = 'Formato anterior: el manifiesto no incluye esquema ni equivalencias de simbolos; el paquete no es autosuficiente para restaurar sin el repositorio.';
        }
    }
}
