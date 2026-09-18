-- Corrige un bug real reproducido por Astra
-- (`REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B1) en mi propia
-- correccion del `2026-09-16`: `earnings_events_normalization_log`
-- (migracion 030) confundia "este hash se vio ALGUNA VEZ" con "este hash
-- es el estado VIGENTE ahora". En una secuencia A->B->A recapturado, la
-- tercera captura (A) reutiliza el blob de la primera -- el CLI creia que
-- ya estaba "al dia" con A (visto en el pasado) y se saltaba la
-- renormalizacion, dejando el contenido de B publicado indefinidamente
-- aunque el estado real mas reciente fuera A.
--
-- Esta tabla es el ESTADO VIGENTE (una fila por ticker, sustituida en la
-- misma transaccion que `earnings_events`), separado del HISTORIAL
-- append-only de `earnings_events_normalization_log` (a la que se le
-- quita su clave unica en esta misma migracion: coleccionaba solo una
-- fila por hash, perdiendo la cuenta real de reprocesos identicos).
-- `isNormalizedFromSource()` pasa a comparar contra ESTA tabla.
ALTER TABLE earnings_events_normalization_log DROP KEY uniq_earnings_events_normalization_log;

CREATE TABLE IF NOT EXISTS earnings_events_current_state (
    ticker VARCHAR(24) NOT NULL PRIMARY KEY,
    source_hash CHAR(64) NOT NULL,
    captured_at DATETIME NOT NULL,
    source_symbol VARCHAR(24) NULL,
    request_from DATE NULL,
    request_to DATE NULL,
    normalizer_version SMALLINT UNSIGNED NOT NULL,
    event_count INT UNSIGNED NOT NULL,
    normalized_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
