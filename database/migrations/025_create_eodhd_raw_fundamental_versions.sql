-- Versionado del archivo crudo de EODHD (Bloque A del plan de Codex del
-- 2026-09-04, "proteger lo ya pagado antes de nuevas descargas").
--
-- Problema real: `eodhd_raw_fundamentals` (019) guarda una unica fila por
-- ticker con UPSERT (ON DUPLICATE KEY UPDATE) -- un `--force` futuro, o
-- simplemente volver a archivar con la API v1.1, SOBRESCRIBE la captura
-- anterior sin dejar rastro. Esta tabla NO sustituye a
-- `eodhd_raw_fundamentals` (que sigue siendo la ultima version conocida por
-- ticker, usada tal cual por sus consumidores actuales -- no se toca su
-- comportamiento aqui): anhade un historial de TODAS las versiones
-- capturadas, para que ninguna descarga futura destruya lo ya pagado.
--
-- payload_compressed guarda el JSON comprimido con gzip (gzencode()): el
-- archivo actual ocupa unos 580,6 MB sin comprimir para 938 tickers (ver
-- versions.md, 2026-09-04), y cada version nueva sin comprimir repetiria ese
-- crecimiento en cada captura. payload_hash es el sha256 del JSON ORIGINAL
-- SIN comprimir (gzip no es determinista byte a byte entre ejecuciones
-- aunque el contenido sea identico), lo que permite verificar integridad
-- sin descomprimir y deduplicar por contenido real, no por bytes del
-- comprimido.
--
-- api_version/section dejan sitio para Fundamentals v1.1 y para secciones
-- parciales (Bloque B del plan: Financials/Earnings/outstandingShares...),
-- pero HOY solo se usan los valores 'legacy' y 'full': no se crea aqui
-- ninguna logica de secciones parciales que todavia no tiene consumidor.
--
-- parse_status/error_message quedan NULL hoy (solo se versiona lo que ya se
-- archivo con exito); existen para un futuro en el que una captura falle a
-- mitad de camino y convenga dejar constancia sin perder las que si
-- funcionaron.
--
-- Clave unica (ticker, api_version, section, payload_hash): si el contenido
-- no cambia entre dos capturas, no se duplica.
CREATE TABLE IF NOT EXISTS eodhd_raw_fundamental_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(24) NOT NULL,
    api_version VARCHAR(16) NOT NULL,
    section VARCHAR(32) NOT NULL,
    fetched_at DATETIME NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    payload_compressed LONGBLOB NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    source_symbol VARCHAR(24) NULL,
    parse_status VARCHAR(16) NULL,
    error_message TEXT NULL,
    UNIQUE KEY uniq_eodhd_raw_fundamental_versions (ticker, api_version, section, payload_hash),
    KEY idx_eodhd_raw_fundamental_versions_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
