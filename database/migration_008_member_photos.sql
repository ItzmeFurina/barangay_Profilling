-- Migration: lets every household member carry a profile photo, not just
-- the account holder. Files live in resident/assets/avatars/ (same public
-- pattern as barangay_officials.photo_path); this column records the
-- filename on the member row.
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go.

ALTER TABLE household_members
    ADD COLUMN photo_path VARCHAR(255) NULL AFTER occupation;
