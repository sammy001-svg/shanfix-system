-- =====================================================================
-- Migration 020 — backups
--
-- Everything this company has sold, been paid, owes and is owed lives in
-- one database on shared hosting. Until now nothing copied it anywhere.
--
-- The schedule is deliberately a number of hours rather than a time of
-- day: cPanel cron granularity varies between hosts, and "at least this
-- long since the last one" behaves the same however often cron fires.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
  -- Master switch. Off stops the scheduled backup; the button on the
  -- settings page still works, so a copy can always be taken by hand.
  ('backup_enabled',      '1'),

  -- Hours between scheduled backups.
  ('backup_every_hours',  '24'),

  -- How many to keep on the server. Shared hosting sells a fixed disk
  -- quota and a backup that fills it takes the website down with it, so
  -- the old ones are dropped rather than allowed to accumulate.
  ('backup_keep',         '7'),

  -- Whether to archive storage/uploads alongside the database. The
  -- database knows a file was attached; only the uploads folder has the
  -- file, so a database-only copy restores a system full of broken links.
  ('backup_uploads',      '1'),

  -- Days without a successful backup before the administrators are told.
  -- A backup that silently stopped running two months ago is worse than
  -- no backup, because everybody believes there is one.
  ('backup_warn_days',    '3')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
