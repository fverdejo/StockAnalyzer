CREATE TABLE IF NOT EXISTS ticker_alert_state (
    user_id INT UNSIGNED NOT NULL,
    ticker VARCHAR(24) NOT NULL,
    last_recommendation VARCHAR(20) NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, ticker),
    CONSTRAINT fk_ticker_alert_state_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ticker VARCHAR(24) NOT NULL,
    message VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    read_at DATETIME NULL,
    INDEX idx_alerts_user_unread (user_id, read_at),
    CONSTRAINT fk_alerts_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
