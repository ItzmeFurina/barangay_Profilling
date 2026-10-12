-- Migration 023: password reset tokens table
-- Stores hashed tokens for the forgot-password flow.
-- Tokens are SHA-256 hashed; only the raw token goes in the URL.

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    token_hash  VARCHAR(64)  NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_prt_user   (user_id),
    INDEX idx_prt_hash   (token_hash),
    INDEX idx_prt_expiry (expires_at)
) ENGINE=InnoDB;
