CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope TINYINT UNSIGNED NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempt_ip_time (scope, ip_hash, attempted_at),
    KEY idx_login_attempt_pair_time (scope, ip_hash, email_hash, attempted_at),
    KEY idx_login_attempt_expiry (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
