-- =====================================================================
-- Migration 019 — telling people about chat messages
--
-- Settings for alerting someone that a message is waiting.
--
-- The reason a delay exists at all: a chat is a back-and-forth, and a
-- message-by-message alert would text somebody a dozen times over one
-- conversation. Each of those costs an SMS unit and none of them tells
-- the recipient anything the first did not. So a person is told at most
-- once per conversation per window, and only when they are not already
-- looking at it.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
  -- Master switch, so the whole behaviour can be turned off without
  -- unpicking anything.
  ('chat_alerts_enabled',   '1'),

  -- Minutes before the same person may be alerted again about the same
  -- conversation. Raise it if the team finds the alerts noisy.
  ('chat_alert_cooldown',   '15'),

  -- How recently someone must have looked at a conversation to count as
  -- being in it. Anyone reading right now needs no email.
  ('chat_alert_active_mins', '3'),

  ('notify_chat_message_email', '1'),
  ('notify_chat_message_sms',   '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
