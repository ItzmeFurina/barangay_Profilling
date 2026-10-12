-- Migration 013: drop households.barangay_zone.
--
-- Zone was only ever used by the CSV importer as a household identifier,
-- and was never part of what users enter on any form or screen. Households
-- are identified the way users input their address (house number + street),
-- matching the census flow, not a zone number.
--
-- Guarded: fresh schema.sql installs never had barangay_zone, so a plain
-- DROP would fail with "Unknown column". Same information_schema pattern the
-- other migrations use, so this is safe to re-run.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households' AND COLUMN_NAME = 'barangay_zone'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE households DROP COLUMN barangay_zone',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;