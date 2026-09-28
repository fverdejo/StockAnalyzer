<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use RuntimeException;
use StockAnalyzer\Enums\StudyVerdictStatus;

/**
 * Registro MECANICO de multiplicidad (C10.4, predeclaracion cerrada de
 * `auditor-estadistico`, 2026-09-28 -- ver `versions.md`): una linea JSON
 * por cada ejecucion de test formal sobre este proyecto, append-only por
 * construccion (`fopen()` en modo `'a'`, nunca reescritura del fichero
 * completo) para que el contador de "cuantas veces se ha tocado esta
 * pregunta" (`family_id`) sea imposible de perder por un bug de
 * lectura-modificacion-escritura o de olvidar actualizar a mano.
 */
final class FormalTestRegistry
{
    public function __construct(
        private readonly string $path = __DIR__ . '/../../storage/formal_test_registry.jsonl'
    ) {
    }

    /**
     * @return int el `family_sequence_number` asignado a este registro (1 = primera vez que se toca esta `family_id`)
     */
    public function record(
        string $familyId,
        string $studyLabel,
        string $metricName,
        string $universe,
        ?int $horizonDays,
        string $predeclarationRef,
        StudyVerdictStatus $verdictStatus,
        string $bootstrapMethodId
    ): int {
        $sequenceNumber = $this->countExisting($familyId) + 1;

        $line = [
            'recorded_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'family_id' => $familyId,
            'family_sequence_number' => $sequenceNumber,
            'study_label' => $studyLabel,
            'metric_name' => $metricName,
            'universe' => $universe,
            'horizon_days' => $horizonDays,
            'predeclaration_ref' => $predeclarationRef,
            'verdict_status' => $verdictStatus->value,
            'bootstrap_method_id' => $bootstrapMethodId,
        ];

        $encoded = json_encode($line, JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('No se pudo serializar el registro de test formal: ' . json_last_error_msg());
        }

        $handle = fopen($this->path, 'a');

        if ($handle === false) {
            throw new RuntimeException(sprintf('No se pudo abrir %s en modo append.', $this->path));
        }

        try {
            if (fwrite($handle, $encoded . "\n") === false) {
                throw new RuntimeException(sprintf('Escritura fallida en %s.', $this->path));
            }
        } finally {
            fclose($handle);
        }

        return $sequenceNumber;
    }

    public function countExisting(string $familyId): int
    {
        if (!file_exists($this->path)) {
            return 0;
        }

        $count = 0;
        $handle = fopen($this->path, 'r');

        if ($handle === false) {
            throw new RuntimeException(sprintf('No se pudo abrir %s en modo lectura.', $this->path));
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (is_array($decoded) && ($decoded['family_id'] ?? null) === $familyId) {
                    $count++;
                }
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }
}
