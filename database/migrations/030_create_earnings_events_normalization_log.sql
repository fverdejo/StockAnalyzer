-- Corrige un hueco real senalado por Astra
-- (`AUDITORIA_Y_TAREAS_EODHD_ASTRA_2026-09-16.md`, tarea A2, aceptacion:
-- "Guardar procedencia tambien para resultados validos vacios"): un ticker
-- sin ningun evento de resultados (60/938 en el archivado original, ver
-- `versions.md` 2026-09-05 -- un historico valido puede estar vacio) no
-- deja NINGUNA fila en `earnings_events` (migracion 026) cuando se
-- normaliza, porque `EarningsEventsRepository::replaceForTicker()` solo
-- inserta si `$events !== []`. Sin ninguna fila, `isNormalizedFromSource()`
-- (que consultaba `earnings_events` directamente) nunca podia confirmar
-- "ya se comprobo este ticker con este hash, de verdad esta vacio" --
-- cada ejecucion de `bin/normalize-eodhd-earnings-events.php` volvia a
-- renormalizar esos tickers desde cero, indefinidamente.
--
-- Esta tabla registra CADA intento de normalizacion completado con exito
-- (`replaceForTicker()` ya ejecutado, sin excepcion), tenga o no eventos --
-- a diferencia de `earnings_events`, que solo registra el CONTENIDO, esta
-- tabla registra la PROCEDENCIA del intento en si. `isNormalizedFromSource()`
-- pasa a consultar esta tabla, no `earnings_events`.
CREATE TABLE IF NOT EXISTS earnings_events_normalization_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(24) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    captured_at DATETIME NOT NULL,
    event_count INT UNSIGNED NOT NULL,
    normalized_at DATETIME NOT NULL,
    UNIQUE KEY uniq_earnings_events_normalization_log (ticker, source_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
