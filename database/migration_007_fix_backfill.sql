-- Fix for the "#1052 Column 'member_id' is ambiguous" error.
-- Only needed if you already ran migration_007_benefit_applications.sql
-- and it stopped on that error - the ALTER TABLE and the two programs
-- (Senior Citizen Benefit, PWD Benefit) already went through fine, so
-- this just re-does the two backfill queries with the bug fixed.
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go.

INSERT INTO beneficiaries (member_id, program_id, claim_status)
SELECT hm.member_id, (SELECT program_id FROM programs WHERE name = 'Senior Citizen Benefit' LIMIT 1), 'qualified'
FROM household_members hm
WHERE hm.is_archived = FALSE
  AND hm.birthdate IS NOT NULL
  AND TIMESTAMPDIFF(YEAR, hm.birthdate, CURDATE()) >= 60
ON DUPLICATE KEY UPDATE claim_status = claim_status;

INSERT INTO beneficiaries (member_id, program_id, claim_status)
SELECT hm.member_id, (SELECT program_id FROM programs WHERE name = 'PWD Benefit' LIMIT 1), 'qualified'
FROM household_members hm
WHERE hm.is_archived = FALSE
  AND hm.is_pwd = 1
ON DUPLICATE KEY UPDATE claim_status = claim_status;
