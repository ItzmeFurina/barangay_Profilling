-- Migration 015: backfill activity_logs.category from the action text.
--
-- Migration 014 added the CRUD category column but left historical rows
-- NULL rather than guessing backwards. The action strings themselves,
-- though, unambiguously name the kind of change ("Deleted event #6",
-- "Created event: hehe", "Resident logged in", "Set program #3 to
-- inactive"), so they are classified deterministically from keywords so
-- the Logs page Create / Read / Update / Delete filters behave for
-- history exactly as they do for newly-logged entries. New entries are
-- still assigned their category explicitly at each call site via
-- log_activity(); this only labels what predates it.

UPDATE activity_logs
SET category = CASE
    WHEN action LIKE '%deleted%' OR action LIKE '%removed%' OR action LIKE '%archived%' THEN 'delete'
    WHEN action LIKE '%created%' OR action LIKE '%added%' OR action LIKE '%registered%'
      OR action LIKE '%completed household census%' OR action LIKE '%applied for%'
      OR action LIKE '%restored from%' OR action LIKE '%spreadsheet import%'
      OR action LIKE '%submitted%' THEN 'create'
    WHEN action LIKE '%logged in%' OR action LIKE '%logged out%' OR action LIKE '%exported%'
      OR action LIKE '%downloaded backup%' OR action LIKE '%failed backup download confirmation%' THEN 'read'
    WHEN action LIKE '%updated%' OR action LIKE '%set beneficiary%' OR action LIKE '%set program%'
      OR action LIKE '%marked beneficiary%' OR action LIKE '%approved account%'
      OR action LIKE '%rejected account%' OR action LIKE '%account linked%' THEN 'update'
    ELSE category
END
WHERE category IS NULL;