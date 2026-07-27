CREATE TABLE IF NOT EXISTS market_data_cache (
    ticker VARCHAR(24) PRIMARY KEY,
    stock_payload LONGTEXT NULL,
    stock_cached_at DATETIME NULL,
    history_payload LONGTEXT NULL,
    history_cached_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    CHECK (stock_payload IS NULL OR JSON_VALID(stock_payload)),
    CHECK (history_payload IS NULL OR JSON_VALID(history_payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
