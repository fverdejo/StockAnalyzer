ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL AFTER password_hash,
    ADD COLUMN verification_token VARCHAR(64) NULL AFTER email_verified_at,
    ADD COLUMN verification_expires_at DATETIME NULL AFTER verification_token,
    ADD UNIQUE KEY uniq_users_verification_token (verification_token);
