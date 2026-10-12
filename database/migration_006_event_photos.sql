-- Migration: adds event_photos so each event can carry a small photo
-- album (Facebook-style post: title + photo up front, everything else
-- - caption, full date, the rest of the gallery - revealed on click).
-- One event can have many photos, so this is its own table rather than
-- a single photo_path column on events, the same reasoning already used
-- for household_members vs. a flat column-per-person design.
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go.

CREATE TABLE IF NOT EXISTS event_photos (
    photo_id       INT AUTO_INCREMENT PRIMARY KEY,
    event_id       INT NOT NULL,
    photo_path     VARCHAR(255) NOT NULL COMMENT 'Relative to resident/assets/events/ - public, same pattern as barangay_officials.photo_path',
    display_order  INT NOT NULL DEFAULT 0,
    uploaded_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    INDEX idx_event_photos_event (event_id)
) ENGINE=InnoDB;
