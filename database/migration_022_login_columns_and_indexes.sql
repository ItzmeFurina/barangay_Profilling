-- Migration 022: reconcile migrated/legacy databases with the objects that
-- exist only in schema.sql, plus performance indexes. Every step is guarded
-- with INFORMATION_SCHEMA checks, so this is safe to run on a fresh install,
-- on a fully-migrated database, or on one where it has already run.
--
-- Fixes:
--   1. users.failed_login_attempts / users.locked_until - both resident and
--      admin login SELECT and UPDATE these, but no migration ever created
--      them. An older database dies with "Column not found" on the login
--      page. (schema.sql had them, migrations did not.)
--   2. residents.household_member_id - the resident login and census linking
--      logic depend on it, again created only by schema.sql.
--   3. Missing indexes called out by the schema review: events.event_date,
--      activity_logs.created_at/category, the household-members
--      (household_id, is_archived) pair, household_members.linked_user_id,
--      beneficiaries.claim_status/pickup_status and households(street,
--      house_number) used by CSV dedupe matching.
--   4. backup_logs.initiated_by was NOT NULL with ON DELETE CASCADE, so
--      deleting a user silently erased their backup audit history. Now
--      nullable with ON DELETE SET NULL.
--   5. households.no_of_members is nullable in older schemas; make it
--      NOT NULL DEFAULT 0.
--   6. The stale legacy households.barangay_zone column (migration_013 was
--      not idempotent and referenced a column fresh schema.sql never had).
--   7. programs.name lacked its UNIQUE constraint (schema.sql has
--      uk_programs_name); added only when no duplicate names exist, so this
--      can never fail on legacy data.

-- ---------------------------------------------------------------------
-- 1. users.failed_login_attempts
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'failed_login_attempts'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1b. users.locked_until
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'locked_until'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN locked_until DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. residents.household_member_id (+ foreign key)
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'household_member_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE residents ADD COLUMN household_member_id INT NULL COMMENT ''Which row in household_members corresponds to this account holder''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents'
      AND COLUMN_NAME = 'household_member_id' AND REFERENCED_TABLE_NAME = 'household_members'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE residents ADD CONSTRAINT fk_residents_household_member FOREIGN KEY (household_member_id) REFERENCES household_members(member_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3. Indexes (each added only when that exact index name is absent)
-- ---------------------------------------------------------------------
-- 3a. events.event_date
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND INDEX_NAME = 'idx_events_date');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE events ADD INDEX idx_events_date (event_date)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3b. activity_logs.created_at
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND INDEX_NAME = 'idx_activity_logs_created');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE activity_logs ADD INDEX idx_activity_logs_created (created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3c. activity_logs.category
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND INDEX_NAME = 'idx_activity_logs_category');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE activity_logs ADD INDEX idx_activity_logs_category (category)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3d. household_members (household_id, is_archived)
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND INDEX_NAME = 'idx_household_members_household_archived');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE household_members ADD INDEX idx_household_members_household_archived (household_id, is_archived)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3e. household_members.linked_user_id
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND INDEX_NAME = 'idx_household_members_linked_user');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE household_members ADD INDEX idx_household_members_linked_user (linked_user_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3f. beneficiaries.claim_status
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND INDEX_NAME = 'idx_beneficiaries_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE beneficiaries ADD INDEX idx_beneficiaries_status (claim_status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3g. beneficiaries.pickup_status
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND INDEX_NAME = 'idx_beneficiaries_pickup');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE beneficiaries ADD INDEX idx_beneficiaries_pickup (pickup_status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3h. households (street, house_number) - CSV dedupe matching
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND INDEX_NAME = 'idx_households_street_house');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE households ADD INDEX idx_households_street_house (street, house_number)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. backup_logs: initiated_by nullable + ON DELETE SET NULL (audit trail)
-- ---------------------------------------------------------------------
-- 4a. Make the column nullable (required before a SET NULL FK can be added).
SET @nullable = (SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_logs' AND COLUMN_NAME = 'initiated_by');
SET @sql = IF(@nullable = 'NO', 'ALTER TABLE backup_logs MODIFY initiated_by INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4b. Drop the old CASCADE foreign key if it is still there.
SET @fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
    JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
      ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
     AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
    WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.TABLE_NAME = 'backup_logs'
      AND kcu.COLUMN_NAME = 'initiated_by' AND kcu.REFERENCED_TABLE_NAME = 'users'
      AND rc.DELETE_RULE = 'CASCADE'
    LIMIT 1
);
SET @sql = IF(@fk_name IS NOT NULL,
    CONCAT('ALTER TABLE backup_logs DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4c. Add the ON DELETE SET NULL foreign key when there is none left.
SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_logs'
      AND COLUMN_NAME = 'initiated_by' AND REFERENCED_TABLE_NAME = 'users'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE backup_logs ADD CONSTRAINT fk_backup_logs_user FOREIGN KEY (initiated_by) REFERENCES users(user_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 5. households.no_of_members -> NOT NULL DEFAULT 0
-- ---------------------------------------------------------------------
SET @nullable = (SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'no_of_members');
SET @sql = IF(@nullable = 'YES',
    'UPDATE households SET no_of_members = 0 WHERE no_of_members IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@nullable = 'YES',
    'ALTER TABLE households MODIFY no_of_members INT NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 6. Drop the stale legacy households.barangay_zone column, guarded so this
--    never fails on a database that never had it (fresh schema.sql).
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'barangay_zone'
);
SET @sql = IF(@col_exists > 0, 'ALTER TABLE households DROP COLUMN barangay_zone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 7. programs.name UNIQUE (schema.sql uk_programs_name). Guarded twice:
--    skip if it already exists, and skip rather than fail when legacy
--    duplicate names are present.
-- ---------------------------------------------------------------------
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND INDEX_NAME = 'uk_programs_name');
SET @dupes = (SELECT COUNT(*) FROM
    (SELECT name FROM programs GROUP BY name HAVING COUNT(*) > 1) AS dup_names);
SET @sql = IF(@idx_exists = 0 AND @dupes = 0,
    'ALTER TABLE programs ADD UNIQUE KEY uk_programs_name (name)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Done. Verify with:
-- SHOW COLUMNS FROM users LIKE 'failed_login_attempts';
-- SHOW COLUMNS FROM users LIKE 'locked_until';
-- SHOW COLUMNS FROM residents LIKE 'household_member_id';
-- SHOW INDEX FROM events WHERE Key_name = 'idx_events_date';
-- SHOW INDEX FROM activity_logs WHERE Key_name IN ('idx_activity_logs_created','idx_activity_logs_category');
