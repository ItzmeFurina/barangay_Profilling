-- Migration: lets the admin leave a reason when rejecting a benefit
-- application, and lets the resident dashboard tell a freshly-decided
-- application (claimed or rejected) apart from one they've already seen.
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go.

ALTER TABLE beneficiaries
    ADD COLUMN rejection_reason TEXT NULL COMMENT 'Set by the admin when claim_status is set to rejected' AFTER reviewed_at,
    ADD COLUMN resident_seen_at DATETIME NULL COMMENT 'NULL means the resident has not yet seen this decision on their dashboard - cleared back to NULL every time the admin decides claimed/rejected' AFTER rejection_reason;
