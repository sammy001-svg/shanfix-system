-- =====================================================================
-- Migration 048 — user login OTPs
--
-- One-time verification codes for main system / staff user login.
-- Requires OTP verification on email and SMS after correct email/password.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_otps (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  email          VARCHAR(160) NOT NULL,
  code_hash      VARCHAR(255) NOT NULL,
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at     DATETIME NOT NULL,
  consumed_at    DATETIME NULL,
  requested_ip   VARCHAR(45) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_user_otps_user (user_id, consumed_at),
  KEY idx_user_otps_email (email, consumed_at),
  KEY idx_user_otps_expiry (expires_at),

  CONSTRAINT fk_user_otps_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('user_otp_enabled', '1'),
  ('user_otp_minutes', '10'),
  ('user_otp_per_hour', '10')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
