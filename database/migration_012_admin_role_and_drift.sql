-- Migration 012: bring an existing database in line with what the live
-- production database actually contains, but which was never captured in
-- a tracked migration:
--
--   1. users.role is missing 'admin' from its ENUM. The admin portal's
--      require_admin() (admin/includes/session.php) and the project's own
--      CLI provisioning script (database/create_admin.php) both depend on
--      an 'admin' role existing - without this, create_admin.php fails on
--      a fresh install, and no one can ever reach admin-only pages.
--   2. households.monthly_income and program_criteria.max_income exist in
--      the live database (added by hand at some point) but no current
--      code reads or writes them. They are added here purely so the
--      schema is truthful about what's actually in production - not
--      because any feature currently uses them. If nothing ends up using
--      them, they are safe to drop later; nothing in the app depends on
--      them existing.
--
-- Safe to run against a database that already has these columns/values -
-- each statement only changes what's missing.

ALTER TABLE users MODIFY role ENUM('resident','secretary','staff','admin') NOT NULL;

-- households.monthly_income - guarded with information_schema rather than
-- MySQL-8-incompatible "ADD COLUMN IF NOT EXISTS".
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'monthly_income'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE households ADD COLUMN monthly_income DECIMAL(10,2) NULL COMMENT 'Present in the live database but not yet read/written by any current code path - flagged for review, not yet wired to a feature'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- program_criteria.max_income - same guard.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_criteria' AND COLUMN_NAME = 'max_income'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE program_criteria ADD COLUMN max_income DECIMAL(10,2) NULL COMMENT 'Present in the live database but not yet read/written by any current code path - flagged for review, not yet wired to a feature'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
