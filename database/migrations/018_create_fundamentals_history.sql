-- Snapshot diario de los fundamentales de cada ticker (ver versions.md
-- v2.74).
--
-- Motivo: BacktestingService::stockAt() reutiliza los fundamentales de HOY
-- para cada fecha pasada, asi que FUNDAMENTAL + VALUATION + QUALITY +
-- DIVIDEND (65 de 115 puntos del score, el 56% del peso) entran en todo
-- backtest como una constante por ticker y con sesgo de anticipacion. Sin
-- una serie historica real no hay forma de validar esa mitad del motor.
--
-- Yahoo no sirve fundamentales con fecha, asi que la unica via es
-- acumularlos desde hoy: esta tabla no da valor hasta dentro de meses, y
-- por eso conviene empezar a llenarla cuanto antes.
--
-- Mismo patron que score_history (013): una fila por ticker/dia con clave
-- unica, UPSERT idempotente para varias visitas el mismo dia, y los ratios
-- en un unico JSON en vez de una columna por ratio — el conjunto de
-- fundamentales ya ha cambiado una vez (dividendGrowth5y llego en v2.64) y
-- un payload absorbe ese cambio sin migracion nueva.
CREATE TABLE IF NOT EXISTS fundamentals_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(24) NOT NULL,
    snapshot_date DATE NOT NULL,
    fundamentals_payload LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_fundamentals_history_ticker_date (ticker, snapshot_date),
    KEY idx_fundamentals_history_ticker_date (ticker, snapshot_date),
    CHECK (JSON_VALID(fundamentals_payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
