-- Migration: separates two things that were both crammed into
-- claim_status = 'claimed' before - (1) the admin approving the
-- application, and (2) the resident actually picking the benefit up.
-- "Claimed" now only ever means the second one; the approval decision is
-- called "approved". In phpMyAdmin: barangay420 database, SQL tab,
-- paste, Go. Safe to run once.

-- Step 1: widen the enum so both the old and new value can exist at the
-- same time while the data is migrated.
ALTER TABLE beneficiaries
    MODIFY COLUMN claim_status ENUM('qualified','pending','claimed','approved','rejected') NOT NULL DEFAULT 'qualified';

UPDATE beneficiaries SET claim_status = 'approved' WHERE claim_status = 'claimed';

-- Step 2: narrow the enum again now that nothing uses 'claimed' as a
-- claim_status value anymore - it's about to mean something else below.
ALTER TABLE beneficiaries
    MODIFY COLUMN claim_status ENUM('qualified','pending','approved','rejected') NOT NULL DEFAULT 'qualified';

-- claimed_at previously got stamped the moment an application was
-- approved, which is a different moment than a resident actually
-- picking the benefit up - clear it so it's ready to mean the latter.
UPDATE beneficiaries SET claimed_at = NULL WHERE claim_status = 'approved';

ALTER TABLE beneficiaries
    ADD COLUMN pickup_status ENUM('unclaimed','claimed') NOT NULL DEFAULT 'unclaimed' COMMENT 'Only meaningful once claim_status = approved - tracks whether the resident has actually picked up the benefit' AFTER claim_status;
