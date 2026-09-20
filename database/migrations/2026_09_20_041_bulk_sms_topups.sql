-- =====================================================================
-- Migration 041 — paying for SMS units by M-Pesa
--
-- The system already prompts a customer's phone to pay an invoice, and
-- that is exactly what buying SMS units needs. Rather than a second
-- M-Pesa integration beside the first, stk_requests learns what a prompt
-- is for: an invoice, or a top-up of an SMS account.
--
-- Two shapes had to give:
--
--   client_id was NOT NULL, because every prompt was for an invoice and
--   every invoice belongs to a client. A partner buying units is not a
--   client, so a prompt can now belong to no client at all.
--
--   bulk_purchase_id says which purchase to hand the units over for when
--   the money lands. Kept beside document_id rather than reusing it: the
--   two mean different things and a column that means one thing on
--   Tuesday and another on Wednesday is how money ends up in the wrong
--   place.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE stk_requests
  MODIFY COLUMN client_id INT UNSIGNED NULL COMMENT 'NULL when the payer is not a client — a partner topping up SMS';

ALTER TABLE stk_requests
  ADD COLUMN purpose ENUM('invoice','bulk_sms') NOT NULL DEFAULT 'invoice' AFTER document_id;

ALTER TABLE stk_requests
  ADD COLUMN bulk_purchase_id INT UNSIGNED NULL AFTER purpose;

ALTER TABLE stk_requests
  ADD KEY idx_stk_purchase (bulk_purchase_id);
