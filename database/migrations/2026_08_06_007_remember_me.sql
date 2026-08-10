-- =====================================================================
-- Migration 007 — "Keep me signed in"
--
-- Persistent login uses the selector/validator pattern rather than
-- storing anything derived from the password:
--
--   cookie      =  selector : validator      (validator is the secret)
--   database    =  selector , sha256(validator)
--
-- The selector is looked up directly, so a stolen database gives an
-- attacker only hashes. The validator is compared with hash_equals, so
-- the check is not timing-sensitive, and it is replaced on every use, so
-- a captured cookie stops working the moment the real user returns.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  selector        CHAR(24)     NOT NULL,   -- public half, looked up directly
  validator_hash  CHAR(64)     NOT NULL,   -- sha256 of the secret half
  expires_at      DATETIME     NOT NULL,
  -- Kept so a user can recognise their own sessions, and so an unfamiliar
  -- device is obvious if one ever needs investigating.
  user_agent      VARCHAR(255) DEFAULT NULL,
  ip_address      VARCHAR(45)  DEFAULT NULL,
  last_used_at    DATETIME     DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_remember_selector (selector),
  KEY idx_remember_user (user_id),
  KEY idx_remember_expiry (expires_at),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- How long "keep me signed in" lasts, and whether it is offered at all.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('remember_me_enabled', '1'),
  ('remember_me_days',    '30')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
