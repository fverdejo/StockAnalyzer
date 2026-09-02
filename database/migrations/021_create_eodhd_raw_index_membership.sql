-- Archivo de la respuesta CRUDA de EODHD /api/fundamentals/{INDICE}.INDX
-- por indice, sin transformar (roadmap.md, "Segundo bloque" punto 1,
-- 2026-09-02).
--
-- Confirmado contra la API real antes de escribir esta tabla (mismo
-- criterio que EodhdRawFundamentalsRepository, ver comentario de esa
-- clase): el mismo endpoint de fundamentales que ya se usa para acciones
-- (`/api/fundamentals/{ticker}`) sirve tambien indices bajo el sufijo
-- `.INDX`, y devuelve ademas dos secciones nuevas segun el indice:
--
--   - `HistoricalTickerComponents`: el listado COMPLETO de miembros
--     historicos (Code, Name, StartDate, EndDate, IsActiveNow, IsDelisted).
--     Confirmado SOLO en GSPC.INDX (S&P 500) con la suscripcion actual
--     (Fundamentals Data Feed): 819 entradas, 504 activos hoy, 315 ya no
--     activos. La documentacion publica de EODHD afirma que tambien cubre
--     MID.INDX/SML.INDX/OEX.INDX, pero la llamada real a esos tres
--     endpoints el 2026-09-02 NO devolvio esa seccion (solo `Components`,
--     la lista de miembros ACTUALES) -- documentado como discrepancia
--     doc-vs-realidad, no asumido. Consultado tambien el producto de pago
--     separado "Indices Historical Constituents Data API"
--     (marketplace/unicornbay/spglobal, ~$29,99/mes adicional): es un
--     addon NO incluido en la suscripcion actual, no contratado.
--   - `HistoricalComponents` (parametro `historical=1&from=...&to=...`):
--     snapshots point-in-time de la composicion en cada fecha de cambio.
--     Confirmado SOLO en GSPC.INDX (326 fechas de cambio entre 2012-04-04
--     y 2026-07-02 en la ejecucion real).
--
-- Por eso `index_code` incluye los cuatro indices (se archiva lo que HAY,
-- aunque en tres de ellos sea solo el snapshot actual), pero
-- `has_point_in_time` distingue cual de verdad sirve para reconstruir un
-- universo point-in-time (ver `index_membership`, migracion 022): hoy solo
-- GSPC.INDX.
CREATE TABLE IF NOT EXISTS eodhd_raw_index_membership (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    index_code VARCHAR(24) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    has_point_in_time TINYINT(1) NOT NULL DEFAULT 0,
    fetched_at DATETIME NOT NULL,
    UNIQUE KEY uniq_eodhd_raw_index_membership_index (index_code),
    CHECK (JSON_VALID(payload_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
