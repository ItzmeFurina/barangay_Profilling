-- Migration 024: Application mode + Existing Beneficiary Import
-- Adds support for importing pre-existing official beneficiary lists.
-- Safe to re-run: all steps use INFORMATION_SCHEMA guards.

-- 1. programs.application_mode
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'application_mode'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE programs ADD COLUMN application_mode ENUM('application_required','existing_beneficiary') NOT NULL DEFAULT 'application_required' AFTER program_type",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. beneficiary_imports table
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

-- 3. beneficiary_import_rows table
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

-- 4. beneficiaries.source_type
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'source_type'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE beneficiaries ADD COLUMN source_type ENUM('eligibility_engine','existing_beneficiary_list') NOT NULL DEFAULT 'eligibility_engine' AFTER claim_status",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. beneficiaries.import_batch_id
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'import_batch_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN import_batch_id INT NULL AFTER source_type',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries'
      AND COLUMN_NAME = 'import_batch_id' AND REFERENCED_TABLE_NAME = 'beneficiary_imports');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE beneficiaries ADD CONSTRAINT fk_beneficiaries_import_batch FOREIGN KEY (import_batch_id) REFERENCES beneficiary_imports(import_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. beneficiaries.original_status (preserves the status from the imported list)
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'original_status'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE beneficiaries ADD COLUMN original_status VARCHAR(50) NULL COMMENT "Status from the imported official list, if available" AFTER import_batch_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Add 'existing' to claim_status enum
ALTER TABLE beneficiaries
    MODIFY COLUMN claim_status ENUM('qualified','pending','approved','rejected','retracted','existing')
    NOT NULL DEFAULT 'qualified';
