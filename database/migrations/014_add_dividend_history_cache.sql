ALTER TABLE market_data_cache
    ADD COLUMN dividend_history_payload LONGTEXT NULL AFTER history_cached_at,
    ADD COLUMN dividend_history_cached_at DATETIME NULL AFTER dividend_history_payload,
    ADD CONSTRAINT chk_dividend_history_payload_valid_json
        CHECK (dividend_history_payload IS NULL OR JSON_VALID(dividend_history_payload));
