CREATE TABLE IF NOT EXISTS score_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(24) NOT NULL,
    snapshot_date DATE NOT NULL,
    total_score DECIMAL(6,2) NOT NULL,
    max_total DECIMAL(6,2) NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    category_breakdown LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_score_history_ticker_date (ticker, snapshot_date),
    CHECK (JSON_VALID(category_breakdown))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
