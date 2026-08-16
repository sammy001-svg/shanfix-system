-- =====================================================================
-- Migration 009 — Sending statements
--
-- Two things.
--
-- First, a correction. Migration 008 added statement_sent_at, but the
-- only thing writing it was the client opening their link — so a column
-- named "sent" was recording a view. Anyone reading that data later
-- would have drawn the wrong conclusion. The view now has its own
-- column, and statement_sent_at is reset so it only ever means what its
-- name says.
--
-- Second, the statement becomes something you can actually send: an
-- event with its own email and SMS templates, and a monthly run to
-- everyone carrying a balance.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'clients' AND column_name = 'statement_viewed_at');

SET @sql := IF(@col = 0,
  'ALTER TABLE clients
     ADD COLUMN statement_viewed_at DATETIME DEFAULT NULL AFTER statement_sent_at',
  'SELECT "clients.statement_viewed_at already present"');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Whatever sits in statement_sent_at today was written by a client
-- opening the link, not by us sending anything. Move it where it belongs.
UPDATE clients
   SET statement_viewed_at = statement_sent_at
 WHERE statement_sent_at IS NOT NULL AND statement_viewed_at IS NULL;

UPDATE clients SET statement_sent_at = NULL WHERE statement_sent_at IS NOT NULL;

-- ---------------------------------------------------------------------
-- When the monthly run goes out. 0 switches it off.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_statement_day', '1'),
  ('notify_statement_sent_email', '1'),
  ('notify_statement_sent_sms',   '0')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- ---------------------------------------------------------------------
-- Templates. Extra placeholders here: {balance} {overdue} {oldest_days}
-- {invoice_count} {statement_month}
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('tpl_statement_sent_subject', 'Statement of account from {company_name}'),
  ('tpl_statement_sent_intro',
   'Please find your statement of account below, showing everything invoiced and every payment received. The balance outstanding is {balance}.'),

  ('tpl_sms_statement_sent',
   'Hi {contact_name}, your statement from {company_name} shows {balance} outstanding. View: {link}')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
