-- Registro de OBSERVACIONES del archivo versionado de EODHD -- correccion de
-- un bug real encontrado por Codex el 2026-09-05/06 en la revision
-- independiente de `d608747` (ver `versions.md`, entrada del 2026-09-06, y
-- `RESULTADOS_OPTIMIZACION_MOTOR_CODEX_2026-09-05.md`, seccion P0 punto 2).
--
-- Bug corregido: `eodhd_raw_fundamental_versions` (025) deduplica BLOBS por
-- `payload_hash` -- su clave unica incluye ese hash -- y
-- `EodhdRawFundamentalVersionsRepository::store()` usaba `INSERT IGNORE`
-- contra esa clave. Si la MISMA captura (contenido byte a byte identico) se
-- repetia dos veces, la segunda insercion se ignoraba en silencio y solo
-- quedaba una fila. Eso rompia dos garantias que hacen falta para comparar
-- vintages (Bloque E2/E3 del plan de aprovechamiento de EODHD):
--
--   1. No se podia distinguir "se recapturo y era identico" de "nunca se
--      recaptura" -- las dos situaciones dejaban exactamente una fila.
--   2. Una secuencia A->B->A (el valor cambia y luego VUELVE al original)
--      perdia la tercera captura por completo: colisionaba por hash con la
--      primera fila ya existente y se ignoraba, asi que `latestFor()` (que
--      resolvia por la fila mas reciente, no por la captura real mas
--      reciente) devolvia B como "el mas reciente" aunque el ultimo estado
--      observado de verdad fuera A.
--
-- Esta tabla NO sustituye a `eodhd_raw_fundamental_versions`: los BLOBS
-- siguen deduplicados por hash ahi (no tiene sentido guardar el mismo JSON
-- comprimido dos veces). Lo que cambia es que CADA peticion exitosa deja
-- aqui una fila nueva, apunte o no `version_id` a un blob ya archivado --
-- la fuente de verdad de "cuando se observo que" pasa a ser esta tabla, no
-- `fetched_at` de la fila de blob que la peticion toco. `latestFor()` ahora
-- resuelve por `observed_at_utc` maximo de esta tabla (ver
-- `EodhdRawFundamentalVersionsRepository`), no por la version mas reciente.
--
-- `ticker`/`api_version`/`section` se repiten aqui (denormalizados desde la
-- version referenciada via `version_id`): son exactamente los campos por
-- los que ya filtra todo el repositorio (`latestFor()`, `allPayloadsFor()`,
-- `hasVersion()`), y evitan un JOIN en la consulta mas frecuente.
--
-- `request_from`/`request_to` quedan NULL cuando el endpoint no toma
-- ventana de fechas (p.ej. `v1.1/full`, `sec-form4/full`); se rellenan para
-- `calendar/earnings`, donde la ventana `from`/`to` afecta la respuesta
-- (ver `bin/archive-eodhd-calendar-earnings.php`) y por tanto forma parte
-- de lo que se observo en esa captura.
CREATE TABLE IF NOT EXISTS eodhd_raw_fundamental_version_observations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version_id INT UNSIGNED NOT NULL,
    ticker VARCHAR(24) NOT NULL,
    api_version VARCHAR(16) NOT NULL,
    section VARCHAR(32) NOT NULL,
    observed_at_utc DATETIME NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    source_symbol VARCHAR(24) NULL,
    request_from DATE NULL,
    request_to DATE NULL,
    CONSTRAINT fk_eodhd_raw_fundamental_version_observations_version
        FOREIGN KEY (version_id) REFERENCES eodhd_raw_fundamental_versions (id)
        ON DELETE CASCADE,
    KEY idx_eodhd_raw_fundamental_version_observations_lookup (ticker, api_version, section, observed_at_utc),
    KEY idx_eodhd_raw_fundamental_version_observations_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migra cada fila YA archivada de `eodhd_raw_fundamental_versions` como su
-- PRIMERA observacion: usa el `fetched_at` ya existente de esa fila como
-- `observed_at_utc` de esta primera observacion, tal como pidio Codex. A
-- partir de aqui ninguna captura nueva anhade una fila a
-- `eodhd_raw_fundamental_versions` sin tambien anhadir su propia fila aqui
-- -- ver `EodhdRawFundamentalVersionsRepository::store()`.
INSERT INTO eodhd_raw_fundamental_version_observations
    (version_id, ticker, api_version, section, observed_at_utc, http_status, source_symbol)
SELECT id, ticker, api_version, section, fetched_at, http_status, source_symbol
FROM eodhd_raw_fundamental_versions;
