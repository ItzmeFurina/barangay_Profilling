-- Migration 027: email verification and persistent auth-flow rate limits.
-- Existing users are grandfathered as verified; newly created users remain
-- subject to verification until they complete the emailed verification link.

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at'
);
SET @verification_column_added = (@column_exists = 0);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @verification_required_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verification_required'
);
SET @sql = IF(@verification_required_exists = 0,
    'ALTER TABLE users ADD COLUMN email_verification_required TINYINT(1) NOT NULL DEFAULT 1 AFTER email_verified_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@verification_column_added,
    'UPDATE users SET email_verified_at = COALESCE(created_at, NOW()) WHERE email_verified_at IS NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @old_index_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_reset_tokens' AND INDEX_NAME = 'idx_prt_hash'
);
SET @unique_index_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_reset_tokens' AND INDEX_NAME = 'uq_prt_token_hash'
);
SET @sql = IF(@unique_index_exists = 0,
    IF(@old_index_exists = 1,
        'ALTER TABLE password_reset_tokens DROP INDEX idx_prt_hash, ADD UNIQUE INDEX uq_prt_token_hash (token_hash)',
        'ALTER TABLE password_reset_tokens ADD UNIQUE INDEX uq_prt_token_hash (token_hash)'),
    IF(@old_index_exists = 1,
        'ALTER TABLE password_reset_tokens DROP INDEX idx_prt_hash',
        'SELECT 1'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    token_hash  CHAR(64)     NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE INDEX uq_evt_token_hash (token_hash),
    INDEX idx_evt_user (user_id),
    INDEX idx_evt_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    action             VARCHAR(40) NOT NULL,
    identifier_type    VARCHAR(16) NOT NULL,
    identifier_hash    CHAR(64)    NOT NULL,
    window_started_at  DATETIME    NOT NULL,
    attempts           INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (action, identifier_type, identifier_hash),
    INDEX idx_auth_rate_limits_window (window_started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS registration_drafts (
    draft_id      CHAR(64)     NOT NULL PRIMARY KEY,
    workflow      VARCHAR(24)  NOT NULL,
    email         VARCHAR(150) NOT NULL,
    payload       JSON         NOT NULL,
    document_path VARCHAR(255) NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    expires_at    DATETIME     NOT NULL,
    verified_at   DATETIME     NULL,
    submitted_at  DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX uq_registration_draft_token (token_hash),
    INDEX idx_registration_draft_email (email),
    INDEX idx_registration_draft_expiry (expires_at)
) ENGINE=InnoDB;
