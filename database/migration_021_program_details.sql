-- Migration 021: richer program/benefit details on the Programs form.
--
-- programs gains five new columns so the admin can record more than just a
-- name + validity window when creating a program/benefit:
--
--   - category            one of the fixed dropdown categories, or a custom
--                         label typed when the admin picks 'Other'
--   - provider            source/provider (organization, government agency,
--                         office, NGO, sponsor, etc.)
--   - application_deadline the last moment residents may apply; when NULL
--                         the program's end_date is treated as the deadline
--   - release_period      when the benefit itself will be released/claimed
--                         (free text, e.g. "Oct 15, 2026" / "Every 15th")
--   - slot_limit          available slots / beneficiary limit; NULL = unlimited
--
-- The existing program_type column stays untouched: it is legacy and still
-- read by resident/apply-benefit.php for the fallback ID label, so removing
-- it would break that fallback. New programs simply store category into
-- program_type as well, keeping the old path working unchanged.
--
-- In phpMyAdmin: barangay420 database, SQL tab, paste this WHOLE file in one
-- go, click Go once. Every step checks first, so it is safe to run on both a
-- fresh schema.sql import and an already-migrated database.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'category'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN category VARCHAR(100) NULL COMMENT ''Program/benefit category - one of the fixed dropdown choices, or a custom label when "Other" is picked'' AFTER program_type',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'provider'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN provider VARCHAR(255) NULL COMMENT ''Source/provider of the program - organization, government agency, office, NGO, sponsor, etc.'' AFTER category',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'application_deadline'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN application_deadline DATETIME NULL COMMENT ''Last moment residents may apply; NULL = program end_date is treated as the deadline'' AFTER end_date',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'release_period'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN release_period VARCHAR(255) NULL COMMENT ''Benefit release date/period - free text, e.g. "Oct 15, 2026" or "Every 15th of the month"'' AFTER application_deadline',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'slot_limit'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN slot_limit INT NULL COMMENT ''Available slots / beneficiary limit; NULL = unlimited slots'' AFTER release_period',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill category from the legacy program_type so older programs read as
-- their proper category instead of an empty cell in the new view.
UPDATE programs
SET category = 'Senior Citizen Assistance'
WHERE category IS NULL AND program_type = 'Senior';

UPDATE programs
SET category = 'PWD Assistance'
WHERE category IS NULL AND program_type = 'PWD';

-- Verify with:
-- SHOW COLUMNS FROM programs LIKE 'category';              -- should return 1 row
-- SELECT program_id, name, category, application_deadline,
--        slot_limit FROM programs;