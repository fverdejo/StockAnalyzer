-- Repositorio normalizado de membresia de indice (roadmap.md, "Segundo
-- bloque" punto 2, 2026-09-02): que tickers pertenecian a que indice en
-- que rango de fechas, para poder construir un universo point-in-time y
-- eliminar el sesgo de supervivencia del backtest transversal.
--
-- Poblada UNICAMENTE desde `HistoricalTickerComponents` de GSPC.INDX (ver
-- migracion 021 y el comentario de `EodhdIndexMembershipParser`): es el
-- unico de los cuatro indices (GSPC/MID/SML/OEX) que la suscripcion actual
-- sirve con fechas de entrada/salida reales. Para MID/SML/OEX no se
-- inserta ninguna fila aqui (solo se archivo el snapshot actual en
-- `eodhd_raw_index_membership`, sin fechas point-in-time fiables) -- una
-- fila fabricada con una fecha de inicio inventada seria peor que no tener
-- el dato.
--
-- `original_symbol` queda SIEMPRE NULL hoy: la API "Symbol Change History"
-- de EODHD que mapea ticker viejo -> nuevo requiere el plan "All-In-One,
-- EOD+Intraday -- All World Extended" (confirmado con una llamada real:
-- 403 Forbidden contra `/api/symbol-change-history` con la api_key de
-- Fundamentals Data Feed), un plan distinto y no contratado. Cuando EODHD
-- reutiliza un ticker para una empresa NO relacionada, lo refleja con un
-- codigo distinto en `HistoricalTickerComponents` (p.ej. `APC_OLD` para la
-- Anadarko Petroleum original, distinta de la `APC`/`ARKO Petroleum Corp.`
-- que existe hoy) -- ese codigo especial, cuando aparece, ya identifica de
-- forma inambigua al miembro historico y se guarda tal cual en `ticker`.
CREATE TABLE IF NOT EXISTS index_membership (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(24) NOT NULL,
    index_code VARCHAR(24) NOT NULL,
    company_name VARCHAR(191) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    is_active_now TINYINT(1) NOT NULL DEFAULT 0,
    is_delisted TINYINT(1) NOT NULL DEFAULT 0,
    original_symbol VARCHAR(24) NULL,
    source VARCHAR(48) NOT NULL DEFAULT 'eodhd_historical_ticker_components',
    UNIQUE KEY uniq_index_membership (ticker, index_code),
    KEY idx_index_membership_lookup (index_code, start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
