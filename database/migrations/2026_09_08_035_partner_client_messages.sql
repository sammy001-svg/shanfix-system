-- =====================================================================
-- Migration 035 — telling a partner what is happening on their account
--
-- A partner cannot raise a quotation, an invoice or a payment. That is
-- ours to do, deliberately: they introduce and they earn, we bill and we
-- collect. The consequence is that everything which moves their money
-- happens out of their sight, and without these messages the only way
-- they would learn of it is by signing in and looking.
--
-- Three moments matter to them:
--
--   * a customer they registered has been taken on;
--   * we have quoted or invoiced that customer, so work is happening;
--   * that customer has paid, which is the moment commission is earned.
--
-- Registered in Notifier::EVENTS as well as written here. An event
-- missing from that list still sends — with the raw event name as the
-- subject, an empty body, and no SMS at all, because queueSms refuses an
-- event it has no template for.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
  -- Their customer is on our books. Says plainly that being registered is
  -- not the same as having ordered anything, because the difference is
  -- what they will be paid on.
  ('tpl_partner_client_registered_subject', '{company}: {client_name} is registered to you'),
  ('tpl_partner_client_registered_intro',
   'Thank you — {client_name} is now on our books against your account, and anything they spend with us earns you commission. We will be in touch with them about what they need. You can follow it in your partner portal.'),
  ('tpl_sms_partner_client_registered',
   '{company}: {client_name} is now registered to you. Anything they spend with us earns you commission.'),

  -- We have quoted or invoiced their customer. {doc_type} carries the
  -- word, so one template covers both rather than drifting apart.
  ('tpl_partner_document_subject', '{company}: {doc_type} {doc_number} for {client_name}'),
  ('tpl_partner_document_intro',
   'We have raised {doc_type} {doc_number} for {client_name}, for {amount}. Nothing is owed to you yet — commission is earned when the customer pays us, and you can see it building in your partner portal.'),
  ('tpl_sms_partner_document',
   '{company}: {doc_type} {doc_number} for {client_name}, {amount}. Commission follows when they pay.'),

  -- The one that is actually money. Said in the same breath as the
  -- payment, because this is the moment they have been waiting for.
  ('tpl_partner_payment_subject', '{company}: {client_name} has paid {amount}'),
  ('tpl_partner_payment_intro',
   '{client_name} has paid {amount} against {doc_number}. That earns you {commission} in commission, which will go out with the next monthly payment run. Every entry behind it is in your partner portal.'),
  ('tpl_sms_partner_payment',
   '{company}: {client_name} paid {amount}. You have earned {commission}.')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- ---------------------------------------------------------------------
-- Turn them on
-- ---------------------------------------------------------------------
-- On by default, unlike most events. These are not marketing: a partner
-- who is not told when their customer pays has no way of checking what
-- they are owed against what we say they are owed, and the whole point
-- of the arrangement is that both sides can see the same figures.
--
-- SMS is on for the two that carry money and off for the document, which
-- is news rather than something to act on — a text per quotation would
-- be noise, and it costs us per part.
INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_partner_client_registered_email', '1'),
  ('notify_partner_client_registered_sms',   '1'),
  ('notify_partner_document_email',          '1'),
  ('notify_partner_document_sms',            '0'),
  ('notify_partner_payment_email',           '1'),
  ('notify_partner_payment_sms',             '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
