-- =====================================================================
-- Migration 043 — what an SMS account prefers, and what a reseller's
-- clients see
--
-- Three small things the old platform had and this did not:
--
--   A default sender ID, so somebody with four approved names does not
--   have to pick the same one every time.
--
--   Per-account choice of how they are told about their own account.
--   The system-wide switches decide whether an event is sent at all;
--   these decide whether this particular customer wants it by e-mail,
--   by text, or both. Both start on, which is what the old platform
--   did — a customer whose balance runs out mid-campaign would rather
--   have been texted about it. Anybody who would rather not pay a unit
--   for that can turn it off on their own settings page.
--
--   A reseller's own name and contact details. Their clients buy units
--   from them and pay them directly, so the Buy page has to say who to
--   pay and how — "contact us" is no use when "us" is the wrong company.
--   This is not white-labelling the whole portal: one domain, one
--   system, and a customer always knows whose system they are in.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE bulk_accounts
  ADD COLUMN default_sender_id VARCHAR(20) NULL AFTER status;

ALTER TABLE bulk_accounts
  ADD COLUMN alert_email TINYINT(1) NOT NULL DEFAULT 1 AFTER default_sender_id;

ALTER TABLE bulk_accounts
  ADD COLUMN alert_sms TINYINT(1) NOT NULL DEFAULT 1 AFTER alert_email;

-- A reseller's own details, shown to the clients they supply.
ALTER TABLE bulk_accounts
  ADD COLUMN brand_name VARCHAR(120) NULL AFTER resale_unit_price;

ALTER TABLE bulk_accounts
  ADD COLUMN brand_support_email VARCHAR(160) NULL AFTER brand_name;

ALTER TABLE bulk_accounts
  ADD COLUMN brand_support_phone VARCHAR(30) NULL AFTER brand_support_email;

ALTER TABLE bulk_accounts
  ADD COLUMN brand_pay_instructions TEXT NULL AFTER brand_support_phone;
