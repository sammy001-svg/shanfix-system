-- =====================================================================
-- Migration 063 — Demo requests for the systems we sell
--
-- The website now has a page for Sacco Pro, and the point of that page
-- is the Request a demo button on it. This is where those requests land
-- and how they are answered.
--
-- The flow, end to end:
--
--   1. A visitor fills in the form on the website.
--   2. The request lands here and a lead is raised beside it, so it is
--      in the sales pipeline like any other enquiry rather than in a
--      list somebody has to remember to look at.
--   3. An administrator reads it and either declines it or accepts it,
--      entering the address of the demo and the login they have set up.
--   4. The client is sent those details by e-mail and by text.
--
-- Step 4 is the reason this table holds a password at all, and it is
-- the part that needed care.
--
-- WHAT IS DONE ABOUT THE PASSWORD
--
-- It is encrypted here with the same key the settings secrets use, so a
-- dump of this table is not a list of live demo logins. It is never
-- written to the activity log. And every demo gets an expiry, so a
-- login handed out to somebody who never came back stops working.
--
-- What is NOT solved, and should be understood: the message that
-- carries it is kept in the notifications queue like every other
-- message, and the rendered body of that message contains the password
-- in the clear. Anyone who can read the notifications table can read
-- what was sent. That is true of every password any system e-mails, and
-- the honest fix is not to send one — to send a link that lets the
-- person set their own. That is a change to Sacco Pro rather than to
-- this, so it is written down here rather than quietly assumed.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS demo_requests (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference       VARCHAR(20)  NOT NULL,

  -- Which system they want to see. One page today; the same table takes
  -- the next one without a migration, which is the point of a column
  -- rather than a table called sacco_demo_requests.
  product         VARCHAR(40)  NOT NULL DEFAULT 'sacco',

  -- Who asked.
  name            VARCHAR(160) NOT NULL,
  organisation    VARCHAR(180) DEFAULT NULL,
  role            VARCHAR(120) DEFAULT NULL,
  email           VARCHAR(190) NOT NULL,
  phone           VARCHAR(40)  NOT NULL,
  members         VARCHAR(40)  DEFAULT NULL COMMENT 'rough size, as a band — nobody knows it exactly',
  message         TEXT         DEFAULT NULL,

  -- Where it came from, for working out which page actually sells.
  source_page     VARCHAR(120) DEFAULT NULL,
  source_ip       VARBINARY(16) DEFAULT NULL,

  status          ENUM('new','accepted','declined','expired') NOT NULL DEFAULT 'new',

  -- What was handed over, once somebody accepts.
  demo_url        VARCHAR(255) DEFAULT NULL,
  demo_username   VARCHAR(160) DEFAULT NULL,
  demo_password   VARBINARY(512) DEFAULT NULL COMMENT 'encrypted; never logged, never shown after sending',
  expires_on      DATE         DEFAULT NULL,

  decided_by      INT UNSIGNED DEFAULT NULL,
  decided_at      DATETIME     DEFAULT NULL,
  decline_reason  VARCHAR(400) DEFAULT NULL,
  sent_at         DATETIME     DEFAULT NULL,

  -- The lead raised alongside it, so the two screens agree.
  lead_id         INT UNSIGNED DEFAULT NULL,

  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_reference (reference),
  KEY idx_queue (status, created_at),
  KEY idx_product (product, status),
  KEY idx_expiry (status, expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The message that carries the login
--
-- Registered as a notification event like every other, so it gets the
-- same template editing, the same per-channel switches and the same
-- queue. Both channels are on by default, which is unusual here — the
-- whole feature is "tell them", and a demo accepted that never reaches
-- the client is worse than no demo at all.
--
-- The templates below are the default wording. They are settings, so
-- they can be rewritten on the Settings screen without a deployment.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_demo_ready_email', '1'),
  ('notify_demo_ready_sms',   '1'),

  ('tpl_demo_ready_subject', 'Your {product} demo is ready'),
  ('tpl_demo_ready_intro',
   'Thank you for asking to see {product}. Your demo account is ready — the address, your username and your password are below, and the account is yours until {expires}.'),

  -- Kept short on purpose: a login and a link already take most of a
  -- text message, and a second part costs again on every one sent.
  ('tpl_sms_demo_ready',
   '{company_name}: your {product} demo is ready. {link} User: {username} Pass: {password}'),

  -- How long a demo lasts, in days, when nobody says otherwise.
  ('demo_default_days', '14')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- The reference prefix, alongside the other series.
INSERT INTO settings (setting_key, setting_value) VALUES ('demo_prefix', 'DEMO')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
