-- Barangay 420 Program Beneficiary Selection System
-- Database schema
-- Applies the Phase 1 fixes (see Phase1_Requirements_Analysis.md) plus a
-- household-members redesign based on the actual paper census form
-- ("Records of Barangay Inhabitants by Household"):
--   - household_members captures EVERY person in a household, not just
--     whoever has a login account
--   - beneficiaries ties to the specific member who qualifies, since
--     eligibility belongs to a person, not necessarily the account holder
--   - age is never stored - it is calculated from birthdate whenever
--     needed, so it can never go stale the way a handwritten form does

CREATE DATABASE IF NOT EXISTS barangay420 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barangay420;

CREATE TABLE users (
    user_id               INT AUTO_INCREMENT PRIMARY KEY,
    full_name             VARCHAR(150)  NOT NULL,
    email                 VARCHAR(150)  NOT NULL UNIQUE,
    email_verified_at     DATETIME NULL,
    email_verification_required BOOLEAN NOT NULL DEFAULT TRUE,
    contact_number        VARCHAR(20)   NOT NULL,
    photo_path            VARCHAR(255)  NULL COMMENT 'Profile picture, relative to resident/assets/avatars/ - same public pattern as barangay_officials.photo_path',
    password_hash         VARCHAR(255)  NOT NULL,
    role                  ENUM('resident','secretary','staff','admin') NOT NULL,
    account_status        ENUM('pending','active','rejected','archived') NOT NULL DEFAULT 'pending',
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until          DATETIME NULL,
    created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_status (role, account_status)
) ENGINE=InnoDB;

-- Single-row table: general barangay info that Staff/Admin maintain
-- and residents see on their home page. Officials live in their own
-- table below, not as columns here - a barangay has many officials with
-- different roles, which does not fit into flat columns on one row.
CREATE TABLE barangay_info (
    info_id          INT PRIMARY KEY,
    tagline          VARCHAR(255) NULL,
    about_text       TEXT NULL,
    address          VARCHAR(255) NULL,
    office_hours     VARCHAR(255) NULL,
    contact_number   VARCHAR(20) NULL,
    contact_email    VARCHAR(150) NULL,
    updated_by       INT NULL,
    updated_at       DATETIME NULL,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO barangay_info (info_id, tagline, about_text, address, office_hours)
VALUES (
    1,
    'Serving the community with transparency and care.',
    'Barangay 420 is located in the Sampaloc district of Manila, within the National Capital Region. According to the 2020 census, the barangay is home to 3,134 residents - up from 2,811 in the 2015 count of roughly 643 households, an average of about 4 to 5 people per household. The population has grown steadily since 1990, when it stood at 2,763.',
    'Barangay 420, Sampaloc, Manila',
    'Monday to Friday, 8:00 AM - 5:00 PM'
);

CREATE TABLE barangay_officials (
    official_id   INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(150) NOT NULL,
    position      VARCHAR(50) NOT NULL,
    display_order INT NOT NULL DEFAULT 100,
    photo_path    VARCHAR(255) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO barangay_officials (full_name, position, display_order) VALUES
('Hon. Michael John M. Regala', 'Punong Barangay', 1),
('Rudylinda P. Domingo', 'Secretary', 2),
('Reynaldo T. Delos Reyes', 'Treasurer', 3),
('Rodrigo C. Bernas Jr.', 'Kagawad', 10),
('Lailane S. Castelo', 'Kagawad', 11),
('Hones L. Bautista', 'Kagawad', 12),
('Florida B. Ami', 'Kagawad', 13),
('Leo R. Austria', 'Kagawad', 14),
('Randolph Malana', 'Kagawad', 15),
('Melvin A. Macanip', 'Kagawad', 16);


CREATE TABLE households (
    household_id    INT AUTO_INCREMENT PRIMARY KEY,
    house_number    VARCHAR(50) NULL COMMENT 'House/Building/Unit number',
    street          VARCHAR(150) NOT NULL,
    barangay        VARCHAR(100) NULL DEFAULT 'Barangay 420',
    city            VARCHAR(100) NOT NULL DEFAULT 'Manila',
    old_address     VARCHAR(255) NULL COMMENT 'Matches the paper forms OLD ADDRESS field, for households that relocated',
    no_of_members   INT NOT NULL DEFAULT 0,
    monthly_income  DECIMAL(10,2) NULL COMMENT 'Estimated combined monthly household income - captured on the census/profile forms, read by shared/eligibility.php against program_criteria.max_income',
    INDEX idx_households_street_house (street, house_number)
) ENGINE=InnoDB;

-- Mirrors the actual "Records of Barangay Inhabitants by Household" form.
-- Every person in a household gets a row here, whether or not they ever
-- create their own login account - a census has to count everyone, not
-- just whoever is tech-savvy enough to register online.
CREATE TABLE household_members (
    member_id           INT AUTO_INCREMENT PRIMARY KEY,
    household_id         INT NOT NULL,
    last_name            VARCHAR(100) NOT NULL,
    first_name           VARCHAR(100) NOT NULL,
    middle_name          VARCHAR(100) NULL,
    suffix               VARCHAR(10) NULL COMMENT 'Jr, Sr, III etc - EXT on the paper form',
    place_of_birth       VARCHAR(150) NULL,
    birthdate            DATE NULL COMMENT 'AGE is deliberately not stored - calculated from this instead',
    sex                  ENUM('Male','Female') NULL,
    civil_status         VARCHAR(30) NULL,
    citizenship          VARCHAR(50) NOT NULL DEFAULT 'Filipino',
    occupation           VARCHAR(100) NULL,
    photo_path           VARCHAR(255) NULL COMMENT 'Profile photo, relative to resident/assets/avatars/ - same public pattern as barangay_officials.photo_path',
    is_household_head    BOOLEAN NOT NULL DEFAULT FALSE,
    is_pwd               BOOLEAN NOT NULL DEFAULT FALSE,
    is_solo_parent       BOOLEAN NOT NULL DEFAULT FALSE,
    is_ofw               BOOLEAN NOT NULL DEFAULT FALSE,
    is_osy               BOOLEAN NOT NULL DEFAULT FALSE,
    is_ip                BOOLEAN NOT NULL DEFAULT FALSE,
    is_unemployed        BOOLEAN NOT NULL DEFAULT FALSE,
    is_employed           BOOLEAN NOT NULL DEFAULT FALSE,
    is_student             BOOLEAN NOT NULL DEFAULT FALSE,
    is_archived          BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Soft-delete: excluded from eligibility checks and resident-facing views once archived',
    linked_user_id        INT NULL COMMENT 'Set if this member also has their own login account',
    created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES households(household_id) ON DELETE CASCADE,
    FOREIGN KEY (linked_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_household_members_household (household_id),
    INDEX idx_household_members_household_archived (household_id, is_archived),
    INDEX idx_household_members_linked_user (linked_user_id)
) ENGINE=InnoDB;

CREATE TABLE residents (
    resident_id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id                INT NOT NULL UNIQUE,
    address                VARCHAR(255) NULL COMMENT 'Legacy free-text address kept for old rows only',
    house_number           VARCHAR(50) NULL COMMENT 'House/Building/Unit number from registration',
    street                 VARCHAR(150) NULL,
    barangay               VARCHAR(100) NULL,
    city                   VARCHAR(100) NULL,
    household_id           INT NULL,
    household_member_id    INT NULL COMMENT 'Which row in household_members corresponds to this account holder',
    census_completed       BOOLEAN NOT NULL DEFAULT FALSE,
    census_completed_at    DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (household_id) REFERENCES households(household_id) ON DELETE SET NULL,
    FOREIGN KEY (household_member_id) REFERENCES household_members(member_id) ON DELETE SET NULL,
    INDEX idx_residents_census (census_completed)
) ENGINE=InnoDB;

CREATE TABLE barangay_staff (
    staff_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id  INT NOT NULL UNIQUE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE verification_documents (
    document_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL,
    document_type     VARCHAR(50) NOT NULL DEFAULT 'Barangay ID',
    file_path         VARCHAR(255) NOT NULL,
    uploaded_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    verified_status   ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    verified_by       INT NULL,
    verified_at       DATETIME NULL,
    rejection_reason  VARCHAR(255) NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_verification_status (verified_status)
) ENGINE=InnoDB;

CREATE TABLE programs (
    program_id          INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    description         TEXT,
    program_type        VARCHAR(50),
    application_mode    ENUM('application_required','no_application_required') NOT NULL DEFAULT 'application_required' COMMENT 'application_required = residents apply through the portal; no_application_required = the eligibility engine enrolls matching residents automatically (no application, no admin approval)',
    import_existing_list BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE = admins may import an official beneficiary list into this program (admin/import-beneficiaries.php). Available under both application modes.',
    category            VARCHAR(100) NULL COMMENT 'Program/benefit category - one of the fixed dropdown choices, or a custom label when "Other" is picked',
    provider            VARCHAR(255) NULL COMMENT 'Source/provider of the program - organization, government agency, office, NGO, sponsor, etc.',
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
    start_date          DATETIME NULL COMMENT 'First moment the program is effective - set on the admin Programs form, checked by shared/eligibility.php (NULL = open-ended)',
    end_date            DATETIME NULL COMMENT 'Last moment the program is effective - set on the admin Programs form; when it passes the program is auto-archived',
    application_deadline DATETIME NULL COMMENT 'Last moment residents may apply; NULL = program end_date is treated as the deadline',
    release_period      VARCHAR(255) NULL COMMENT 'Benefit release date/period - free text, e.g. "Oct 15, 2026" or "Every 15th of the month"',
    slot_limit          INT NULL COMMENT 'Available slots / beneficiary limit; NULL = unlimited slots',
    created_by          INT NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uk_programs_name (name)
) ENGINE=InnoDB;

CREATE TABLE program_criteria (
    criteria_id           INT AUTO_INCREMENT PRIMARY KEY,
    program_id            INT NOT NULL,
    min_age               INT NULL,
    max_age               INT NULL,
    requires_pwd          BOOLEAN NOT NULL DEFAULT FALSE,
    requires_solo_parent  BOOLEAN NOT NULL DEFAULT FALSE,
    requires_ofw          BOOLEAN NOT NULL DEFAULT FALSE,
    requires_ip           BOOLEAN NOT NULL DEFAULT FALSE,
    requires_osy          BOOLEAN NOT NULL DEFAULT FALSE,
    requires_unemployed   BOOLEAN NOT NULL DEFAULT FALSE,
    requires_employed     BOOLEAN NOT NULL DEFAULT FALSE,
    requires_student      BOOLEAN NOT NULL DEFAULT FALSE,
    min_household_size    INT NULL,
    max_income            DECIMAL(10,2) NULL COMMENT 'Maximum monthly household income qualifying for the program - edited on the admin Programs form, checked by shared/eligibility.php',
    min_income            DECIMAL(10,2) NULL COMMENT 'Minimum monthly household income qualifying for the program - edited on the admin Programs form, checked by shared/eligibility.php',
    notes                 VARCHAR(255) NULL,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Documents the admin configures as required uploads when a resident
-- applies for a program (e.g. "Senior Citizen ID" for the Senior Benefit).
-- One program, several required documents - hence its own table.
CREATE TABLE program_documents (
    document_id    INT AUTO_INCREMENT PRIMARY KEY,
    program_id     INT NOT NULL,
    label          VARCHAR(150) NOT NULL,
    files_required INT NOT NULL DEFAULT 1,
    display_order  INT NOT NULL DEFAULT 0,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per uploaded beneficiary list (admin/import-beneficiaries.php),
-- used when a program's import_existing_list flag is TRUE. The
-- *_rows table holds the per-row matching outcome, so an import can always
-- be reviewed or rolled back after the fact. Defined before beneficiaries
-- below because beneficiaries.import_batch_id references import_id.
CREATE TABLE beneficiary_imports (
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

CREATE TABLE beneficiary_import_rows (
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

-- Tied to the specific household member who qualifies - not necessarily
-- the account holder. A resident might register online on behalf of the
-- household, but it could be their PWD grandparent or solo-parent
-- sibling who actually qualifies for a given program.
-- claim_status doubles as the application workflow: 'qualified' means the
-- rule engine matched them but they haven't applied yet, 'pending' means
-- they submitted the application form below, 'approved'/'rejected' are
-- the admin's decision on that application. pickup_status is a
-- separate, later step - only meaningful once approved - tracking
-- whether the resident has actually picked up the benefit in person.
CREATE TABLE beneficiaries (
    beneficiary_id  INT AUTO_INCREMENT PRIMARY KEY,
    member_id        INT NOT NULL,
    program_id        INT NOT NULL,
    claim_status      ENUM('qualified','pending','approved','rejected','retracted','existing') NOT NULL DEFAULT 'qualified' COMMENT "'existing' = beneficiary with no application: imported from an official list (source_type = existing_beneficiary_list) or auto-enrolled by the engine for a no-application-required program (source_type = eligibility_engine)",
    source_type       ENUM('eligibility_engine','existing_beneficiary_list') NOT NULL DEFAULT 'eligibility_engine' COMMENT 'Whether this row was produced by the eligibility sweep or by a beneficiary-list import',
    import_batch_id   INT NULL COMMENT 'Which beneficiary_imports batch created this row, when imported',
    original_status   VARCHAR(50) NULL COMMENT 'Status from the imported official list, if available',
    pickup_status     ENUM('unclaimed','claimed') NOT NULL DEFAULT 'unclaimed',
    applicant_full_name       VARCHAR(150) NULL,
    applicant_contact_number  VARCHAR(20)  NULL,
    id_document_path          VARCHAR(255) NULL COMMENT 'Senior/PWD ID upload - private, served only through admin/view-benefit-document.php',
    applied_at                DATETIME NULL,
    reviewed_by               INT NULL,
    reviewed_at                DATETIME NULL,
    rejection_reason          TEXT NULL COMMENT 'Set by the admin when claim_status is set to rejected',
    resident_seen_at          DATETIME NULL COMMENT 'NULL means the resident has not yet seen this decision on their dashboard',
    qualified_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    qualification_snapshot JSON NULL COMMENT 'Immutable criteria + resident/household values that first qualified this member - written once by the eligibility engine (migration_025), never overwritten by later sweeps',
    claimed_at        DATETIME NULL,
    UNIQUE KEY unique_member_program (member_id, program_id),
    FOREIGN KEY (member_id) REFERENCES household_members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE,
    FOREIGN KEY (import_batch_id) REFERENCES beneficiary_imports(import_id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_beneficiaries_qualified_at (qualified_at),
    INDEX idx_beneficiaries_status (claim_status),
    INDEX idx_beneficiaries_pickup (pickup_status)
) ENGINE=InnoDB;

-- One uploaded application document per row - a program can require
-- several documents (program_documents), so the single id_document_path on
-- beneficiaries can only cover the first one; the rest live here. Private:
-- served only through admin/view-benefit-document.php, never a direct URL.
CREATE TABLE benefit_documents (
    benefit_document_id INT AUTO_INCREMENT PRIMARY KEY,
    beneficiary_id      INT NOT NULL,
    label               VARCHAR(150) NOT NULL COMMENT 'Document label as configured on the program (program_documents.label)',
    file_path           VARCHAR(255) NOT NULL COMMENT 'Relative to uploads/ - private, served only through admin/view-benefit-document.php',
    uploaded_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(beneficiary_id) ON DELETE CASCADE,
    INDEX idx_benefit_documents_beneficiary (beneficiary_id)
) ENGINE=InnoDB;

-- Ledger of data-quality issues the eligibility gate raises. Nothing here
-- deletes or mutates resident records - flags exist only to tell an admin
-- what to review. Self-resolving: a flag opens when the check fires,
-- auto-closes once the record is fixed, and re-opens if it comes back.
-- Written by shared/data_quality.php, surfaced on admin/data-quality.php.
CREATE TABLE validation_flags (
    flag_id        INT AUTO_INCREMENT PRIMARY KEY,
    check_code     VARCHAR(50)  NOT NULL COMMENT 'Machine code of the check that fired, e.g. H_INCOME_NEGATIVE, D_CROSS_HOUSEHOLD_DUP',
    severity       ENUM('error','warning','possible_duplicate') NOT NULL,
    message        TEXT         NOT NULL COMMENT 'Human-readable explanation, as built by shared/data_quality.php',
    scope          ENUM('household','member') NOT NULL,
    household_id   INT NULL,
    member_id      INT NULL,
    status         ENUM('open','resolved') NOT NULL DEFAULT 'open' COMMENT 'Resolved = the underlying condition no longer exists (auto) or was accepted by an admin (manual)',
    resolved_by    INT NULL,
    resolved_at    DATETIME NULL,
    resolved_note  VARCHAR(500) NULL COMMENT 'Admin explanation when resolved manually; "auto-cleared: condition no longer present" otherwise',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES households(household_id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES household_members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY uk_validation_check (check_code, household_id, member_id),
    INDEX idx_validation_status (status, severity),
    INDEX idx_validation_household (household_id)
) ENGINE=InnoDB;

-- Senior Citizen and PWD benefits, seeded so a fresh install has them
-- immediately. Criteria reuse the rule engine's existing age/PWD checks.
INSERT INTO programs (name, description, program_type, category, status)
VALUES ('Senior Citizen Benefit', 'Assistance for barangay residents aged 60 and above.', 'Senior', 'Senior Citizen Assistance', 'active');
INSERT INTO program_criteria (program_id, min_age) VALUES (LAST_INSERT_ID(), 60);

INSERT INTO programs (name, description, program_type, category, status)
VALUES ('PWD Benefit', 'Assistance for barangay residents with a registered disability.', 'PWD', 'PWD Assistance', 'active');
INSERT INTO program_criteria (program_id, requires_pwd) VALUES (LAST_INSERT_ID(), 1);

CREATE TABLE events (
    event_id     INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(150) NOT NULL,
    description  TEXT,
    event_date   DATE NOT NULL,
    created_by   INT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_events_date (event_date)
) ENGINE=InnoDB;

-- A Facebook-style post can carry several photos, so this is its own
-- table rather than a single photo_path column on events - one event,
-- many photos. "Upcoming" is never stored either: like age, it is only
-- ever event_date >= CURDATE() at read time, so it can't drift stale.
CREATE TABLE event_photos (
    photo_id       INT AUTO_INCREMENT PRIMARY KEY,
    event_id       INT NOT NULL,
    photo_path     VARCHAR(255) NOT NULL COMMENT 'Relative to resident/assets/events/ - public, same pattern as barangay_officials.photo_path',
    display_order  INT NOT NULL DEFAULT 0,
    uploaded_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    INDEX idx_event_photos_event (event_id)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
    log_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NULL,
    action       VARCHAR(255) NOT NULL,
    category     ENUM('create','read','update','delete','validate') NULL DEFAULT NULL COMMENT 'CRUD + validate (validation/conflict flags raised by the data-quality gate)',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_activity_logs_user (user_id),
    INDEX idx_activity_logs_created (created_at),
    INDEX idx_activity_logs_category (category)
) ENGINE=InnoDB;

CREATE TABLE backup_logs (
    backup_id      INT AUTO_INCREMENT PRIMARY KEY,
    initiated_by    INT NULL,
    started_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    record_count    INT DEFAULT 0,
    status          ENUM('in_progress','completed','failed') NOT NULL DEFAULT 'in_progress',
    file_path       VARCHAR(255) NULL,
    FOREIGN KEY (initiated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Hashed tokens for the forgot-password flow (migration_023). Only the raw
-- token ever appears in the emailed URL; this table stores its SHA-256 hash.
CREATE TABLE password_reset_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    token_hash  VARCHAR(64)  NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_prt_user   (user_id),
    UNIQUE INDEX uq_prt_token_hash (token_hash),
    INDEX idx_prt_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE email_verification_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    token_hash  CHAR(64)     NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE INDEX uq_evt_token_hash (token_hash),
    INDEX idx_evt_user (user_id),
    INDEX idx_evt_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE registration_drafts (
    draft_id      CHAR(64)     NOT NULL PRIMARY KEY,
    workflow      VARCHAR(24)  NOT NULL,
    email         VARCHAR(150) NOT NULL,
    payload       JSON         NOT NULL,
    document_path VARCHAR(255) NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    expires_at    DATETIME     NOT NULL,
    verified_at   DATETIME     NULL,
    submitted_at  DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX uq_registration_draft_token (token_hash),
    INDEX idx_registration_draft_email (email),
    INDEX idx_registration_draft_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE auth_rate_limits (
    action             VARCHAR(40) NOT NULL,
    identifier_type    VARCHAR(16) NOT NULL,
    identifier_hash    CHAR(64)    NOT NULL,
    window_started_at  DATETIME    NOT NULL,
    attempts           INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (action, identifier_type, identifier_hash),
    INDEX idx_auth_rate_limits_window (window_started_at)
) ENGINE=InnoDB;

-- Small key/value table for system-wide state (migration_025) - currently
-- the daily eligibility sweep's last-run timestamp and its concurrency lock.
CREATE TABLE system_meta (
    meta_key     VARCHAR(64)  NOT NULL PRIMARY KEY,
    meta_value   VARCHAR(255) NULL,
    updated_at   DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
