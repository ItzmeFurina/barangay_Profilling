-- Migration: adds a single-row table for barangay info that Staff/Admin
-- can edit and residents see on their home page. Additive - no reset needed.
-- In phpMyAdmin: select the barangay420 database, SQL tab, paste, Go.

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
    updated_at       DATETIME NULL,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO barangay_info (info_id, tagline, address, office_hours)
VALUES (1, 'Serving the community with transparency and care.', 'Barangay 420, Sampaloc, Manila', 'Monday to Friday, 8:00 AM - 5:00 PM');
