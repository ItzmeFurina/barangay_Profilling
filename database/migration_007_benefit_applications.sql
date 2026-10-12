-- Migration: turns "qualified" into "eligible to apply" instead of
-- "already enrolled". A resident now has to actively apply - full name,
-- an active contact number, and their Senior/PWD ID - before an admin
-- reviews and approves it. The existing claim_status states already map
-- perfectly onto that flow, so no new table is needed:
--   qualified  = the rule engine matched them, they haven't applied yet
--   pending    = they applied, waiting on the admin
--   claimed    = the admin approved the application
--   rejected   = the admin rejected the application
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go. Safe to run
-- once; running it twice will fail on the duplicate program insert (or
-- just remove those two INSERT blocks below if you re-run it).

ALTER TABLE beneficiaries
    ADD COLUMN applicant_full_name      VARCHAR(150) NULL AFTER claim_status,
    ADD COLUMN applicant_contact_number VARCHAR(20)  NULL AFTER applicant_full_name,
    ADD COLUMN id_document_path         VARCHAR(255) NULL COMMENT 'Senior/PWD ID upload - private, served only through admin/view-benefit-document.php, same pattern as verification_documents' AFTER applicant_contact_number,
    ADD COLUMN applied_at               DATETIME NULL AFTER id_document_path,
    ADD COLUMN reviewed_by              INT NULL AFTER applied_at,
    ADD COLUMN reviewed_at              DATETIME NULL AFTER reviewed_by,
    ADD FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- Seed the two programs. Criteria match exactly what the rule engine in
-- shared/eligibility.php already knows how to check - age from birthdate,
-- and the is_pwd flag from the census - so no code changes were needed
-- to support them, only these two rows.
INSERT INTO programs (name, description, program_type, status)
VALUES ('Senior Citizen Benefit', 'Assistance for barangay residents aged 60 and above.', 'Senior', 'active');
INSERT INTO program_criteria (program_id, min_age) VALUES (LAST_INSERT_ID(), 60);

INSERT INTO programs (name, description, program_type, status)
VALUES ('PWD Benefit', 'Assistance for barangay residents with a registered disability.', 'PWD', 'active');
INSERT INTO program_criteria (program_id, requires_pwd) VALUES (LAST_INSERT_ID(), 1);

-- One-time backfill: mirrors evaluate_household_eligibility() for these
-- two new programs so residents who completed their census before this
-- migration ran show up as "qualified" (eligible to apply) immediately,
-- instead of waiting for their next census edit to trigger a re-check.
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
