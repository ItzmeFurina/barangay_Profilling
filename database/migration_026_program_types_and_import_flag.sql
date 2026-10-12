-- Migration 026: Two program types + separate "import existing list" option
--
-- The old model used one axis, application_mode ENUM('application_required',
-- 'existing_beneficiary'), which conflated two independent questions:
--   1. Do residents have to apply?   (application required vs not)
--   2. May the admin import a list?  (import available vs not)
-- This migration splits them:
--   programs.application_mode    ENUM('application_required','no_application_required')
--   programs.import_existing_list BOOLEAN  -- the new Yes/No import option,
--                                           -- available under BOTH modes
--
-- Existing 'existing_beneficiary' rows were import-only programs, so they
-- become 'no_application_required' with import_existing_list = TRUE.
-- Those legacy programs have no program_criteria rows, so the eligibility
-- engine keeps ignoring them exactly as before - no behavior change for
-- data already on file.
--
-- Safe to re-run: every step is guarded (INFORMATION_SCHEMA checks or
-- state checks that are false once the migration has fully applied).

-- ── 1. programs.import_existing_list ─────────────────────────────────────
SET @flag_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'import_existing_list'
);
SET @sql = IF(@flag_exists = 0,
    "ALTER TABLE programs ADD COLUMN import_existing_list BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE = admins may import an official beneficiary list into this program (admin/import-beneficiaries.php). Available under both application modes.' AFTER application_mode",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Widen application_mode so the remap below is valid ────────────────
-- (Modifying an ENUM to drop a value the data still uses would truncate in
-- strict mode, so the new value is added first, rows are remapped, and only
-- then is the old value removed.)
SET @ctype = (
    SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'application_mode'
);
SET @sql = IF(@ctype LIKE '%no_application_required%',
    'SELECT 1',
    "ALTER TABLE programs MODIFY COLUMN application_mode ENUM('application_required','existing_beneficiary','no_application_required') NOT NULL DEFAULT 'application_required' COMMENT 'application_required = residents apply through the portal; no_application_required = the eligibility engine enrolls matching residents automatically (no application, no admin approval)'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Refresh after the possible widen above.
SET @ctype = (
    SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'application_mode'
);

-- ── 3. Remap old value -> no_application_required ────────────────────────
SET @sql = IF(@ctype LIKE '%existing_beneficiary%',
    "UPDATE programs SET application_mode = 'no_application_required' WHERE application_mode = 'existing_beneficiary'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. Narrow the enum back down (old value now unused) ──────────────────
SET @sql = IF(@ctype LIKE '%existing_beneficiary%',
    "ALTER TABLE programs MODIFY COLUMN application_mode ENUM('application_required','no_application_required') NOT NULL DEFAULT 'application_required' COMMENT 'application_required = residents apply through the portal; no_application_required = the eligibility engine enrolls matching residents automatically (no application, no admin approval)'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 5. Backfill the import flag for the migrated import-only programs ────
-- Runs only when step 1 actually created the column, so a later re-run can
-- never flip back an import flag an admin deliberately created as FALSE.
SET @sql = IF(@flag_exists = 0,
    "UPDATE programs SET import_existing_list = TRUE WHERE application_mode IN ('existing_beneficiary', 'no_application_required')",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Verification ─────────────────────────────────────────────────────────
-- SHOW COLUMNS FROM programs LIKE 'import_existing_list';               -- should return 1 row
-- SHOW COLUMNS FROM programs LIKE 'application_mode';
--   COLUMN_TYPE should read: enum('application_required','no_application_required')
-- SELECT application_mode, import_existing_list, COUNT(*) FROM programs GROUP BY 1, 2;
--   every legacy row should read no_application_required / 1
