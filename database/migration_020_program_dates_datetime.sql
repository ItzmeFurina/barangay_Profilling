-- Migration 020: program validity window becomes date + time.
--
-- programs.start_date / end_date are upgraded from DATE to DATETIME so the
-- deadline can be an exact moment (e.g. Sept 30, 2026 5:00 PM), not just a
-- calendar day. Existing DATE values are preserved with their meaning:
--
--   - start_date '2026-09-01'  -> '2026-09-01 00:00:00'  (opens at midnight)
--   - end_date   '2026-09-30'  -> '2026-09-30 23:59:59'  (active THROUGH that day)
--
-- All comparisons against these timestamps use the app timezone (Asia/Manila):
-- shared/db.php pins it for PHP and sets the MySQL session time_zone to
-- '+08:00', and shared/eligibility.php passes a PHP-computed "now" into the
-- archive/gate queries instead of CURDATE()/NOW().
--
-- In phpMyAdmin: barangay420 database, SQL tab, paste this WHOLE file in one
-- go, click Go once. Every step checks first, so it is safe to run on both a
-- fresh schema.sql import and an already-migrated database.

SET @is_date = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs'
      AND COLUMN_NAME = 'start_date' AND COLUMN_TYPE = 'date'
);
SET @sql = IF(@is_date = 1,
    'ALTER TABLE programs MODIFY COLUMN start_date DATETIME NULL COMMENT ''First moment the program is effective - set on the admin Programs form, checked by shared/eligibility.php (NULL = open-ended)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Convert end_date to DATETIME while keeping "through that day" semantics.
SET @is_date = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs'
      AND COLUMN_NAME = 'end_date' AND COLUMN_TYPE = 'date'
);
SET @sql = IF(@is_date = 1,
    'ALTER TABLE programs
     MODIFY COLUMN end_date DATETIME NULL COMMENT ''Last moment the program is effective - set on the admin Programs form; when it passes the program is auto-archived''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill any existing end_date so a bare date still means "through that day".
UPDATE programs
SET end_date = DATE_ADD(end_date, INTERVAL 24 HOUR) - INTERVAL 1 SECOND
WHERE end_date IS NOT NULL AND TIME(end_date) = '00:00:00';

-- Verify with:
-- SHOW COLUMNS FROM programs LIKE 'end_date';       -- type datetime
-- SELECT program_id, start_date, end_date FROM programs;