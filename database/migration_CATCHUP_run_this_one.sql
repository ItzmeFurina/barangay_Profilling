-- Catch-up migration: brings ANY copy of this database - freshly
-- imported from schema.sql, or already partway through migrations
-- 002-005 in some combination you're not sure of - up to fully current.
--
-- Every step checks first, so this is safe to run exactly once
-- regardless of what you've already applied: nothing gets duplicated,
-- nothing errors out because a column or table already exists.
--
-- In phpMyAdmin: barangay420 database, SQL tab, paste this WHOLE file
-- in one go, click Go once.

-- 1. household_members.is_archived (was migration 002)
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'is_archived'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE household_members ADD COLUMN is_archived BOOLEAN NOT NULL DEFAULT FALSE AFTER is_unemployed',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. barangay_info table + seed row (was migration 003) - both already
--    naturally safe to repeat (CREATE ... IF NOT EXISTS, INSERT IGNORE)
CREATE TABLE IF NOT EXISTS barangay_info (
    info_id          INT PRIMARY KEY,
    tagline          VARCHAR(255) NULL,
    about_text       TEXT NULL,
    address          VARCHAR(255) NULL,
    office_hours     VARCHAR(255) NULL,
    contact_number   VARCHAR(20) NULL,
    contact_email    VARCHAR(150) NULL,
    punong_barangay  VARCHAR(150) NULL,
    updated_by       INT NULL,
    updated_at       DATETIME NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO barangay_info (info_id, tagline, address, office_hours)
VALUES (1, 'Serving the community with transparency and care.', 'Barangay 420, Sampaloc, Manila', 'Monday to Friday, 8:00 AM - 5:00 PM');

-- 3. Real census-sourced about text (was migration 004) - a plain UPDATE
--    is already safe to repeat, it just sets the same value again
UPDATE barangay_info
SET about_text = 'Barangay 420 is located in the Sampaloc district of Manila, within the National Capital Region. According to the 2020 census, the barangay is home to 3,134 residents - up from 2,811 in the 2015 count of roughly 643 households, an average of about 4 to 5 people per household. The population has grown steadily since 1990, when it stood at 2,763.'
WHERE info_id = 1;

-- 4. barangay_officials table (was migration 005)
CREATE TABLE IF NOT EXISTS barangay_officials (
    official_id   INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(150) NOT NULL,
    position      VARCHAR(50) NOT NULL,
    display_order INT NOT NULL DEFAULT 100,
    photo_path    VARCHAR(255) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 5. Seed officials ONLY if the table is currently empty - prevents
--    ending up with 20 officials if this has already run once
SET @official_count = (SELECT COUNT(*) FROM barangay_officials);
SET @sql = IF(@official_count = 0,
    "INSERT INTO barangay_officials (full_name, position, display_order) VALUES
     ('Hon. Michael John M. Regala', 'Punong Barangay', 1),
     ('Rudylinda P. Domingo', 'Secretary', 2),
     ('Reynaldo T. Delos Reyes', 'Treasurer', 3),
     ('Rodrigo C. Bernas Jr.', 'Kagawad', 10),
     ('Lailane S. Castelo', 'Kagawad', 11),
     ('Hones L. Bautista', 'Kagawad', 12),
     ('Florida R. Ami', 'Kagawad', 13),
     ('Leo R. Austria', 'Kagawad', 14),
     ('Randolph Malana', 'Kagawad', 15),
     ('Melvin A. Macanip', 'Kagawad', 16)",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Drop the now-redundant single punong_barangay field (was migration 005)
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barangay_info' AND COLUMN_NAME = 'punong_barangay'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE barangay_info DROP COLUMN punong_barangay',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Employed / Student eligibility flags on program criteria (was
--    migration 011) - the eligibility engine and the Programs page
--    reference these columns, so a fresh install must end up with them
--    too. Both steps below are the same information_schema guard pattern
--    as step 1, so re-running is safe.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_criteria' AND COLUMN_NAME = 'requires_employed'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE program_criteria ADD COLUMN requires_employed BOOLEAN NOT NULL DEFAULT FALSE AFTER requires_unemployed',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_criteria' AND COLUMN_NAME = 'requires_student'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE program_criteria ADD COLUMN requires_student BOOLEAN NOT NULL DEFAULT FALSE AFTER requires_employed',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. program_documents table (was migration 011) - the Programs page
--    saves required documents into it. CREATE TABLE IF NOT EXISTS is
--    already safe to repeat.
CREATE TABLE IF NOT EXISTS program_documents (
    document_id    INT AUTO_INCREMENT PRIMARY KEY,
    program_id     INT NOT NULL,
    label          VARCHAR(150) NOT NULL,
    files_required INT NOT NULL DEFAULT 1,
    display_order  INT NOT NULL DEFAULT 0,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- Everything below was added to also cover migrations 006-021 plus the
-- schema-only objects that no migration ever captured. Same guard pattern
-- throughout, so re-running remains safe.
-- =====================================================================

-- 9. event_photos table (was migration 006)
CREATE TABLE IF NOT EXISTS event_photos (
    photo_id       INT AUTO_INCREMENT PRIMARY KEY,
    event_id       INT NOT NULL,
    photo_path     VARCHAR(255) NOT NULL COMMENT 'Relative to resident/assets/events/ - public',
    display_order  INT NOT NULL DEFAULT 0,
    uploaded_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    INDEX idx_event_photos_event (event_id)
) ENGINE=InnoDB;

-- 10. users.photo_path (was migration 007_profile_photos)
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'photo_path'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) NULL AFTER contact_number',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 11. beneficiaries application columns (was migration 007_benefit_applications)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'applicant_full_name');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN applicant_full_name VARCHAR(150) NULL AFTER claim_status',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'applicant_contact_number');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN applicant_contact_number VARCHAR(20) NULL AFTER applicant_full_name',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'id_document_path');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN id_document_path VARCHAR(255) NULL AFTER applicant_contact_number',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'applied_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN applied_at DATETIME NULL AFTER id_document_path',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'reviewed_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN reviewed_by INT NULL AFTER applied_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'reviewed_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries'
      AND COLUMN_NAME = 'reviewed_by' AND REFERENCED_TABLE_NAME = 'users');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE beneficiaries ADD CONSTRAINT fk_beneficiaries_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 12. household_members.photo_path (was migration 008_member_photos)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'photo_path');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE household_members ADD COLUMN photo_path VARCHAR(255) NULL AFTER occupation',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 13. beneficiaries rejection / seen columns (was migration 008_benefit_notifications)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'rejection_reason');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN rejection_reason TEXT NULL AFTER reviewed_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'resident_seen_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN resident_seen_at DATETIME NULL AFTER rejection_reason',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 14. residents split-address columns (was migration 009_address_parts)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'house_number');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE residents ADD COLUMN house_number VARCHAR(50) NULL AFTER address',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'street');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE residents ADD COLUMN street VARCHAR(150) NULL AFTER house_number',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'barangay');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE residents ADD COLUMN barangay VARCHAR(100) NULL AFTER street',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'city');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE residents ADD COLUMN city VARCHAR(100) NULL AFTER barangay',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 15. households house_number / barangay (was migration 009_address_parts).
--     No AFTER clause: a legacy table may not have every neighbor column.
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'house_number');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE households ADD COLUMN house_number VARCHAR(50) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'barangay');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE households ADD COLUMN barangay VARCHAR(100) NULL DEFAULT 'Barangay 420'",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 16. beneficiaries: approved vs pickup separate + 'retracted' (was migrations
--     009_approved_beneficiaries and 016). 'existing' (migration 024 / step 41)
--     is listed in BOTH shapes below: step 41 runs later in this file, so a
--     database that already carries 'existing' rows must not pass through an
--     enum that omits the value - MySQL strict mode would error and non-strict
--     mode would blank those rows to ''.
ALTER TABLE beneficiaries
    MODIFY COLUMN claim_status ENUM('qualified','pending','claimed','approved','rejected','retracted','existing') NOT NULL DEFAULT 'qualified';

UPDATE beneficiaries SET claim_status = 'approved' WHERE claim_status = 'claimed';

ALTER TABLE beneficiaries
    MODIFY COLUMN claim_status ENUM('qualified','pending','approved','rejected','retracted','existing') NOT NULL DEFAULT 'qualified';

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'pickup_status');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE beneficiaries ADD COLUMN pickup_status ENUM('unclaimed','claimed') NOT NULL DEFAULT 'unclaimed' AFTER claim_status",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 17. household_members Employed / Student flags (was migration 010)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'is_employed');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE household_members ADD COLUMN is_employed BOOLEAN NOT NULL DEFAULT FALSE AFTER is_unemployed',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND COLUMN_NAME = 'is_student');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE household_members ADD COLUMN is_student BOOLEAN NOT NULL DEFAULT FALSE AFTER is_employed',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 18. activity_logs.category, final enum incl. 'validate' (was 014 + 017)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND COLUMN_NAME = 'category');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE activity_logs ADD COLUMN category ENUM('create','read','update','delete','validate') NULL DEFAULT NULL AFTER action",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 19. benefit_documents table (was migration 016)
CREATE TABLE IF NOT EXISTS benefit_documents (
    benefit_document_id INT AUTO_INCREMENT PRIMARY KEY,
    beneficiary_id      INT NOT NULL,
    label               VARCHAR(150) NOT NULL,
    file_path           VARCHAR(255) NOT NULL,
    uploaded_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(beneficiary_id) ON DELETE CASCADE,
    INDEX idx_benefit_documents_beneficiary (beneficiary_id)
) ENGINE=InnoDB;

-- 20. validation_flags table (was migration 017)
CREATE TABLE IF NOT EXISTS validation_flags (
    flag_id        INT AUTO_INCREMENT PRIMARY KEY,
    check_code     VARCHAR(50)  NOT NULL,
    severity       ENUM('error','warning','possible_duplicate') NOT NULL,
    message        TEXT         NOT NULL,
    scope          ENUM('household','member') NOT NULL,
    household_id   INT NULL,
    member_id      INT NULL,
    status         ENUM('open','resolved') NOT NULL DEFAULT 'open',
    resolved_by    INT NULL,
    resolved_at    DATETIME NULL,
    resolved_note  VARCHAR(500) NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES households(household_id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES household_members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uk_validation_check (check_code, household_id, member_id),
    INDEX idx_validation_status (status, severity),
    INDEX idx_validation_household (household_id)
) ENGINE=InnoDB;

-- 21. income columns (was migrations 012 / 016)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'monthly_income');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE households ADD COLUMN monthly_income DECIMAL(10,2) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_criteria' AND COLUMN_NAME = 'max_income');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE program_criteria ADD COLUMN max_income DECIMAL(10,2) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 22. program_criteria.min_income (was migration 018)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_criteria' AND COLUMN_NAME = 'min_income');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE program_criteria ADD COLUMN min_income DECIMAL(10,2) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 23. programs validity window as DATETIME (was migrations 019 + 020)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'start_date');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN start_date DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'end_date');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN end_date DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- If they were added as DATE by an older run, widen them to DATETIME.
SET @is_date = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'start_date' AND COLUMN_TYPE = 'date');
SET @sql = IF(@is_date = 1, 'ALTER TABLE programs MODIFY COLUMN start_date DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @is_date = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'end_date' AND COLUMN_TYPE = 'date');
SET @sql = IF(@is_date = 1, 'ALTER TABLE programs MODIFY COLUMN end_date DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE programs
SET end_date = DATE_ADD(end_date, INTERVAL 24 HOUR) - INTERVAL 1 SECOND
WHERE end_date IS NOT NULL AND TIME(end_date) = '00:00:00';

-- 24. programs detail columns (was migration 021)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'category');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN category VARCHAR(100) NULL AFTER program_type',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'provider');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN provider VARCHAR(255) NULL AFTER category',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'application_deadline');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN application_deadline DATETIME NULL AFTER end_date',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'release_period');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN release_period VARCHAR(255) NULL AFTER application_deadline',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'slot_limit');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE programs ADD COLUMN slot_limit INT NULL AFTER release_period',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE programs SET category = 'Senior Citizen Assistance' WHERE category IS NULL AND program_type = 'Senior';
UPDATE programs SET category = 'PWD Assistance' WHERE category IS NULL AND program_type = 'PWD';

-- 25. login lockout columns - schema.sql only, no migration ever added them,
--     yet both login pages SELECT/UPDATE them (was migration 022)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'failed_login_attempts');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'locked_until');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN locked_until DATETIME NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 26. residents.household_member_id (schema.sql only before migration 022)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'household_member_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE residents ADD COLUMN household_member_id INT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents'
      AND COLUMN_NAME = 'household_member_id' AND REFERENCED_TABLE_NAME = 'household_members');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE residents ADD CONSTRAINT fk_residents_household_member FOREIGN KEY (household_member_id) REFERENCES household_members(member_id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 27. Performance indexes (was migration 022). One guarded ADD INDEX each.
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND INDEX_NAME = 'idx_events_date');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE events ADD INDEX idx_events_date (event_date)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND INDEX_NAME = 'idx_activity_logs_created');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE activity_logs ADD INDEX idx_activity_logs_created (created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND INDEX_NAME = 'idx_activity_logs_category');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE activity_logs ADD INDEX idx_activity_logs_category (category)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND INDEX_NAME = 'idx_household_members_household_archived');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE household_members ADD INDEX idx_household_members_household_archived (household_id, is_archived)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_members' AND INDEX_NAME = 'idx_household_members_linked_user');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE household_members ADD INDEX idx_household_members_linked_user (linked_user_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND INDEX_NAME = 'idx_beneficiaries_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE beneficiaries ADD INDEX idx_beneficiaries_status (claim_status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND INDEX_NAME = 'idx_beneficiaries_pickup');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE beneficiaries ADD INDEX idx_beneficiaries_pickup (pickup_status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND INDEX_NAME = 'idx_households_street_house');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE households ADD INDEX idx_households_street_house (street, house_number)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 28. backup_logs: keep the audit trail when a user is deleted (was migration 022)
SET @nullable = (SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_logs' AND COLUMN_NAME = 'initiated_by');
SET @sql = IF(@nullable = 'NO', 'ALTER TABLE backup_logs MODIFY initiated_by INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
    JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
      ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
    WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.TABLE_NAME = 'backup_logs'
      AND kcu.COLUMN_NAME = 'initiated_by' AND kcu.REFERENCED_TABLE_NAME = 'users'
      AND rc.DELETE_RULE = 'CASCADE'
    LIMIT 1
);
SET @sql = IF(@fk_name IS NOT NULL, CONCAT('ALTER TABLE backup_logs DROP FOREIGN KEY `', @fk_name, '`'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_logs'
      AND COLUMN_NAME = 'initiated_by' AND REFERENCED_TABLE_NAME = 'users');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE backup_logs ADD CONSTRAINT fk_backup_logs_user FOREIGN KEY (initiated_by) REFERENCES users(user_id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 29. households.no_of_members -> NOT NULL DEFAULT 0 (was migration 022)
SET @nullable = (SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'no_of_members');
UPDATE households SET no_of_members = 0 WHERE no_of_members IS NULL;
SET @sql = IF(@nullable = 'YES', 'ALTER TABLE households MODIFY no_of_members INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 30. stale legacy households.barangay_zone column (was migration 013)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'barangay_zone');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE households DROP COLUMN barangay_zone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 31. programs.name UNIQUE (schema.sql uk_programs_name; was migration 022)
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND INDEX_NAME = 'uk_programs_name');
SET @dupes = (SELECT COUNT(*) FROM
    (SELECT name FROM programs GROUP BY name HAVING COUNT(*) > 1) AS dup_names);
SET @sql = IF(@idx_exists = 0 AND @dupes = 0,
    'ALTER TABLE programs ADD UNIQUE KEY uk_programs_name (name)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 32. users.role gains 'secretary' (was migration 012)
SET @type = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role');
SET @sql = IF(@type IS NOT NULL AND @type NOT LIKE '%secretary%',
    "ALTER TABLE users MODIFY role ENUM('resident','secretary','staff','admin') NOT NULL",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 33. programs.application_mode (was migration 024)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'application_mode');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE programs ADD COLUMN application_mode ENUM('application_required','existing_beneficiary') NOT NULL DEFAULT 'application_required' AFTER program_type",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 34. password_reset_tokens table (was migration 023)
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

-- 35. beneficiary_imports table (was migration 024)
CREATE TABLE IF NOT EXISTS beneficiary_imports (
    import_id         INT AUTO_INCREMENT PRIMARY KEY,
    program_id        INT NOT NULL,
    file_name         VARCHAR(255) NOT NULL,
    file_type         VARCHAR(10) NOT NULL COMMENT 'csv or xlsx',
    total_rows        INT NOT NULL DEFAULT 0,
    matched_rows      INT NOT NULL DEFAULT 0,
    unmatched_rows    INT NOT NULL DEFAULT 0,
    duplicate_rows    INT NOT NULL DEFAULT 0,
    error_rows        INT NOT NULL DEFAULT 0,
    removed_rows      INT NOT NULL DEFAULT 0,
    status            ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    imported_by       INT NULL,
    imported_at       DATETIME NULL,
    notes             TEXT NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE,
    FOREIGN KEY (imported_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_beneficiary_imports_program (program_id)
) ENGINE=InnoDB;

-- 36. beneficiary_import_rows table (was migration 024)
CREATE TABLE IF NOT EXISTS beneficiary_import_rows (
    row_id            INT AUTO_INCREMENT PRIMARY KEY,
    import_id         INT NOT NULL,
    row_number        INT NOT NULL,
    raw_data          JSON NOT NULL,
    normalized_name   VARCHAR(200) NULL,
    normalized_birthdate DATE NULL,
    normalized_address VARCHAR(255) NULL,
    zone              VARCHAR(50) NULL,
    match_status      ENUM('matched','needs_review','not_found','duplicate_in_file','already_beneficiary','error')
                      NOT NULL DEFAULT 'error',
    matched_member_id INT NULL,
    confidence_score  DECIMAL(5,2) NULL,
    review_notes      VARCHAR(500) NULL,
    FOREIGN KEY (import_id) REFERENCES beneficiary_imports(import_id) ON DELETE CASCADE,
    FOREIGN KEY (matched_member_id) REFERENCES household_members(member_id) ON DELETE SET NULL,
    INDEX idx_import_rows_import (import_id),
    INDEX idx_import_rows_status (match_status)
) ENGINE=InnoDB;

-- 37. beneficiaries.source_type (was migration 024)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'source_type');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE beneficiaries ADD COLUMN source_type ENUM('eligibility_engine','existing_beneficiary_list') NOT NULL DEFAULT 'eligibility_engine' AFTER claim_status",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 38. beneficiaries.import_batch_id (was migration 024)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'import_batch_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN import_batch_id INT NULL AFTER source_type',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 39. FK beneficiaries.import_batch_id -> beneficiary_imports.import_id (was migration 024)
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries'
      AND COLUMN_NAME = 'import_batch_id' AND REFERENCED_TABLE_NAME = 'beneficiary_imports');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE beneficiaries ADD CONSTRAINT fk_beneficiaries_import_batch FOREIGN KEY (import_batch_id) REFERENCES beneficiary_imports(import_id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 40. beneficiaries.original_status (was migration 024)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'original_status');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN original_status VARCHAR(50) NULL COMMENT "Status from the imported official list, if available" AFTER import_batch_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 41. claim_status gains 'existing' (was migration 024)
SET @type = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'claim_status');
SET @sql = IF(@type IS NOT NULL AND @type NOT LIKE '%existing%',
    "ALTER TABLE beneficiaries MODIFY COLUMN claim_status ENUM('qualified','pending','approved','rejected','retracted','existing') NOT NULL DEFAULT 'qualified'",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 42. beneficiaries.qualification_snapshot (was migration 025)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'qualification_snapshot');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN qualification_snapshot JSON NULL COMMENT "Immutable criteria + resident/household values that first qualified this member" AFTER qualified_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 43. system_meta table (was migration 025)
CREATE TABLE IF NOT EXISTS system_meta (
    meta_key     VARCHAR(64)  NOT NULL PRIMARY KEY,
    meta_value   VARCHAR(255) NULL,
    updated_at   DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 44. Two program types + import_existing_list flag (was migration 026).
--     application_mode drops 'existing_beneficiary' for 'no_application_required',
--     and a separate import_existing_list boolean becomes the Yes/No import
--     option under BOTH modes. Guarded so a re-run never flips an import flag
--     an admin deliberately created as FALSE.
SET @flag_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'import_existing_list');
SET @sql = IF(@flag_exists = 0,
    "ALTER TABLE programs ADD COLUMN import_existing_list BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE = admins may import an official beneficiary list into this program (admin/import-beneficiaries.php). Available under both application modes.' AFTER application_mode",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ctype = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'application_mode');
SET @sql = IF(@ctype LIKE '%no_application_required%',
    'SELECT 1',
    "ALTER TABLE programs MODIFY COLUMN application_mode ENUM('application_required','existing_beneficiary','no_application_required') NOT NULL DEFAULT 'application_required' COMMENT 'application_required = residents apply through the portal; no_application_required = the eligibility engine enrolls matching residents automatically (no application, no admin approval)'");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ctype = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'application_mode');
SET @sql = IF(@ctype LIKE '%existing_beneficiary%',
    "UPDATE programs SET application_mode = 'no_application_required' WHERE application_mode = 'existing_beneficiary'",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@ctype LIKE '%existing_beneficiary%',
    "ALTER TABLE programs MODIFY COLUMN application_mode ENUM('application_required','no_application_required') NOT NULL DEFAULT 'application_required' COMMENT 'application_required = residents apply through the portal; no_application_required = the eligibility engine enrolls matching residents automatically (no application, no admin approval)'",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@flag_exists = 0,
    "UPDATE programs SET import_existing_list = TRUE WHERE application_mode IN ('existing_beneficiary', 'no_application_required')",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Done. Verify everything with database/verify_current_state.sql - every row
-- should say OK. Quick spot checks:
-- SHOW COLUMNS FROM users LIKE 'failed_login_attempts';
-- SHOW COLUMNS FROM household_members LIKE 'is_archived';           -- should return 1 row
-- SHOW COLUMNS FROM household_members LIKE 'is_employed';           -- should return 1 row
-- SELECT COUNT(*) FROM barangay_officials;                          -- should return 10
-- SHOW COLUMNS FROM barangay_info LIKE 'punong_barangay';           -- should return 0 rows
-- SELECT COUNT(*) FROM information_schema.tables
--   WHERE table_schema = DATABASE() AND table_name IN
--   ('event_photos','benefit_documents','validation_flags','program_documents');  -- should return 4
-- SELECT COUNT(*) FROM information_schema.tables
--   WHERE table_schema = DATABASE() AND table_name IN
--   ('password_reset_tokens','beneficiary_imports','beneficiary_import_rows','system_meta');  -- should return 4
-- SHOW COLUMNS FROM programs LIKE 'application_mode';                       -- should return 1 row
-- SHOW COLUMNS FROM programs LIKE 'import_existing_list';                   -- should return 1 row
-- SELECT COLUMN_TYPE FROM information_schema.columns
--   WHERE table_schema = DATABASE() AND table_name = 'programs' AND column_name = 'application_mode';  -- no 'existing_beneficiary'
-- SHOW COLUMNS FROM beneficiaries LIKE 'source_type';                       -- should return 1 row
-- SELECT COLUMN_TYPE FROM information_schema.columns
--   WHERE table_schema = DATABASE() AND table_name = 'beneficiaries' AND column_name = 'claim_status';  -- should include 'existing'
-- SELECT COLUMN_TYPE FROM information_schema.columns
--   WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role';                 -- should include 'secretary'

