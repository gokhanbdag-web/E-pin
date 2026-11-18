CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    email_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    UNIQUE KEY uq_password_reset_token (token_hash),
    KEY idx_password_reset_user (user_id, used_at, expires_at),
    KEY idx_password_reset_rate (email_hash, created_at),
    KEY idx_password_reset_ip_rate (ip_hash, created_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
