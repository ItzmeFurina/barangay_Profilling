-- ────────────────────────────────────────────────────────────────────────
-- Migration 025 - Fully automatic eligibility detection
--
-- 1. beneficiaries.qualification_snapshot (JSON)
--    Immutable record of the criteria/rules and the resident/household
--    values that FIRST qualified a member for a program. Written once by the
--    eligibility engine at the moment it inserts the beneficiary row, and
--    preserved through retraction and restoration. Later runs of the daily
--    eligibility sweep never overwrite it; the original qualification date
--    (qualified_at) is kept alongside it.
--
-- 2. system_meta (small key/value mechanism for the daily-sweep throttle)
--    - 'eligibility_last_completed_at'  -> last successful completion of the
--      full-household sweep; drives the 24-hour interval (cadence is based on
--      completion, never on the lock itself).
--    - 'eligibility_sweep_lock'         -> concurrency lock. updated_at holds
--      the acquisition time (started_at); a lock held longer than 15 minutes
--      is considered stale (e.g. a request that died mid-sweep) and can be
--      reacquired, so a crashed run can never block the next sweep for 24h.
--
-- Idempotent: every statement is safe to run more than once.
-- ────────────────────────────────────────────────────────────────────────

-- 1. beneficiaries.qualification_snapshot
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'beneficiaries'
              AND COLUMN_NAME = 'qualification_snapshot'
        ),
        'SELECT 1',
        'ALTER TABLE beneficiaries ADD COLUMN qualification_snapshot JSON NULL COMMENT "Immutable criteria + resident/household values that first qualified this member" AFTER qualified_at'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. system_meta
CREATE TABLE IF NOT EXISTS system_meta (
    meta_key     VARCHAR(64)  NOT NULL PRIMARY KEY,
    meta_value   VARCHAR(255) NULL,
    updated_at   DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;