-- Tabla PARALELA a fundamentals_history (018), misma estructura exacta,
-- para regenerar los snapshots con la formula corregida en v2.110 (ver
-- roadmap.md, punto 4 de "Prioridad cero") sin tocar ni sobrescribir el
-- historico existente. fundamentals_history acumula desde v2.74/v2.93 con
-- el bug anual/trimestral (corregido el 2026-09-01, ver PointInTimeFundamentalsBuilder);
-- esta tabla es donde se recalcula para comparar distribuciones antes/despues
-- e investigar outliers, sin ningun riesgo sobre los datos que ya usa la
-- aplicacion en produccion.
--
-- El intercambio (renombrar tablas, sincronizar con la Pi) es una decision
-- posterior del usuario, no de esta migracion: esta tabla se queda aqui
-- como esta, lista para revisar, hasta que se decida.
CREATE TABLE IF NOT EXISTS fundamentals_history_v2110 (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(24) NOT NULL,
    snapshot_date DATE NOT NULL,
    fundamentals_payload LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_fundamentals_history_v2110_ticker_date (ticker, snapshot_date),
    KEY idx_fundamentals_history_v2110_ticker_date (ticker, snapshot_date),
    CHECK (JSON_VALID(fundamentals_payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
