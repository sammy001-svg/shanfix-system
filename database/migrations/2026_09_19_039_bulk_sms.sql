-- =====================================================================
-- Migration 039 — Bulk SMS, brought home
--
-- Shanfix Bulk SMS ran as a separate application at
-- sms.shanfixtechnology.com, with its own logins, its own customers and
-- its own database. This is that platform rebuilt inside the system, so
-- a customer has one login for their invoices and their SMS, a partner
-- resells SMS from the same portal they earn commission in, and the
-- office sees all of it in one place.
--
-- Every table is prefixed bulk_. The system already has sms_campaigns
-- (the office texting its own clients) and whatsapp_messages (the office
-- inbox), and those are different things that happen to share a word.
--
-- THE MODEL
-- ---------
-- An ACCOUNT holds a balance of SMS units. It belongs to exactly one of:
--
--   house    — Shanfix itself. Its balance mirrors the credit we hold
--              with Onfon, the gateway; every unit anyone else holds was
--              sold out of it. There is one.
--   client   — a customer from the clients table. Every person on that
--              customer's portal login shares the one account, the same
--              way they already share its invoices.
--   partner  — a partner, which is a reseller: they buy units from the
--              house in bulk and sell them on to their own clients.
--
-- An account's PARENT is where its units come from: the house for
-- direct clients and for partners, the partner for a partner's clients.
-- Units are never created except at the house. Everywhere else they
-- only move — parent to child on a sale, account to gateway on a send —
-- which is what makes a balance something you can reconcile.
--
-- Every movement is written to bulk_ledger. The old platform kept only
-- the balance, so "where did my units go" had no answer beyond the
-- message log. Sends are written per batch rather than per message:
-- a million-recipient campaign would otherwise write a million ledger
-- rows, and the per-message detail already lives in bulk_messages.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bulk_accounts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  owner_type    ENUM('house','client','partner') NOT NULL,
  -- clients.id or partners.id. NULL only for the house, which is us.
  owner_id      INT UNSIGNED NULL,

  -- Where this account's units come from. NULL only for the house.
  parent_id     INT UNSIGNED NULL,

  -- Units are fractional because a message is charged per 160-character
  -- part, and the old platform allowed fractional allocations. Four
  -- places matches its message rows.
  sms_units        DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
  whatsapp_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Money, not units',
  ussd_balance     DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Money, not units',

  -- What THIS account pays per unit when it buys. NULL means "whatever
  -- my parent charges by default" — the partner's resale price for a
  -- partner's client, the house price for everyone else.
  unit_price       DECIMAL(10,4) NULL,

  -- Partners only: what they charge their own clients by default. The
  -- old platform kept this on reseller_settings.unit_price.
  resale_unit_price DECIMAL(10,4) NULL,

  -- A warning e-mail when the balance drops under this. NULL means use
  -- the system-wide threshold; 0 means never warn.
  low_balance_threshold DECIMAL(12,2) NULL,
  low_balance_alerted_at DATETIME NULL,

  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',

  -- The developer API. The client id is public and shown on screen; the
  -- key is stored only as a SHA-256 hash, so a copy of this database
  -- does not hand anybody the ability to send as our customers. The
  -- old platform stored keys as written; migrated keys are hashed on the
  -- way in and keep working unchanged.
  api_client_id  VARCHAR(40) NULL,
  api_key_hash   CHAR(64) NULL,
  api_key_hint   VARCHAR(12) NULL COMMENT 'Last few characters, so a key can be recognised',
  api_key_created_at DATETIME NULL,
  api_last_used_at   DATETIME NULL,

  -- users.id on the old platform, so the migration can be re-run
  -- without creating anybody twice.
  legacy_user_id INT UNSIGNED NULL,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_bulk_account_owner (owner_type, owner_id),
  UNIQUE KEY uq_bulk_account_api   (api_client_id),
  UNIQUE KEY uq_bulk_account_legacy (legacy_user_id),
  KEY idx_bulk_account_parent (parent_id),
  CONSTRAINT fk_bulk_account_parent FOREIGN KEY (parent_id) REFERENCES bulk_accounts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The house account. There is exactly one, and a unique key on
-- (owner_type, owner_id) cannot enforce that because owner_id is NULL
-- (NULLs never collide), so it is created here and nowhere else.
INSERT INTO bulk_accounts (owner_type, owner_id, parent_id)
SELECT 'house', NULL, NULL
 WHERE NOT EXISTS (SELECT 1 FROM bulk_accounts WHERE owner_type = 'house');

-- ---------------------------------------------------------------------
-- Every movement of units, in and out
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bulk_ledger (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,

  -- purchase      units bought, credited to the buyer
  -- sale          the same units leaving the seller (house or partner)
  -- transfer_in   units moved in by hand from the parent
  -- transfer_out  units moved out by hand to a child
  -- send          units spent on messages
  -- refund        units returned for messages the gateway refused
  -- adjustment    staff correction, including syncing the house to Onfon
  -- migration     opening balance brought over from the old platform
  kind          ENUM('purchase','sale','transfer_in','transfer_out','send','refund','adjustment','migration') NOT NULL,
  channel       ENUM('sms','whatsapp','ussd') NOT NULL DEFAULT 'sms',

  -- Signed: positive in, negative out.
  amount        DECIMAL(16,4) NOT NULL,
  balance_after DECIMAL(16,4) NOT NULL,

  -- The other side of a transfer or sale, so both rows can be read
  -- together.
  counterparty_id INT UNSIGNED NULL,

  -- What caused it: a purchase, a campaign, an API call.
  ref_type      VARCHAR(30) NULL,
  ref_id        BIGINT UNSIGNED NULL,
  note          VARCHAR(255) NULL,

  -- Who did it, where that is a person. A staff user, or the portal
  -- login that pressed the button; NULL for the engine itself.
  actor_type    ENUM('staff','client_user','partner','system','api') NOT NULL DEFAULT 'system',
  actor_id      INT UNSIGNED NULL,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_bulk_ledger_account (account_id, created_at),
  KEY idx_bulk_ledger_ref     (ref_type, ref_id),
  CONSTRAINT fk_bulk_ledger_account FOREIGN KEY (account_id) REFERENCES bulk_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Sender IDs — the name a message arrives from
-- ---------------------------------------------------------------------
-- Registered with the networks through Onfon, which is why the office
-- approves them rather than a partner: a partner cannot register one on
-- a client's behalf any more than they could before.
CREATE TABLE IF NOT EXISTS bulk_sender_ids (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  sender_id     VARCHAR(20) NOT NULL COMMENT 'Case matters to the networks, so it is compared exactly',
  purpose       TEXT NULL,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reject_reason VARCHAR(255) NULL,

  -- The networks want a letter on the company's letterhead and its
  -- registration certificate. Paths under storage/, never public.
  application_letter VARCHAR(255) NULL,
  registration_cert  VARCHAR(255) NULL,

  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  decided_by    INT UNSIGNED NULL,
  decided_at    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_bulk_sender_account (account_id, status),
  KEY idx_bulk_sender_status  (status),
  CONSTRAINT fk_bulk_sender_account FOREIGN KEY (account_id) REFERENCES bulk_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_bulk_sender_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Contacts and groups
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bulk_contact_groups (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bulk_group_account (account_id),
  CONSTRAINT fk_bulk_group_account FOREIGN KEY (account_id) REFERENCES bulk_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bulk_contacts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  group_id      INT UNSIGNED NULL,
  name          VARCHAR(120) NULL,
  phone         VARCHAR(20) NOT NULL,
  email         VARCHAR(120) NULL,
  -- Any other columns from an import, for {placeholders} in a message.
  metadata      JSON NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bulk_contact_account (account_id),
  KEY idx_bulk_contact_group   (group_id, id),
  KEY idx_bulk_contact_phone   (account_id, phone),
  CONSTRAINT fk_bulk_contact_account FOREIGN KEY (account_id) REFERENCES bulk_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_bulk_contact_group   FOREIGN KEY (group_id)   REFERENCES bulk_contact_groups (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bulk_templates (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  title         VARCHAR(120) NOT NULL,
  message       TEXT NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bulk_template_account (account_id),
  CONSTRAINT fk_bulk_template_account FOREIGN KEY (account_id) REFERENCES bulk_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Campaigns — one message to many, sent in the background
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bulk_campaigns (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(180) NOT NULL,
  sender_id     VARCHAR(20) NOT NULL,
  message       TEXT NOT NULL,

  -- Exactly one audience is used, in this order of precedence by the
  -- engine: an uploaded file, a contact group, a typed list.
  group_id      INT UNSIGNED NULL,
  recipients    MEDIUMTEXT NULL COMMENT 'Comma-separated numbers when no group or file',
  file_path     VARCHAR(600) NULL COMMENT 'Uploaded CSV/XLSX under storage/, deleted once sent',

  total_count     INT UNSIGNED NOT NULL DEFAULT 0,
  processed_rows  INT UNSIGNED NOT NULL DEFAULT 0,
  -- Last contacts.id handed to the gateway, so a crashed worker resumes
  -- a group campaign exactly where it stopped rather than re-sending.
  resume_contact_id INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count      INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count    INT UNSIGNED NOT NULL DEFAULT 0,
  units_used      DECIMAL(14,4) NOT NULL DEFAULT 0.0000,

  status        ENUM('draft','scheduled','queued','sending','completed','failed','cancelled') NOT NULL DEFAULT 'draft',
  failure_reason VARCHAR(255) NULL,
  scheduled_at  DATETIME NULL,
  sent_at       DATETIME NULL,

  -- A worker stamps locked_at when it claims the campaign and
  -- last_heartbeat_at after every batch. A campaign sending with no
  -- heartbeat for a while has a dead worker, and cron hands it back.
  locked_at         DATETIME NULL,
  last_heartbeat_at DATETIME NULL,

  -- Where it was started from, and by whom.
  source        ENUM('portal','partner','admin','api') NOT NULL DEFAULT 'portal',
  actor_type    ENUM('staff','client_user','partner','system','api') NOT NULL DEFAULT 'system',
  actor_id      INT UNSIGNED NULL,

  legacy_id     INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_bulk_campaign_account   (account_id, created_at),
  KEY idx_bulk_campaign_status    (status, scheduled_at),
  KEY idx_bulk_campaign_heartbeat (status, last_heartbeat_at),
  UNIQUE KEY uq_bulk_campaign_legacy (legacy_id),
  CONSTRAINT fk_bulk_campaign_account FOREIGN KEY (account_id) REFERENCES bulk_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_bulk_campaign_group   FOREIGN KEY (group_id)   REFERENCES bulk_contact_groups (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Every message, one row each
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bulk_messages (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id   INT UNSIGNED NULL,
  account_id    INT UNSIGNED NOT NULL,
  sender_id     VARCHAR(20) NOT NULL,
  recipient     VARCHAR(20) NOT NULL,
  message       TEXT NOT NULL,
  -- Parts charged. Zero for a number that was never sent because it
  -- was not a phone number.
  units_charged DECIMAL(8,4) NOT NULL DEFAULT 1.0000,

  -- queued       accepted, not yet with the gateway
  -- sent         the gateway took it
  -- delivered    the handset confirmed it
  -- failed       refused at send time; units refunded
  -- undelivered  the gateway took it, the network could not deliver it
  status        ENUM('queued','sent','delivered','failed','undelivered') NOT NULL DEFAULT 'queued',

  gateway_msg_id VARCHAR(100) NULL,
  sent_at       DATETIME NULL,
  delivered_at  DATETIME NULL,
  failed_reason VARCHAR(500) NULL,
  -- The carrier's own word for it — DELIVRD, AbsentSubscriber, … — kept
  -- as sent, because the delivery report is laid out by these and has
  -- to agree with Onfon's report line for line.
  dlr_status    VARCHAR(60) NULL,

  source        ENUM('portal','partner','admin','api','system') NOT NULL DEFAULT 'portal',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_bulk_msg_campaign        (campaign_id, status),
  KEY idx_bulk_msg_account_created (account_id, created_at),
  KEY idx_bulk_msg_account_dlr     (account_id, created_at, dlr_status),
  KEY idx_bulk_msg_gateway         (gateway_msg_id),
  KEY idx_bulk_msg_created         (created_at),
  CONSTRAINT fk_bulk_msg_campaign FOREIGN KEY (campaign_id) REFERENCES bulk_campaigns (id) ON DELETE SET NULL,
  CONSTRAINT fk_bulk_msg_account  FOREIGN KEY (account_id)  REFERENCES bulk_accounts (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- What units cost
-- ---------------------------------------------------------------------
-- House plans are what we sell to direct clients and to partners. A
-- partner's plans are what they sell to their own clients, at prices
-- they set — the old platform's pricing_plans.owner_id.
CREATE TABLE IF NOT EXISTS bulk_plans (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_account_id INT UNSIGNED NOT NULL COMMENT 'The house, or a partner account',
  name          VARCHAR(80) NOT NULL,
  units         INT UNSIGNED NOT NULL,
  price         DECIMAL(12,2) NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  is_popular    TINYINT(1) NOT NULL DEFAULT 0,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bulk_plan_owner (owner_account_id, is_active),
  CONSTRAINT fk_bulk_plan_owner FOREIGN KEY (owner_account_id) REFERENCES bulk_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Buying units
-- ---------------------------------------------------------------------
-- The seller is recorded on the purchase, not worked out when it
-- completes: a client who changes partner halfway through a payment
-- must still be served by the partner they paid.
CREATE TABLE IF NOT EXISTS bulk_purchases (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL COMMENT 'The buyer',
  seller_account_id INT UNSIGNED NOT NULL COMMENT 'The house, or the buyer''s partner',

  channel       ENUM('sms','whatsapp','ussd') NOT NULL DEFAULT 'sms',
  plan_id       INT UNSIGNED NULL,
  units         DECIMAL(14,4) NOT NULL DEFAULT 0.0000 COMMENT 'SMS units; 0 for a money top-up',
  amount        DECIMAL(14,2) NOT NULL,
  unit_price    DECIMAL(10,4) NULL COMMENT 'As charged, so a later price change does not rewrite it',

  -- mpesa_stk     an STK push to the buyer's phone; completes itself
  -- manual_mpesa  paid to the seller's till or number, then approved by
  --               the seller — how a partner's clients pay the partner
  -- bank, cash    recorded by the office
  -- complimentary given, no money
  method        ENUM('mpesa_stk','manual_mpesa','bank','cash','complimentary') NOT NULL DEFAULT 'mpesa_stk',
  phone         VARCHAR(20) NULL,
  transaction_ref VARCHAR(100) NULL COMMENT 'M-Pesa code or bank reference',
  gateway_ref   VARCHAR(255) NULL COMMENT 'Kopo Kopo payment resource, to confirm a push the webhook missed',

  status        ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  failure_reason VARCHAR(255) NULL,
  decided_by_type ENUM('staff','partner','system') NULL,
  decided_by_id   INT UNSIGNED NULL,
  completed_at  DATETIME NULL,

  actor_type    ENUM('staff','client_user','partner','system','api') NOT NULL DEFAULT 'system',
  actor_id      INT UNSIGNED NULL,
  legacy_id     INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_bulk_purchase_account (account_id, created_at),
  KEY idx_bulk_purchase_seller  (seller_account_id, status),
  KEY idx_bulk_purchase_status  (status, created_at),
  UNIQUE KEY uq_bulk_purchase_legacy (legacy_id),
  CONSTRAINT fk_bulk_purchase_account FOREIGN KEY (account_id)        REFERENCES bulk_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_bulk_purchase_seller  FOREIGN KEY (seller_account_id) REFERENCES bulk_accounts (id) ON DELETE RESTRICT,
  CONSTRAINT fk_bulk_purchase_plan    FOREIGN KEY (plan_id)           REFERENCES bulk_plans (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- API rate limiting
-- ---------------------------------------------------------------------
-- One row per key per minute, incremented atomically. The old platform's
-- limits carry over: 60 a minute for single sends, 10 for bulk.
CREATE TABLE IF NOT EXISTS bulk_rate_counters (
  bucket        VARCHAR(100) NOT NULL,
  window_start  DATETIME NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
-- The Onfon credentials are secrets and are encrypted by Settings on
-- save; they start blank. Everything else carries the old platform's
-- defaults.
INSERT INTO settings (setting_key, setting_value) VALUES
  ('bulk_sms_enabled',          '1'),
  ('onfon_client_id',           ''),
  ('onfon_api_key',             ''),
  ('onfon_access_key',          ''),
  -- Pause between gateway batches, so a large campaign does not trip
  -- Onfon's rate limit.
  ('onfon_batch_delay_ms',      '100'),
  -- What a unit costs a direct client who buys a custom amount rather
  -- than a plan.
  ('bulk_sms_unit_price',       '1.00'),
  -- Below this, an account's owner gets one warning a day. 0 = off.
  ('bulk_sms_low_balance',      '0'),
  -- Optional shared secret on the delivery-report URL (?token=…).
  ('bulk_sms_dlr_token',        '')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- The house's starting plans — the old platform's defaults. Only when
-- there are none, so re-running never duplicates them and a migration
-- from the live platform can replace them.
INSERT INTO bulk_plans (owner_account_id, name, units, price, is_popular, sort_order)
SELECT a.id, p.name, p.units, p.price, p.popular, p.sort_order
  FROM bulk_accounts a
  JOIN (SELECT 'Starter' AS name, 100 AS units, 50.00 AS price, 0 AS popular, 1 AS sort_order
        UNION ALL SELECT 'Basic',       500,   200.00, 0, 2
        UNION ALL SELECT 'Standard',   1000,   350.00, 1, 3
        UNION ALL SELECT 'Business',   5000,  1500.00, 0, 4
        UNION ALL SELECT 'Enterprise',10000,  2500.00, 0, 5) p
 WHERE a.owner_type = 'house'
   AND NOT EXISTS (SELECT 1 FROM bulk_plans bp WHERE bp.owner_account_id = a.id);
