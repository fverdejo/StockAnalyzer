CREATE TABLE IF NOT EXISTS watchlist_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ticker VARCHAR(24) NOT NULL,
    added_at DATETIME NOT NULL,
    UNIQUE KEY uniq_watchlist_user_ticker (user_id, ticker),
    CONSTRAINT fk_watchlist_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
