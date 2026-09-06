<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Enums\EodhdFundamentalVersionStoreOutcome;

/**
 * Lo que devuelve `EodhdRawFundamentalVersionsRepository::store()` tras
 * archivar una captura (correccion del 2026-09-06 al bug de `INSERT IGNORE`
 * senalado por Codex, ver `versions.md`). `$versionId` identifica siempre el
 * blob (nuevo o ya existente); `$observationId` identifica la fila NUEVA
 * que esa llamada acaba de anhadir a `eodhd_raw_fundamental_version_observations`
 * -- a diferencia del blob, la observacion nunca se deduplica: cada llamada
 * a `store()` que representa una peticion exitosa real deja constancia de
 * si misma.
 */
final class EodhdFundamentalVersionStoreResult
{
    public function __construct(
        public readonly int $versionId,
        public readonly int $observationId,
        public readonly EodhdFundamentalVersionStoreOutcome $outcome
    ) {
    }

    public function isNewVersion(): bool
    {
        return $this->outcome === EodhdFundamentalVersionStoreOutcome::NEW_VERSION;
    }
}
