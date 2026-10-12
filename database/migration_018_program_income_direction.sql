-- Migration 018: income direction on program criteria.
--
-- program_criteria gains min_income: the "at least" threshold picked on the
-- admin Programs form. max_income stays the existing "up to" threshold.
-- Both null = no income condition; both set (not exposed by the form but
-- harmless) = the household income must sit inside the band.
--
-- A household with no income on file never qualifies either direction -
-- unknown income is treated like unknown age, it cannot satisfy a
-- means-tested criterion.
--
-- In phpMyAdmin: barangay420 database, SQL tab, paste this WHOLE file in one
-- go, click Go once. Every step checks first, so it is safe to run on both a
-- fresh schema.sql import and an already-migrated database.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_criteria' AND COLUMN_NAME = 'min_income'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE program_criteria ADD COLUMN min_income DECIMAL(10,2) NULL COMMENT ''Minimum monthly household income qualifying for the program - edited on the admin Programs form, checked by shared/eligibility.php''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verify with:
-- SHOW COLUMNS FROM program_criteria LIKE 'min_income';  -- should return 1 row