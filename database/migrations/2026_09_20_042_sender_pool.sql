-- =====================================================================
-- Migration 042 — sender IDs we hold, before anybody is using them
--
-- Sender IDs are registered with the mobile networks through Onfon, and
-- that is where the real list lives. Until now a row here had to belong
-- to an account, so a name we had registered but not yet given to anyone
-- could not be recorded at all — the office kept that list in its head,
-- or in the Onfon portal, and a customer asking for a name we already
-- held got told to wait days for nothing.
--
-- account_id becomes optional. A row with none is one we hold and have
-- not yet given out; assigning it to an account is one click, and that
-- customer can send under it immediately.
--
-- Nothing can send from a pooled name by accident: the engine only ever
-- matches a sender against the account asking to use it, so a row with
-- no account matches nobody.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE bulk_sender_ids
  MODIFY COLUMN account_id INT UNSIGNED NULL
    COMMENT 'NULL = registered with the networks but not yet given to anybody';

-- Who put it here: a customer asking for it, or the office recording one
-- it already holds. The two read differently on screen — one is a
-- request, the other is stock.
ALTER TABLE bulk_sender_ids
  ADD COLUMN source ENUM('customer','office') NOT NULL DEFAULT 'customer' AFTER status;

ALTER TABLE bulk_sender_ids
  ADD KEY idx_bulk_sender_pool (account_id, status);

INSERT INTO settings (setting_key, setting_value) VALUES
  -- Whether customers may buy SMS units with M-Pesa. Separate from the
  -- system-wide payment switch so SMS can be turned off on its own
  -- without stopping invoices being paid.
  ('bulk_sms_mpesa',        '1'),
  -- Which websites may call the SMS API from a browser. Empty means any,
  -- which is what the old platform did: the key authorises the call, not
  -- the page it came from.
  ('bulk_sms_cors_origins', '')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
