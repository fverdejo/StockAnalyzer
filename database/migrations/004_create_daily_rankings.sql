CREATE TABLE IF NOT EXISTS daily_rankings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ranking_date DATE NOT NULL,
    name VARCHAR(80) NOT NULL,
    tickers_hash CHAR(40) NOT NULL,
    payload LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_daily_rankings (ranking_date, name, tickers_hash),
    CHECK (JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
