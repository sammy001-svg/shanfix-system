-- =====================================================================
-- Migration 022 — a lead activity always has a date
--
-- lead_activities.activity_date was NOT NULL with no default, so any
-- insert that did not name it failed outright. One of the five places
-- that log an activity did not name it, and raising a quotation or a
-- proposal from a lead died on that line with a 500.
--
-- The code is fixed. This is so the next one cannot fail the same way:
-- for four of those five call sites "when it happened" is simply now,
-- and a missing timestamp is never worth taking the page down over.
--
-- The one activity that can be backdated — a call or a meeting being
-- logged after the fact — still passes its own date and is unaffected.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE lead_activities
  MODIFY COLUMN activity_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
