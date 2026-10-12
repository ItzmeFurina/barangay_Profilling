-- Migration: adds barangay_officials (many officials, each with a name,
-- position, and optional photo) and retires barangay_info.punong_barangay,
-- which is now redundant - that fact lives in this table instead, so
-- there is only ever one place it can drift out of sync from.
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go.

CREATE TABLE IF NOT EXISTS barangay_officials (
    official_id   INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(150) NOT NULL,
    position      VARCHAR(50) NOT NULL,
    display_order INT NOT NULL DEFAULT 100,
    photo_path    VARCHAR(255) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE barangay_info DROP COLUMN punong_barangay;

INSERT INTO barangay_officials (full_name, position, display_order) VALUES
('Hon. Michael John M. Regala', 'Punong Barangay', 1),
('Rudylinda P. Domingo', 'Secretary', 2),
('Reynaldo T. Delos Reyes', 'Treasurer', 3),
('Rodrigo C. Bernas Jr.', 'Kagawad', 10),
('Lailane S. Castelo', 'Kagawad', 11),
('Hones L. Bautista', 'Kagawad', 12),
('Florida R. Ami', 'Kagawad', 13),
('Leo R. Austria', 'Kagawad', 14),
('Randolph Malana', 'Kagawad', 15),
('Melvin A. Macanip', 'Kagawad', 16);
