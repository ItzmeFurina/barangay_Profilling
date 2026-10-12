-- Migration 019: program validity period.
--
-- programs gains start_date and end_date: the window during which a program
-- is effective ("active on that period"). New programs must be created with
-- both dates; existing rows stay NULL, which the engine treats as open-ended
-- (no limit on that side), so current programs are untouched.
--
-- The end date drives auto-archival: shared/eligibility.php runs a lazy sweep
-- (maybe_archive_programs()) that flips a program to 'inactive' once the end
-- date passes, and load_active_criteria() never matches a program whose
-- window does not cover today.
--
-- In phpMyAdmin: barangay420 database, SQL tab, paste this WHOLE file in one
-- go, click Go once. Every step checks first, so it is safe to run on both a
-- fresh schema.sql import and an already-migrated database.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'start_date'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN start_date DATE NULL COMMENT ''First day the program is effective - set on the admin Programs form, checked by shared/eligibility.php (NULL = open-ended)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'end_date'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN end_date DATE NULL COMMENT ''Last day the program is effective - set on the admin Programs form; when it passes, maybe_archive_programs() sets the program to inactive''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verify with:
-- SHOW COLUMNS FROM programs LIKE 'end_date';  -- should return 1 row