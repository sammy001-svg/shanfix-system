-- =====================================================================
-- Migration 003 — Move SMS onto Shanfix Bulk SMS
--
-- Replaces the Africa's Talking gateway with our own bulk SMS platform
-- at https://sms.shanfixtechnology.com. Authentication changes from
-- username + API key to Client ID + API key, both taken from the
-- portal's Developer / API page.
--
-- Any Africa's Talking key already stored is useless against the new
-- gateway, so it is cleared and SMS is switched off until an operator
-- enters the new credentials in Settings. Nothing is sent in between.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Remember whether this install was still on Africa's Talking before we
-- rewrite the provider, so the reset below only touches legacy configs
-- and never wipes credentials someone has already re-entered.
-- ---------------------------------------------------------------------
SET @legacy_at := (
  SELECT COUNT(*) FROM settings
   WHERE setting_key = 'sms_provider' AND setting_value = 'africastalking'
);

-- New credential keys. Existing values are kept on re-run.
INSERT INTO settings (setting_key, setting_value) VALUES
  ('sms_provider',  'shanfix'),
  ('sms_client_id', ''),
  ('sms_base_url',  'https://sms.shanfixtechnology.com'),
  ('sms_api_key',   ''),
  ('sms_sender_id', ''),
  ('sms_enabled',   '0')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- Retire the Africa's Talking key and pause sending until reconfigured.
UPDATE settings SET setting_value = '' WHERE setting_key = 'sms_api_key' AND @legacy_at = 1;
UPDATE settings SET setting_value = '0' WHERE setting_key = 'sms_enabled' AND @legacy_at = 1;

-- Now the provider itself.
UPDATE settings SET setting_value = 'shanfix' WHERE setting_key = 'sms_provider';

-- The gateway username has no equivalent on the new API.
DELETE FROM settings WHERE setting_key = 'sms_username';
