-- Migration 017: data-quality gate in front of eligibility.
--
-- 1. activity_logs.category gains 'validate' - every validation/conflict
--    flag created, auto-cleared, or manually resolved is written to the
--    audit trail under this category so the Logs page can filter it.
-- 2. New validation_flags table - the ERROR / WARNING / POSSIBLE DUPLICATE
--    ledger produced by shared/data_quality.php before eligibility runs.
--    Nothing here deletes or mutates resident records: flags exist solely
--    to tell an admin what to review. Flags are self-resolving upserts
--    keyed on (check_code, entity) - they open when an issue exists,
--    auto-close once the data is fixed, and re-open if it comes back.
--
-- In phpMyAdmin: barangay420 database, SQL tab, paste this WHOLE file in
-- one go, click Go once. Every step checks first, so it is safe to run on
-- both a fresh schema.sql import and an already-migrated database.

-- 1. Widen the activity_logs category enum.
SET @enum_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs'
      AND COLUMN_NAME = 'category' AND COLUMN_TYPE LIKE '%validate%'
);
SET @sql = IF(@enum_exists = 0,
    "ALTER TABLE activity_logs
     MODIFY COLUMN category ENUM('create','read','update','delete','validate') NULL DEFAULT NULL AFTER action",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. validation_flags: one row per open/auto-resolved issue.
CREATE TABLE IF NOT EXISTS validation_flags (
    flag_id        INT AUTO_INCREMENT PRIMARY KEY,
    check_code     VARCHAR(50)  NOT NULL COMMENT 'Machine code of the check that fired, e.g. H_INCOME_NEGATIVE, D_CROSS_HOUSEHOLD_DUP',
    severity       ENUM('error','warning','possible_duplicate') NOT NULL,
    message        TEXT         NOT NULL COMMENT 'Human-readable explanation, as built by shared/data_quality.php',
    scope          ENUM('household','member') NOT NULL,
    household_id   INT NULL,
    member_id      INT NULL,
    status         ENUM('open','resolved') NOT NULL DEFAULT 'open' COMMENT 'Resolved = the underlying condition no longer exists (auto) or was accepted by an admin (manual)',
    resolved_by    INT NULL,
    resolved_at    DATETIME NULL,
    resolved_note  VARCHAR(500) NULL COMMENT 'Admin explanation when resolved manually; "auto-cleared: condition no longer present" otherwise',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES households(household_id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES household_members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uk_validation_check (check_code, household_id, member_id),
    INDEX idx_validation_status (status, severity),
    INDEX idx_validation_household (household_id)
) ENGINE=InnoDB;

-- Verify with:
-- SELECT column_type FROM information_schema.columns
--   WHERE table_schema = DATABASE() AND table_name = 'activity_logs' AND column_name = 'category'
--   -- should contain 'validate'
-- SELECT COUNT(*) FROM information_schema.tables
--   WHERE table_schema = DATABASE() AND table_name = 'validation_flags';  -- should return 1