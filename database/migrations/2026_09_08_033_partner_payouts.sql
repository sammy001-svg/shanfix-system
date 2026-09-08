-- =====================================================================
-- Migration 033 — paying partners, and knowing that we did
--
-- Until now "pay" flipped every owed commission row to 'paid' the moment
-- somebody clicked it. That records an intention, not a payment. If the
-- M-Pesa transfer bounced because the number was wrong, or the bank
-- rejected one line out of forty, the ledger still said paid and nobody
-- could tell which. There was also nothing to hand the bank: finance
-- read the figures off a screen and typed them in again.
--
-- So a payment becomes a thing in its own right:
--
--   a RUN   is a month's paying-out, built once, checked, then approved;
--   a LINE  is one partner within it, with its own destination, its own
--           reference and its own fate — because one line failing must
--           not un-pay the other thirty-nine.
--
-- Commission rows point at the line that paid them, and only turn 'paid'
-- when that line actually settles. A line that fails releases its rows
-- so they are simply owed again and fall into the next run.
--
-- Commission is paid gross. There is no withholding here on purpose:
-- tax on it is handled outside this system.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Where a partner's money goes
-- ---------------------------------------------------------------------
-- Kept on the partner rather than asked for at payment time, because
-- finance should not be re-typing an account number every month — that
-- is how money reaches the wrong person.
--
-- Deliberately set by staff and not by the partner. Somebody who talks
-- their way into a partner login should not be able to redirect the
-- money; the partner can see what is on file and tell us if it is wrong.
ALTER TABLE partners
  ADD COLUMN pay_method ENUM('mpesa','bank') NULL
      COMMENT 'How we send it. NULL means we cannot pay them yet'
      AFTER kra_pin,
  ADD COLUMN pay_phone VARCHAR(30) NULL
      COMMENT 'M-Pesa number, which is not always their contact number'
      AFTER pay_method,
  ADD COLUMN bank_name VARCHAR(120) NULL AFTER pay_phone,
  ADD COLUMN bank_branch VARCHAR(120) NULL AFTER bank_name,
  ADD COLUMN bank_account_name VARCHAR(160) NULL
      COMMENT 'As the bank has it, which is not always the trading name'
      AFTER bank_branch,
  ADD COLUMN bank_account_no VARCHAR(40) NULL AFTER bank_account_name;

-- ---------------------------------------------------------------------
-- A month's paying-out
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payout_runs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  period       CHAR(7) NOT NULL COMMENT 'YYYY-MM the commission was earned in',

  -- draft     — built, still being checked, nothing committed
  -- approved  — signed off, ready to send to the bank
  -- sent      — the file has gone out; we are waiting to hear back
  -- closed    — every line has settled, failed or been held over
  status       ENUM('draft','approved','sent','closed') NOT NULL DEFAULT 'draft',

  total        DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Payable lines only',
  line_count   INT UNSIGNED NOT NULL DEFAULT 0,

  created_by   INT UNSIGNED NULL,
  approved_by  INT UNSIGNED NULL,
  approved_at  DATETIME NULL,
  exported_at  DATETIME NULL,
  closed_at    DATETIME NULL,
  note         VARCHAR(255) NULL,

  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_payout_runs_period (period, status),
  KEY idx_payout_runs_status (status),
  CONSTRAINT fk_payout_runs_created_by  FOREIGN KEY (created_by)  REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payout_runs_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- One partner within it
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payout_lines (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id       INT UNSIGNED NOT NULL,
  partner_id   INT UNSIGNED NOT NULL,

  amount       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  entries      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Commission rows behind it',

  -- The destination is copied in rather than read from the partner when
  -- it is needed. Account numbers change; a payment made last March must
  -- still say where it actually went.
  method       ENUM('mpesa','bank') NULL,
  destination  VARCHAR(200) NULL COMMENT 'The number the money was sent to, as it was then',
  account_name VARCHAR(160) NULL,

  -- pending — waiting to go out with the run
  -- sent    — in the file we handed the bank
  -- settled — the money landed, and we have the reference
  -- failed  — it bounced; the commission behind it is owed again
  -- held    — deliberately left out, usually for want of payment details
  status         ENUM('pending','sent','settled','failed','held') NOT NULL DEFAULT 'pending',
  ref            VARCHAR(80) NULL COMMENT 'The transaction reference from M-Pesa or the bank',
  failure_reason VARCHAR(255) NULL,
  settled_at     DATETIME NULL,

  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- One line per partner per run, or the same commission gets sent twice.
  UNIQUE KEY uq_payout_line (run_id, partner_id),
  KEY idx_payout_lines_partner (partner_id, status),
  KEY idx_payout_lines_status (status),
  CONSTRAINT fk_payout_lines_run     FOREIGN KEY (run_id)     REFERENCES payout_runs (id) ON DELETE CASCADE,
  CONSTRAINT fk_payout_lines_partner FOREIGN KEY (partner_id) REFERENCES partners (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The link that makes reconciliation possible
-- ---------------------------------------------------------------------
-- payout_ref stays, because it is what a person quotes on the phone, but
-- it is a string and cannot be joined on. This is the real link: which
-- payment covered this commission.
--
-- It doubles as the guard against paying twice. A row with a line
-- against it is already committed to a run, so the next run must not
-- pick it up. "Available to pay" is exactly: earned, and no line yet.
ALTER TABLE commissions
  ADD COLUMN payout_line_id INT UNSIGNED NULL
      COMMENT 'The payout line that covers this, once it is in a run'
      AFTER payout_ref,
  ADD KEY idx_commissions_line (payout_line_id),
  ADD CONSTRAINT fk_commissions_payout_line
      FOREIGN KEY (payout_line_id) REFERENCES payout_lines (id) ON DELETE SET NULL;
