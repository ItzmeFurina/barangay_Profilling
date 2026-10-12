-- Migration 028: release resident/staff registration email addresses when
-- the application has already been rejected. Keep the rejected user,
-- verification documents, rejection reasons, household and audit history.

START TRANSACTION;

UPDATE users
SET email = CONCAT('rejected-', user_id, '@invalid.local'),
    email_verified_at = NULL,
    email_verification_required = FALSE
WHERE account_status = 'rejected'
  AND role IN ('resident', 'staff')
  AND email <> CONCAT('rejected-', user_id, '@invalid.local');

DELETE prt
FROM password_reset_tokens prt
JOIN users u ON u.user_id = prt.user_id
WHERE u.account_status = 'rejected'
  AND u.role IN ('resident', 'staff');

DELETE evt
FROM email_verification_tokens evt
JOIN users u ON u.user_id = evt.user_id
WHERE u.account_status = 'rejected'
  AND u.role IN ('resident', 'staff');

UPDATE household_members hm
JOIN users u ON u.user_id = hm.linked_user_id
SET hm.linked_user_id = NULL
WHERE u.account_status = 'rejected'
  AND u.role = 'resident';

UPDATE residents r
JOIN users u ON u.user_id = r.user_id
SET r.household_member_id = NULL
WHERE u.account_status = 'rejected'
  AND u.role = 'resident'
  AND r.household_member_id IS NOT NULL;

COMMIT;
