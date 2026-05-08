-- LoanDesk upgrade migration: forgot-password support
-- Safe for existing databases (idempotent checks included).
-- Run this on the target DB selected in phpMyAdmin SQL tab.

-- 1) users.email column
SET @has_users_email := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'email'
);
SET @sql_users_email := IF(
    @has_users_email = 0,
    'ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER username',
    'SELECT 1'
);
PREPARE stmt FROM @sql_users_email;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) unique index on users.email
SET @has_users_email_uq := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'uq_users_email'
);
SET @sql_users_email_uq := IF(
    @has_users_email_uq = 0,
    'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_users_email_uq;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) password_reset_tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    requested_ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_reset_tokens_token_hash (token_hash),
    INDEX idx_password_reset_tokens_user_id (user_id),
    INDEX idx_password_reset_tokens_expires_at (expires_at),
    CONSTRAINT fk_password_reset_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 4) users.status column
SET @has_users_status := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'status'
);
SET @sql_users_status := IF(
    @has_users_status = 0,
    "ALTER TABLE users ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER role",
    "SELECT 1"
);
PREPARE stmt FROM @sql_users_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
