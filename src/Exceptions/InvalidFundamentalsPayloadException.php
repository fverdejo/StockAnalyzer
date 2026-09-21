<?php

declare(strict_types=1);

namespace StockAnalyzer\Exceptions;

use RuntimeException;

/**
 * El snapshot de `fundamentals_history` que correspondia a una fecha tiene un
 * `fundamentals_payload` que NO es un objeto JSON utilizable (literal `null`,
 * un numero, una lista, JSON malformado...). En el modo estricto de
 * `FundamentalsHistoryRepository`/`PreloadedFundamentalsHistoryRepository`
 * (recorridos de medicion, donde rescatar en silencio un dato ANTERIOR
 * falsearia el resultado) se lanza esto para que el fallo sea identificable
 * (encargo C7 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`).
 */
final class InvalidFundamentalsPayloadException extends RuntimeException
{
    public static function forSnapshot(string $ticker, string $snapshotDate): self
    {
        return new self(sprintf(
            "El snapshot de fundamentales de %s del %s tiene un payload que no es un objeto JSON valido.",
            $ticker,
            $snapshotDate
        ));
    }
}
