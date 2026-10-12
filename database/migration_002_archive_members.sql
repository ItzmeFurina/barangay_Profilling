-- Migration: adds archive support to household_members.
-- Run this once against your EXISTING database (no drop/reimport needed).
-- In phpMyAdmin: select the barangay420 database, click the SQL tab,
-- paste this in, click Go.

ALTER TABLE household_members
    ADD COLUMN is_archived BOOLEAN NOT NULL DEFAULT FALSE
    COMMENT 'Soft-delete: excluded from eligibility checks and resident-facing views once archived'
    AFTER is_unemployed;
