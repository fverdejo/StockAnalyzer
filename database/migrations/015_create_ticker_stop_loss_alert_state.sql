CREATE TABLE IF NOT EXISTS ticker_stop_loss_alert_state (
    user_id INT UNSIGNED NOT NULL,
    ticker VARCHAR(24) NOT NULL,
    last_state VARCHAR(8) NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, ticker),
    CONSTRAINT fk_ticker_stop_loss_alert_state_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
