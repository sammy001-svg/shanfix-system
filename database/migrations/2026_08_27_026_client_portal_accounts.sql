-- =====================================================================
-- Migration 026 — client portal accounts
--
-- The first half of the client portal: getting in. Everything else the
-- portal does depends on knowing who is asking.
--
-- Portal logins live in their own table, deliberately not in `users`.
-- Staff accounts carry roles and permissions that reach the whole
-- business; a client account must never be able to become one by
-- accident, and a bug in one table should not be able to hand out access
-- in the other. Two tables, two session keys, two guards.
--
-- A company can have several people who need to see its invoices, so a
-- client account belongs to a client rather than being one.
--
-- Three ways in, because a client can be in three states:
--   * new to us            — they register, and a client record is made
--   * on file with email   — a code to that address proves it is them
--   * on file, no email    — they ask, and an administrator vouches
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS client_users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- The company or person they are allowed to see. Set once a request is
  -- approved or an email is verified against an existing record; null
  -- only for the moments before that.
  client_id         INT UNSIGNED NULL,

  name              VARCHAR(140) NOT NULL,
  email             VARCHAR(160) NOT NULL,
  phone             VARCHAR(30)  NULL,
  password_hash     VARCHAR(255) NULL COMMENT 'Null until they have set one',

  -- pending    registered or requested, not yet able to sign in
  -- active     verified and able to sign in
  -- suspended  turned off by an administrator
  status            ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',

  email_verified_at DATETIME NULL,
  last_login_at     DATETIME NULL,
  last_login_ip     VARCHAR(45) NULL,

  -- Rising delay after repeated failures, same idea as the staff login.
  failed_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until      DATETIME NULL,

  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_client_users_email (email),
  KEY idx_client_users_client (client_id, status),

  CONSTRAINT fk_client_users_client
    FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time codes, for proving an email address or resetting a password.
--
-- The code is stored hashed. A one-time code is a credential for as long
-- as it lives, and a leaked database should not hand somebody a working
-- one; the same reasoning that keeps passwords hashed applies here.
CREATE TABLE IF NOT EXISTS client_otps (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email          VARCHAR(160) NOT NULL,
  code_hash      VARCHAR(255) NOT NULL,

  -- verify_email  proving an address at sign-up
  -- reset         setting a forgotten password
  purpose        ENUM('verify_email','reset') NOT NULL DEFAULT 'verify_email',

  -- A wrong guess costs an attempt. Five, then the code is dead and a new
  -- one has to be asked for — six digits is only a million, and without a
  -- limit that is a short afternoon's work.
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,

  expires_at     DATETIME NOT NULL,
  consumed_at    DATETIME NULL,
  requested_ip   VARCHAR(45) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_client_otps_email (email, purpose, consumed_at),
  KEY idx_client_otps_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For a client already on file whose email we do not have.
--
-- They give a name and a phone number. Nothing is confirmed to them at
-- that point — telling somebody "no client of that name exists" would let
-- a stranger map the client list a guess at a time. An administrator sees
-- the request, and the match against the client record is checked when
-- they approve it, not when it is submitted.
CREATE TABLE IF NOT EXISTS client_access_requests (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,

  full_name      VARCHAR(140) NOT NULL,
  phone          VARCHAR(30)  NOT NULL,
  email          VARCHAR(160) NULL COMMENT 'Where they would like the account, if given',
  note           VARCHAR(255) NULL,

  -- pending   waiting for an administrator
  -- approved  matched to a client and a code texted
  -- rejected  no match, or refused
  status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',

  matched_client_id INT UNSIGNED NULL,
  decided_by     INT UNSIGNED NULL,
  decided_at     DATETIME NULL,
  decision_note  VARCHAR(255) NULL,

  requested_ip   VARCHAR(45) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_access_requests_status (status, created_at),

  CONSTRAINT fk_access_requests_client
    FOREIGN KEY (matched_client_id) REFERENCES clients (id) ON DELETE SET NULL,
  CONSTRAINT fk_access_requests_user
    FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  -- The portal as a whole, in one switch.
  ('portal_enabled', '1'),

  -- Whether somebody we have never heard of may create an account. Off
  -- makes the portal invitation-only.
  ('portal_self_signup', '1'),

  -- Minutes a one-time code stays good for. Long enough to find the
  -- email on a slow connection, short enough that a screenshot in a chat
  -- is not a lasting key.
  ('portal_otp_minutes', '10'),

  -- Codes one address may ask for in an hour, so the portal cannot be
  -- used to send somebody a hundred texts or emails.
  ('portal_otp_per_hour', '5')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('tpl_client_otp_subject', 'Your {company} verification code'),
  ('tpl_client_otp_intro',
   'Use the code below to finish setting up your account. It is good for {minutes} minutes, and nobody from {company} will ever ask you for it.'),
  ('tpl_sms_client_otp',
   '{company}: your verification code is {code}. It expires in {minutes} minutes. Do not share it with anyone.')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
