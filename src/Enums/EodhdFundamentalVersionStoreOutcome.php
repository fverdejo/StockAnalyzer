<?php

declare(strict_types=1);

namespace StockAnalyzer\Enums;

/**
 * Resultado real de `EodhdRawFundamentalVersionsRepository::store()`
 * (correccion del 2026-09-06 al bug de `INSERT IGNORE` senalado por Codex,
 * ver `versions.md`): antes `store()` devolvia `void`, asi que ningun
 * llamador podia saber si una captura recien completada trajo un blob
 * nuevo o repitio contenido ya archivado -- ni distinguirlo de un fallo
 * silencioso. Los blobs siguen deduplicados por `payload_hash` (ver
 * docblock de la clase); este enum solo describe si ESE hash ya existia.
 * En los dos casos `store()` deja siempre una fila nueva en
 * `eodhd_raw_fundamental_version_observations`: este resultado no dice si
 * hubo observacion, dice si hizo falta guardar un blob nuevo para ella.
 */
enum EodhdFundamentalVersionStoreOutcome: string
{
    case NEW_VERSION = 'new_version';
    case DUPLICATE_CONTENT = 'duplicate_content';
}
