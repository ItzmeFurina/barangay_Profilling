-- Migration: lets resident accounts upload a profile picture.
-- The file itself lives in resident/assets/avatars/ (public, same pattern
-- as barangay_officials.photo_path / event_photos.photo_path); this
-- column just records its filename on the user row.
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go.

ALTER TABLE users
    ADD COLUMN photo_path VARCHAR(255) NULL AFTER contact_number;
