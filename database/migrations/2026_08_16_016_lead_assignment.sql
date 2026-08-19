-- =====================================================================
-- Migration 016 — Leads allocated to more than one person
--
-- A lead had one assigned_to, which does not survive contact with how
-- selling actually works: a technical lead pairs with an account manager,
-- someone covers a colleague on leave, a big account has two people on it.
--
-- lead_assignees carries the full set. leads.assigned_to stays as the
-- owner — the one name that appears in a list and takes the follow-up
-- reminders — so nothing that already reads it has to change.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS lead_assignees (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- One row per person per lead, so allocating twice is a no-op rather
  -- than a duplicate someone has to clean up later.
  UNIQUE KEY uq_lead_assignee (lead_id, user_id),
  KEY idx_lassign_user (user_id),
  CONSTRAINT fk_lassign_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_lassign_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Everyone already holding a lead keeps it. Without this backfill the
-- new scoping would hide every existing lead from the person working it.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO lead_assignees (lead_id, user_id)
SELECT id, assigned_to FROM leads WHERE assigned_to IS NOT NULL;
