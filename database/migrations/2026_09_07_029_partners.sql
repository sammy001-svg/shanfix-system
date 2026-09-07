-- =====================================================================
-- Migration 029 — partners and commission
--
-- A third kind of person who signs in: someone who introduces customers
-- to us and is paid a share of what those customers spend.
--
-- Three decisions are baked into this schema, and everything else
-- follows from them:
--
--   1. A CUSTOMER belongs to a partner, not a single job. Once a client
--      is tagged to a partner, every invoice we ever raise to that client
--      earns commission — including repeat work years later. That is the
--      right fit for printing and branding, where customers come back.
--
--   2. Commission is earned WHEN THE CUSTOMER PAYS, never when we
--      invoice. A part payment earns a proportional part. We never owe a
--      partner money we have not collected ourselves, and a bad debt
--      costs us the sale but not the sale plus the commission.
--
--   3. The rate is PER SERVICE where one is set, falling back to the
--      partner's own default. Design carries a different margin from
--      printing, and the commission should be able to say so.
--
-- Partner logins live in this table rather than in `users` or in
-- `client_users`, for the same reason those two are separate: three
-- kinds of account, three session keys, three guards, and no path by
-- which a bug in one hands out access in another.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- The partners themselves
-- ---------------------------------------------------------------------
-- An application and an approved partner are the same row in different
-- states. Somebody applying is a partner we have not said yes to yet,
-- and keeping them in one table means the approval is a status change
-- rather than a copy between tables that can half-fail.
CREATE TABLE IF NOT EXISTS partners (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Assigned when they are approved, so an application that is turned
  -- down never burns a code.
  partner_code     VARCHAR(30)  NULL,

  name             VARCHAR(140) NOT NULL COMMENT 'The person we deal with',
  company          VARCHAR(180) NULL     COMMENT 'Their business, if they have one',
  email            VARCHAR(160) NOT NULL,
  phone            VARCHAR(30)  NOT NULL,
  kra_pin          VARCHAR(30)  NULL     COMMENT 'Needed before we can pay them',

  -- What they told us when they applied: who they sell to, what they do.
  pitch            TEXT NULL,

  -- Their default share, used for anything the service rate does not
  -- cover. Percent, so 10.00 means ten percent.
  default_rate     DECIMAL(5,2) NOT NULL DEFAULT 10.00,

  -- pending    applied, cannot sign in yet
  -- active     approved and able to sign in
  -- suspended  was approved, access withdrawn, history kept
  -- rejected   we said no
  status           ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending',

  -- Sign-in. Null until they set one from the link in their approval.
  password_hash    VARCHAR(255) NULL,
  last_login_at    DATETIME NULL,
  failed_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until     DATETIME NULL,

  decided_by       INT UNSIGNED NULL COMMENT 'The staff member who approved or refused',
  decided_at       DATETIME NULL,
  decision_note    VARCHAR(255) NULL,

  notes            TEXT NULL COMMENT 'Internal, never shown to the partner',

  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_partners_email (email),
  UNIQUE KEY uq_partners_code (partner_code),
  KEY idx_partners_status (status),
  CONSTRAINT fk_partners_decided_by FOREIGN KEY (decided_by)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- One-time codes, for setting and resetting a partner password
-- ---------------------------------------------------------------------
-- Same shape and same rules as client_otps: hashed, single use, counted
-- against, and expiring. A partner login is worth as much as a client
-- one, and gets the same care.
CREATE TABLE IF NOT EXISTS partner_otps (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email        VARCHAR(160) NOT NULL,
  code_hash    VARCHAR(255) NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at   DATETIME NOT NULL,
  consumed_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_partner_otps_email (email),
  KEY idx_partner_otps_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The commission ledger
-- ---------------------------------------------------------------------
-- One row per payment that earned something. The ledger is derived: it
-- is rebuilt from the payments actually recorded against an invoice, so
-- a reversed payment takes its commission back with it and nobody has to
-- remember to do it by hand.
--
-- The rate is copied in rather than looked up later. Rates change, and a
-- commission already earned must not move when they do.
CREATE TABLE IF NOT EXISTS commissions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,

  partner_id     INT UNSIGNED NOT NULL,
  client_id      INT UNSIGNED NOT NULL,
  document_id    INT UNSIGNED NOT NULL COMMENT 'The invoice that was paid',
  payment_id     INT UNSIGNED NULL COMMENT 'The payment that earned it, where one payment did',

  -- What the commission was worked out on, and what came out.
  base_amount    DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Commissionable value of the invoice, net of discount and VAT',
  rate           DECIMAL(5,2)  NOT NULL DEFAULT 0.00 COMMENT 'Effective percent across the invoice, for showing our working',
  amount         DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  -- earned   the customer has paid; we owe this
  -- paid     we have paid it out to the partner
  -- void     the payment behind it was reversed
  status         ENUM('earned','paid','void') NOT NULL DEFAULT 'earned',

  paid_at        DATETIME NULL,
  payout_ref     VARCHAR(80) NULL,
  notes          VARCHAR(255) NULL,

  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_commissions_partner (partner_id, status),
  KEY idx_commissions_document (document_id),
  KEY idx_commissions_client (client_id),
  CONSTRAINT fk_commissions_partner FOREIGN KEY (partner_id)
    REFERENCES partners (id) ON DELETE CASCADE,
  CONSTRAINT fk_commissions_client FOREIGN KEY (client_id)
    REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_commissions_document FOREIGN KEY (document_id)
    REFERENCES documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The link that makes a customer somebody's
-- ---------------------------------------------------------------------
ALTER TABLE clients
  ADD COLUMN partner_id INT UNSIGNED NULL COMMENT 'The partner who introduced them, if any' AFTER source_lead_id;

ALTER TABLE clients
  ADD COLUMN partner_linked_at DATETIME NULL AFTER partner_id;

ALTER TABLE clients
  ADD KEY idx_clients_partner (partner_id);

ALTER TABLE clients
  ADD CONSTRAINT fk_clients_partner FOREIGN KEY (partner_id)
    REFERENCES partners (id) ON DELETE SET NULL;

-- A partner can introduce someone before there is a client record to
-- attach to, so the lead carries it until conversion hands it over.
ALTER TABLE leads
  ADD COLUMN partner_id INT UNSIGNED NULL COMMENT 'The partner who introduced this lead' AFTER source;

ALTER TABLE leads
  ADD KEY idx_leads_partner (partner_id);

ALTER TABLE leads
  ADD CONSTRAINT fk_leads_partner FOREIGN KEY (partner_id)
    REFERENCES partners (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- Per-service rates
-- ---------------------------------------------------------------------
-- Null means "use the partner's default". Zero is a real answer and
-- means this service pays no commission at all, which is why it cannot
-- be the same value as "not set".
ALTER TABLE services
  ADD COLUMN commission_rate DECIMAL(5,2) NULL COMMENT 'Percent; null means use the partner default' AFTER price;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
-- settings is a plain key/value table; there is no group or description
-- column to fill in.
INSERT INTO settings (setting_key, setting_value) VALUES
  ('partners_enabled',       '1'),
  ('partner_signup_enabled', '1'),
  ('partner_default_rate',   '10'),
  ('partner_terms',          'Commission is earned when your customer pays us, and is paid out monthly against an invoice from you.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
