-- Migration 016: eligibility sync + program-document flow + income criteria.
--
-- 1. beneficiaries.claim_status gains 'retracted' - a NEW, engine-only state
--    set by shared/eligibility.php when a member stops matching an active
--    program (e.g. an admin edits a birthdate so a senior drops below 60).
--    It is deliberately distinct from the admin's manual 'rejected', so
--    the auto-sync can restore a row to 'qualified' later without ever
--    overwriting a human decision.
-- 2. New benefit_documents table - one row per uploaded application
--    document, because a program can require several documents and the old
--    single id_document_path column on beneficiaries can only hold one.
-- 3. Self-healing guards for the income columns (already present on most
--    installs via migration_012 / schema.sql).
--
-- In phpMyAdmin: barangay420 database, SQL tab, paste this WHOLE file in
-- one go, click Go once. Every step checks first, so it is safe to run on
-- both a fresh schema.sql import and an already-migrated database.

-- 1. Widen the claim_status enum so 'retracted' can exist.
SET @enum_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries'
      AND COLUMN_NAME = 'claim_status' AND COLUMN_TYPE LIKE '%retracted%'
);
SET @sql = IF(@enum_exists = 0,
    "ALTER TABLE beneficiaries
     MODIFY COLUMN claim_status ENUM('qualified','pending','approved','rejected','retracted') NOT NULL DEFAULT 'qualified'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. benefit_documents: every uploaded application document.
CREATE TABLE IF NOT EXISTS benefit_documents (
    benefit_document_id INT AUTO_INCREMENT PRIMARY KEY,
    beneficiary_id      INT NOT NULL,
    label               VARCHAR(150) NOT NULL COMMENT 'Document label as configured on the program (program_documents.label)',
    file_path           VARCHAR(255) NOT NULL COMMENT 'Relative to uploads/ - private, served only through admin/view-benefit-document.php',
    uploaded_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(beneficiary_id) ON DELETE CASCADE,
    INDEX idx_benefit_documents_beneficiary (beneficiary_id)
) ENGINE=InnoDB;

-- 3. Income columns, made self-healing for any copy of the database.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'monthly_income'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE households ADD COLUMN monthly_income DECIMAL(10,2) NULL COMMENT ''Estimated combined monthly household income - read by shared/eligibility.php against program_criteria.max_income''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'program_criteria' AND COLUMN_NAME = 'max_income'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE program_criteria ADD COLUMN max_income DECIMAL(10,2) NULL COMMENT ''Maximum monthly household income qualifying for the program - checked by shared/eligibility.php''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verify with:
-- SHOW COLUMNS FROM beneficiaries LIKE 'claim_status';   -- enum list ends in 'retracted'
-- SELECT COUNT(*) FROM information_schema.tables
--   WHERE table_schema = DATABASE() AND table_name = 'benefit_documents';  -- should return 1