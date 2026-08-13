-- =====================================================================
-- Migration 010 — recurring services
--
-- The things a client keeps paying for rather than buys once: a website
-- we built and now host, a domain, a maintenance retainer. Until now
-- these lived in people's heads and a spreadsheet, and a renewal was
-- remembered or it was not.
--
-- Two tables, because a subscription and a billing period are different
-- things. The subscription is the standing arrangement — what it is, what
-- it costs, when it next falls due. Each renewal is one period of it, and
-- carries the invoice raised for that period. Keeping periods separate is
-- what makes "what did we bill this site last year" answerable, and stops
-- a second invoice being raised for a period already billed.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS subscriptions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id      INT UNSIGNED NOT NULL,
  -- Optional link to the services catalogue, so a renewal invoice can
  -- describe itself the same way a quoted service does.
  service_id     INT UNSIGNED DEFAULT NULL,

  name           VARCHAR(180) NOT NULL,
  -- The live address, for websites and hosting. Nullable: a maintenance
  -- retainer has no URL.
  url            VARCHAR(500) DEFAULT NULL,
  service_type   VARCHAR(30)  NOT NULL DEFAULT 'website',

  amount         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  currency       VARCHAR(5)    NOT NULL DEFAULT 'KES',

  -- monthly | quarterly | semiannual | annual | custom
  billing_cycle  VARCHAR(20)  NOT NULL DEFAULT 'annual',
  -- Only consulted when billing_cycle = 'custom'.
  cycle_days     SMALLINT UNSIGNED NOT NULL DEFAULT 365,

  start_date         DATE NOT NULL,
  next_renewal_date  DATE NOT NULL,
  last_invoiced_on   DATE DEFAULT NULL,

  -- active | paused | cancelled
  status         VARCHAR(20) NOT NULL DEFAULT 'active',

  -- Raise the invoice automatically when the renewal comes round, rather
  -- than only reminding someone to do it by hand.
  auto_invoice   TINYINT(1) NOT NULL DEFAULT 0,
  -- Days before renewal to chase, e.g. "30,14,7,1". Blank falls back to
  -- the system-wide setting.
  reminder_days  VARCHAR(60) NOT NULL DEFAULT '',

  notes          TEXT DEFAULT NULL,
  created_by     INT UNSIGNED DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_sub_client (client_id),
  KEY idx_sub_status (status),
  -- The renewal sweep runs daily over exactly this pair.
  KEY idx_sub_due (status, next_renewal_date),

  CONSTRAINT fk_sub_client  FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
  CONSTRAINT fk_sub_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- One row per billing period.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscription_renewals (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  period_start    DATE NOT NULL,
  period_end      DATE NOT NULL,
  amount          DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  -- The invoice raised for this period, once there is one.
  document_id     INT UNSIGNED DEFAULT NULL,
  -- due | invoiced | paid | skipped
  status          VARCHAR(20) NOT NULL DEFAULT 'due',

  invoiced_at     DATETIME DEFAULT NULL,
  paid_at         DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- A period is billed once. This is what makes the daily sweep safe to
  -- run twice in a day, or to catch up after the server was down.
  UNIQUE KEY uq_renewal_period (subscription_id, period_start),
  KEY idx_renewal_status (status),
  KEY idx_renewal_document (document_id),

  CONSTRAINT fk_renewal_sub FOREIGN KEY (subscription_id)
    REFERENCES subscriptions(id) ON DELETE CASCADE,
  CONSTRAINT fk_renewal_doc FOREIGN KEY (document_id)
    REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings: when to chase a renewal, and the wording used.
-- Existing values are never overwritten.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_renewal_days',          '30,14,7,1'),
  ('subscription_invoice_lead',    '14'),
  ('notify_renewal_due_email',     '1'),
  ('notify_renewal_due_sms',       '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('tpl_renewal_due_subject',
   '{service_name} renews on {renewal_date}'),
  ('tpl_renewal_due_intro',
   'This is a reminder that {service_name} is due for renewal on {renewal_date}, {days_to_renewal} day(s) from now. The renewal amount is {amount}. Let us know if anything should change before we invoice it.'),
  ('tpl_sms_renewal_due',
   '{company}: {service_name} renews on {renewal_date} ({amount}). Reply or call {company_phone} to make changes.')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
