-- =====================================================================
-- Migration 049 — Telling somebody a visitor is waiting
--
-- Migration 045 built the live chat and the departments; what it never
-- built was the part that taps somebody on the shoulder. Since tawk.to
-- came off the website, a stranger who starts a chat reaches a queue
-- that nobody is told about: the only signal is a badge in the sidebar,
-- and only if a member of staff happens to be looking at a system page.
-- Departments::escalateTo() was written for this and has been called
-- from nowhere since the day it was added.
--
-- Two things arrive here. A conversation now remembers that its leads
-- have already been warned about it, so the warning goes out once
-- rather than every time the cron runs. And the office gets switches
-- for how loudly to be told.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- When the leads were told
--
-- NULL means nobody has been warned about this one yet. It is stamped
-- rather than derived from staff_notifications because a notification
-- somebody deletes from their bell must not cause the same alarm to be
-- raised again — the question being answered here is "have we already
-- shouted about this?", not "is there still a row somewhere".
--
-- Cleared when a conversation goes quiet and is later re-opened by a new
-- message, because that is a fresh wait and deserves a fresh alarm.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'live_conversations' AND column_name = 'escalated_at');

SET @sql := IF(@col = 0,
  'ALTER TABLE live_conversations
     ADD COLUMN escalated_at DATETIME DEFAULT NULL AFTER first_reply_at',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The sweep asks for waiting conversations that have not been escalated,
-- oldest first. Without this it is a scan of the whole table every run.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = 'live_conversations'
                AND index_name = 'idx_live_conv_escalate');

SET @sql := IF(@idx = 0,
  'ALTER TABLE live_conversations
     ADD KEY idx_live_conv_escalate (status, escalated_at, created_at)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Settings
--
-- livechat_alert_after already exists from 045 — the minutes a
-- conversation may sit unanswered before its leads hear about it.
--
-- The split between the bell and the rest is deliberate. Everybody in
-- the department gets the bell the moment a chat arrives, because it
-- costs nothing and it is the signal people actually work from. E-mail
-- and SMS are held back for the escalation, when nobody has picked it
-- up: mailing five people about every question would teach all five to
-- filter the mail, and then the one that mattered goes unread too.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  -- E-mail the department's leads when a chat has waited too long.
  ('livechat_alert_email',  '1'),
  -- Text them as well. Off by default: it costs money per message and
  -- it wakes people up, so it is a decision the office makes knowingly.
  ('livechat_alert_sms',    '0'),
  -- Whether the desk makes a sound when somebody new joins the queue.
  -- An office-wide default; each person can still silence their own.
  ('livechat_alert_sound',  '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
