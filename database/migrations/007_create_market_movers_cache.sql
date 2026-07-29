CREATE TABLE IF NOT EXISTS market_movers_cache (
    kind VARCHAR(16) NOT NULL PRIMARY KEY,
    tickers_payload TEXT NOT NULL,
    fetched_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
