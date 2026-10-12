-- Migration: adds real Employed / Student checkboxes to the census,
-- alongside the existing PWD/Solo parent/OFW/OSY/IP/Unemployed flags.
-- The admin dashboard previously guessed these two from the free-text
-- occupation field ("contains the word student", "not blank and not
-- unemployed") - this replaces that guess with an actual checkbox, the
-- same as every other status on the form.
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go.

ALTER TABLE household_members
    ADD COLUMN is_employed BOOLEAN NOT NULL DEFAULT FALSE AFTER is_unemployed,
    ADD COLUMN is_student  BOOLEAN NOT NULL DEFAULT FALSE AFTER is_employed;
