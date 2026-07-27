CREATE TABLE IF NOT EXISTS transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ticker VARCHAR(24) NOT NULL,
    type ENUM('buy', 'sell') NOT NULL,
    quantity DECIMAL(18, 6) NOT NULL,
    price DECIMAL(18, 6) NOT NULL,
    executed_at DATETIME NOT NULL,
    INDEX idx_transactions_user_ticker (user_id, ticker),
    CONSTRAINT fk_transactions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
