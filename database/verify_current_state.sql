-- Verification script: checks your ACTUAL current database against
-- everything it should contain at this point in the project. Read-only -
-- makes no changes, just reports OK / MISSING for each item.
--
-- In phpMyAdmin: barangay420 database, SQL tab, paste this whole file, Go.
-- Scroll through the single results table it produces - every row should
-- say OK. Anything else tells you exactly what's missing.
--
-- All checks below are derived from INFORMATION_SCHEMA only, so the script
-- always completes and reports per-item, even when whole tables are missing.
-- The seed-data checks at the bottom run only when their tables exist.

SELECT 'Table: users' AS check_item, IF(COUNT(*)=1,'OK','MISSING') AS status FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'
UNION ALL SELECT 'Table: barangay_info', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='barangay_info'
UNION ALL SELECT 'Table: barangay_officials', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='barangay_officials'
UNION ALL SELECT 'Table: households', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='households'
UNION ALL SELECT 'Table: household_members', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='household_members'
UNION ALL SELECT 'Table: residents', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='residents'
UNION ALL SELECT 'Table: barangay_staff', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='barangay_staff'
UNION ALL SELECT 'Table: verification_documents', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='verification_documents'
UNION ALL SELECT 'Table: programs', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs'
UNION ALL SELECT 'Table: program_criteria', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='program_criteria'
UNION ALL SELECT 'Table: program_documents', IF(COUNT(*)=1,'OK','MISSING - run migration_011') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='program_documents'
UNION ALL SELECT 'Table: beneficiaries', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries'
UNION ALL SELECT 'Table: events', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events'
UNION ALL SELECT 'Table: event_photos', IF(COUNT(*)=1,'OK','MISSING - run migration_006') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='event_photos'
UNION ALL SELECT 'Table: activity_logs', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='activity_logs'
UNION ALL SELECT 'Table: backup_logs', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='backup_logs'
UNION ALL SELECT 'Table: benefit_documents', IF(COUNT(*)=1,'OK','MISSING - run migration_016') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='benefit_documents'
UNION ALL SELECT 'Table: validation_flags', IF(COUNT(*)=1,'OK','MISSING - run migration_017') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='validation_flags'

UNION ALL SELECT 'Column: household_members.is_archived', IF(COUNT(*)=1,'OK','MISSING - run migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='household_members' AND COLUMN_NAME='is_archived'
UNION ALL SELECT 'Column: household_members.photo_path', IF(COUNT(*)=1,'OK','MISSING - run migration_008') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='household_members' AND COLUMN_NAME='photo_path'
UNION ALL SELECT 'Column: household_members.is_employed', IF(COUNT(*)=1,'OK','MISSING - run migration_010') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='household_members' AND COLUMN_NAME='is_employed'
UNION ALL SELECT 'Column: household_members.is_student', IF(COUNT(*)=1,'OK','MISSING - run migration_010') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='household_members' AND COLUMN_NAME='is_student'
UNION ALL SELECT 'Column: residents.household_member_id', IF(COUNT(*)=1,'OK','MISSING - run migration_022') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='residents' AND COLUMN_NAME='household_member_id'
UNION ALL SELECT 'Column: residents.house_number', IF(COUNT(*)=1,'OK','MISSING - run migration_009') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='residents' AND COLUMN_NAME='house_number'
UNION ALL SELECT 'Column: residents.street', IF(COUNT(*)=1,'OK','MISSING - run migration_009') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='residents' AND COLUMN_NAME='street'
UNION ALL SELECT 'Column: residents.barangay', IF(COUNT(*)=1,'OK','MISSING - run migration_009') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='residents' AND COLUMN_NAME='barangay'
UNION ALL SELECT 'Column: residents.city', IF(COUNT(*)=1,'OK','MISSING - run migration_009') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='residents' AND COLUMN_NAME='city'
UNION ALL SELECT 'Column: households.house_number', IF(COUNT(*)=1,'OK','MISSING - run migration_009') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='households' AND COLUMN_NAME='house_number'
UNION ALL SELECT 'Column: households.barangay', IF(COUNT(*)=1,'OK','MISSING - run migration_009') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='households' AND COLUMN_NAME='barangay'
UNION ALL SELECT 'Column: households.monthly_income', IF(COUNT(*)=1,'OK','MISSING - run migration_012') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='households' AND COLUMN_NAME='monthly_income'
UNION ALL SELECT 'Column: users.photo_path', IF(COUNT(*)=1,'OK','MISSING - run migration_007') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='photo_path'
UNION ALL SELECT 'Column: users.failed_login_attempts', IF(COUNT(*)=1,'OK','MISSING - run migration_022') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='failed_login_attempts'
UNION ALL SELECT 'Column: users.locked_until', IF(COUNT(*)=1,'OK','MISSING - run migration_022') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='locked_until'
UNION ALL SELECT 'Column: users.email_verified_at', IF(COUNT(*)=1,'OK','MISSING - run migration_027_auth_email_verification') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_verified_at'
UNION ALL SELECT 'Column: users.email_verification_required', IF(COUNT(*)=1,'OK','MISSING - run migration_027_auth_email_verification') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_verification_required'
UNION ALL SELECT 'Column: program_criteria.requires_employed', IF(COUNT(*)=1,'OK','MISSING - run migration_011') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='program_criteria' AND COLUMN_NAME='requires_employed'
UNION ALL SELECT 'Column: program_criteria.requires_student', IF(COUNT(*)=1,'OK','MISSING - run migration_011') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='program_criteria' AND COLUMN_NAME='requires_student'
UNION ALL SELECT 'Column: program_criteria.max_income', IF(COUNT(*)=1,'OK','MISSING - run migration_012') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='program_criteria' AND COLUMN_NAME='max_income'
UNION ALL SELECT 'Column: program_criteria.min_income', IF(COUNT(*)=1,'OK','MISSING - run migration_018') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='program_criteria' AND COLUMN_NAME='min_income'
UNION ALL SELECT 'Column: beneficiaries.member_id', IF(COUNT(*)=1,'OK','MISSING') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='member_id'
UNION ALL SELECT 'Column: beneficiaries.applicant_full_name', IF(COUNT(*)=1,'OK','MISSING - run migration_007') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='applicant_full_name'
UNION ALL SELECT 'Column: beneficiaries.applicant_contact_number', IF(COUNT(*)=1,'OK','MISSING - run migration_007') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='applicant_contact_number'
UNION ALL SELECT 'Column: beneficiaries.id_document_path', IF(COUNT(*)=1,'OK','MISSING - run migration_007') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='id_document_path'
UNION ALL SELECT 'Column: beneficiaries.applied_at', IF(COUNT(*)=1,'OK','MISSING - run migration_007') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='applied_at'
UNION ALL SELECT 'Column: beneficiaries.reviewed_by', IF(COUNT(*)=1,'OK','MISSING - run migration_007') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='reviewed_by'
UNION ALL SELECT 'Column: beneficiaries.reviewed_at', IF(COUNT(*)=1,'OK','MISSING - run migration_007') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='reviewed_at'
UNION ALL SELECT 'Column: beneficiaries.rejection_reason', IF(COUNT(*)=1,'OK','MISSING - run migration_008') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='rejection_reason'
UNION ALL SELECT 'Column: beneficiaries.resident_seen_at', IF(COUNT(*)=1,'OK','MISSING - run migration_008') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='resident_seen_at'
UNION ALL SELECT 'Column: beneficiaries.pickup_status', IF(COUNT(*)=1,'OK','MISSING - run migration_009') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='pickup_status'
UNION ALL SELECT 'Column: beneficiaries.claim_status includes retracted', IF(COUNT(*)=1,'OK','MISSING - run migration_016') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='claim_status' AND COLUMN_TYPE LIKE '%retracted%'
UNION ALL SELECT 'Column: programs.start_date', IF(COUNT(*)=1,'OK','MISSING - run migration_019') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='start_date'
UNION ALL SELECT 'Column: programs.end_date', IF(COUNT(*)=1,'OK','MISSING - run migration_019') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='end_date'
UNION ALL SELECT 'Column: programs.start_date is DATETIME', IF(COUNT(*)=1,'OK','MISSING - run migration_020') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='start_date' AND COLUMN_TYPE='datetime'
UNION ALL SELECT 'Column: programs.end_date is DATETIME', IF(COUNT(*)=1,'OK','MISSING - run migration_020') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='end_date' AND COLUMN_TYPE='datetime'
UNION ALL SELECT 'Column: programs.category', IF(COUNT(*)=1,'OK','MISSING - run migration_021') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='category'
UNION ALL SELECT 'Column: programs.provider', IF(COUNT(*)=1,'OK','MISSING - run migration_021') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='provider'
UNION ALL SELECT 'Column: programs.application_deadline', IF(COUNT(*)=1,'OK','MISSING - run migration_021') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='application_deadline'
UNION ALL SELECT 'Column: programs.release_period', IF(COUNT(*)=1,'OK','MISSING - run migration_021') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='release_period'
UNION ALL SELECT 'Column: programs.slot_limit', IF(COUNT(*)=1,'OK','MISSING - run migration_021') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='slot_limit'
UNION ALL SELECT 'Column: activity_logs.category includes validate', IF(COUNT(*)=1,'OK','MISSING - run migration_017') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='activity_logs' AND COLUMN_NAME='category' AND COLUMN_TYPE LIKE '%validate%'
UNION ALL SELECT 'Column: barangay_info.about_text', IF(COUNT(*)=1,'OK','MISSING - run migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='barangay_info' AND COLUMN_NAME='about_text'
UNION ALL SELECT 'Table: password_reset_tokens', IF(COUNT(*)=1,'OK','MISSING - run migration_023 or migration_CATCHUP') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='password_reset_tokens'
UNION ALL SELECT 'Table: email_verification_tokens', IF(COUNT(*)=1,'OK','MISSING - run migration_027_auth_email_verification') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='email_verification_tokens'
UNION ALL SELECT 'Table: auth_rate_limits', IF(COUNT(*)=1,'OK','MISSING - run migration_027_auth_email_verification') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='auth_rate_limits'
UNION ALL SELECT 'Table: registration_drafts', IF(COUNT(*)=1,'OK','MISSING - run migration_027_auth_email_verification') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='registration_drafts'
UNION ALL SELECT 'Table: beneficiary_imports', IF(COUNT(*)=1,'OK','MISSING - run migration_024 or migration_CATCHUP') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiary_imports'
UNION ALL SELECT 'Table: beneficiary_import_rows', IF(COUNT(*)=1,'OK','MISSING - run migration_024 or migration_CATCHUP') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiary_import_rows'
UNION ALL SELECT 'Table: system_meta', IF(COUNT(*)=1,'OK','MISSING - run migration_025 or migration_CATCHUP') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_meta'
UNION ALL SELECT 'Column: users.role includes secretary', IF(COUNT(*)=1,'OK','MISSING - run migration_012 or migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role' AND COLUMN_TYPE LIKE '%secretary%'
UNION ALL SELECT 'Column: programs.application_mode', IF(COUNT(*)=1,'OK','MISSING - run migration_024 or migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='application_mode'
UNION ALL SELECT 'Column: beneficiaries.source_type', IF(COUNT(*)=1,'OK','MISSING - run migration_024 or migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='source_type'
UNION ALL SELECT 'Column: beneficiaries.import_batch_id', IF(COUNT(*)=1,'OK','MISSING - run migration_024 or migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='import_batch_id'
UNION ALL SELECT 'Column: beneficiaries.original_status', IF(COUNT(*)=1,'OK','MISSING - run migration_024 or migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='original_status'
UNION ALL SELECT 'Column: beneficiaries.claim_status includes existing', IF(COUNT(*)=1,'OK','MISSING - run migration_024 or migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='claim_status' AND COLUMN_TYPE LIKE '%existing%'
UNION ALL SELECT 'Column: beneficiaries.qualification_snapshot', IF(COUNT(*)=1,'OK','MISSING - run migration_025 or migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneficiaries' AND COLUMN_NAME='qualification_snapshot'
UNION ALL SELECT 'Column: programs.import_existing_list', IF(COUNT(*)=1,'OK','MISSING - run migration_026 or migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='import_existing_list'
UNION ALL SELECT 'Column: programs.application_mode includes no_application_required', IF(COUNT(*)=1,'OK','MISSING - run migration_026 or migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs' AND COLUMN_NAME='application_mode' AND COLUMN_TYPE LIKE '%no_application_required%'
UNION ALL SELECT 'Column: barangay_info.punong_barangay (should be ABSENT)', IF(COUNT(*)=0,'OK - correctly absent','STILL PRESENT - run migration_CATCHUP') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='barangay_info' AND COLUMN_NAME='punong_barangay';

-- Seed-data checks. These reference the tables directly, so they are only
-- run when both exist - otherwise the script would abort instead of telling
-- you what is missing (the table rows above already report MISSING).
SET @seed_tables = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('barangay_info', 'barangay_officials', 'users')
);
SET @sql = IF(@seed_tables = 3,
    "SELECT 'Seed data: barangay_info row exists' AS check_item, IF((SELECT COUNT(*) FROM barangay_info WHERE info_id=1)=1,'OK','MISSING - run migration_CATCHUP') AS status
     UNION ALL SELECT 'Seed data: barangay_info.about_text has real census figures', IF((SELECT about_text FROM barangay_info WHERE info_id=1) LIKE '%3,134%','OK','MISSING - run migration_CATCHUP')
     UNION ALL SELECT 'Seed data: barangay_officials rows', CONCAT((SELECT COUNT(*) FROM barangay_officials), ' rows found')
     UNION ALL SELECT 'Your account: at least one Admin/Staff exists', IF((SELECT COUNT(*) FROM users WHERE role IN ('admin','staff') AND account_status='active')>=1,'OK','MISSING - run database/create_admin.php from the command line')",
    "SELECT 'Seed data / admin account checks' AS check_item, 'SKIPPED - run migration_CATCHUP first (barangay_info, barangay_officials or users missing)' AS status"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
