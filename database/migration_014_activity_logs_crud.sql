-- Migration 014: CRUD category for activity logs.
--
-- Adds `category` so the Logs page can filter entries by the kind of
-- change they represent (create / read / update / delete). Historical
-- rows are left NULL ("unknown") rather than guessing a bucket in
-- hindsight; every action logged from here on carries an explicit
-- category chosen by the code path that performed it.

ALTER TABLE activity_logs ADD COLUMN category ENUM('create','read','update','delete') NULL DEFAULT NULL AFTER action;