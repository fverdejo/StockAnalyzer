CREATE TABLE IF NOT EXISTS corporate_profile_cache (
    ticker VARCHAR(24) NOT NULL,
    result_payload LONGTEXT NOT NULL,
    cached_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (ticker),
    CHECK (JSON_VALID(result_payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
