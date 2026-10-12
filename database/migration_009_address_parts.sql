-- Migration 009: Split addresses into separate House/Building/Unit number,
-- Street, Barangay and City fields so households at the same address can be
-- matched and grouped. Run once, then delete this file or keep for history.

ALTER TABLE residents
  ADD COLUMN house_number VARCHAR(50) NULL COMMENT 'House/Building/Unit number from registration' AFTER address,
  ADD COLUMN street VARCHAR(150) NULL AFTER house_number,
  ADD COLUMN barangay VARCHAR(100) NULL AFTER street,
  ADD COLUMN city VARCHAR(100) NULL AFTER barangay,
  MODIFY address VARCHAR(255) NULL COMMENT 'Legacy free-text address kept for old rows only';

ALTER TABLE households
  ADD COLUMN house_number VARCHAR(50) NULL COMMENT 'House/Building/Unit number' AFTER household_id,
  ADD COLUMN barangay VARCHAR(100) NULL DEFAULT 'Barangay 420' AFTER street;
