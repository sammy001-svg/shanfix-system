-- =====================================================================
-- Migration 030 — looking after partners, and paying them monthly
--
-- Three things the first partner migration left out, all of which turn
-- out to matter once real people are using it:
--
--   1. SOMEBODY OWNS THE RELATIONSHIP. A partner with nobody looking
--      after them goes quiet. Assigning an account manager makes one
--      named person the contact point between them and us.
--
--   2. COMMISSION IS PAID MONTHLY. Paying "everything owed" whenever
--      somebody remembers is not a process; a month is. Stamping the
--      period a commission was earned in is what makes a monthly run
--      possible, and what lets a partner see the same months we do.
--
--      The period is the month it was EARNED — the month the customer
--      paid us — not the month we invoiced. That is the month we owe it
--      for, and it is what both sides will reconcile against.
--
--   3. WHO MAY DO WHAT. Sales look after partners day to day but must
--      not be able to create one, and that is enforced in the routes
--      rather than here; this migration only adds the column that says
--      which of them looks after whom.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Who looks after this partner
-- ---------------------------------------------------------------------
-- ON DELETE SET NULL rather than CASCADE: a salesperson leaving must
-- unassign their partners, never delete them.
ALTER TABLE partners
  ADD COLUMN account_manager_id INT UNSIGNED NULL
    COMMENT 'The salesperson who is their contact point here' AFTER default_rate;

ALTER TABLE partners
  ADD COLUMN assigned_at DATETIME NULL AFTER account_manager_id;

ALTER TABLE partners
  ADD KEY idx_partners_manager (account_manager_id);

ALTER TABLE partners
  ADD CONSTRAINT fk_partners_manager FOREIGN KEY (account_manager_id)
    REFERENCES users (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- The month a commission belongs to
-- ---------------------------------------------------------------------
-- Stored rather than derived on the fly. A payout run groups by it, a
-- partner's statement groups by it, and both have to agree for good —
-- including after a row is edited, when created_at would still move it
-- from one month's reconciled total into another's.
ALTER TABLE commissions
  ADD COLUMN period CHAR(7) NULL COMMENT 'YYYY-MM, the month it was earned in' AFTER amount;

ALTER TABLE commissions
  ADD KEY idx_commissions_period (partner_id, period, status);

-- Everything already in the ledger belongs to the month it was earned.
UPDATE commissions
   SET period = DATE_FORMAT(created_at, '%Y-%m')
 WHERE period IS NULL;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('partner_payout_day', '5')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
