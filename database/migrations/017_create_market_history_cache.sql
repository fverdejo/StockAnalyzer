-- El historico diario deja de vivir en market_data_cache (una fila por
-- ticker) y pasa a una tabla propia con clave (ticker, history_range).
--
-- Motivo: el rango del historico que se pide al proveedor es
-- parametrizable (la web sigue pidiendo 2y; bin/backtest.php puede pedir
-- 10y para tener potencia estadistica). Con la clave anterior, solo el
-- ticker, una ejecucion de backtest con rango largo sobrescribia el
-- historico de 2 años que sirve la web (y al reves), asi que ninguno de
-- los dos consumidores podia fiarse de lo que encontraba en cache. El
-- rango forma parte de la identidad del dato cacheado, igual que
-- (ticker, horizon_days, step) lo es en ticker_backtest_cache.
--
-- stock_payload y dividend_history_payload NO son dependientes del rango,
-- por eso se quedan en market_data_cache: duplicarlos por rango obligaria
-- a pedir dos veces al proveedor el mismo dato.
--
-- Las filas existentes se conservan como rango '2y', que es exactamente lo
-- que habia cacheado hasta ahora, para no forzar una redescarga completa
-- del universo al aplicar la migracion.

CREATE TABLE IF NOT EXISTS market_history_cache (
    ticker VARCHAR(24) NOT NULL,
    history_range VARCHAR(8) NOT NULL,
    history_payload LONGTEXT NOT NULL,
    history_cached_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (ticker, history_range),
    CHECK (JSON_VALID(history_payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO market_history_cache (ticker, history_range, history_payload, history_cached_at, updated_at)
SELECT ticker, '2y', history_payload, history_cached_at, NOW()
FROM market_data_cache
WHERE history_payload IS NOT NULL
  AND history_cached_at IS NOT NULL;

-- MariaDB elimina con la columna el CHECK que solo depende de ella.
ALTER TABLE market_data_cache
    DROP COLUMN history_payload,
    DROP COLUMN history_cached_at;
