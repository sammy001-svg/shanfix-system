-- =====================================================================
-- Migration 004 — Cover the whole client journey with notifications
--
-- Adds nine client-facing events to the five already in place, each with
-- an email subject, an email opening line and an SMS body. Both channels
-- are switched on by default, so review the templates in
-- Settings -> Messaging before the next cron run.
--
-- Also fills a gap: quotation_sent had no SMS template, so its SMS was
-- always skipped even when the toggle was on.
--
-- Placeholders available: {client_name} {contact_name} {company_name}
-- {company_phone} {doc_number} {doc_type} {title} {amount} {subtotal}
-- {vat} {paid} {balance} {paid_now} {payment_ref} {method} {issue_date}
-- {due_date} {valid_until} {days_overdue} {days_to_due} {days_to_expiry}
-- {link} {job_number} {job_title} {job_stage} {dn_number} {delivery_date}
-- {delivered_to} {delivery_address} {delivered_by} {vehicle_reg}
-- {received_by} {paid_for}
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- How many days ahead to look for the two new date-based chases.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_due_days',    '3'),
  ('notify_expiry_days', '3')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- ---------------------------------------------------------------------
-- Per-event channel switches. Existing rows keep whatever is already set.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_quotation_accepted_email',  '1'),
  ('notify_quotation_accepted_sms',    '1'),
  ('notify_quotation_expiring_email',  '1'),
  ('notify_quotation_expiring_sms',    '1'),
  ('notify_payment_reminder_email',    '1'),
  ('notify_payment_reminder_sms',      '1'),
  ('notify_payment_partial_email',     '1'),
  ('notify_payment_partial_sms',       '1'),
  ('notify_receipt_issued_email',      '1'),
  ('notify_receipt_issued_sms',        '1'),
  ('notify_proof_ready_email',         '1'),
  ('notify_proof_ready_sms',           '1'),
  ('notify_job_in_production_email',   '1'),
  ('notify_job_in_production_sms',     '1'),
  ('notify_delivery_dispatched_email', '1'),
  ('notify_delivery_dispatched_sms',   '1'),
  ('notify_delivery_confirmed_email',  '1'),
  ('notify_delivery_confirmed_sms',    '1'),
  ('notify_quotation_sent_sms',        '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- ---------------------------------------------------------------------
-- Email templates
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('tpl_quotation_accepted_subject', 'Quotation {doc_number} accepted — thank you'),
  ('tpl_quotation_accepted_intro',
   'Thank you for approving quotation {doc_number} for {amount}. We are going ahead with the work and will keep you updated on progress.'),

  ('tpl_quotation_expiring_subject', 'Quotation {doc_number} expires on {valid_until}'),
  ('tpl_quotation_expiring_intro',
   'A reminder that quotation {doc_number} for {amount} is valid until {valid_until}. Let us know if you would like to go ahead, or if you would prefer it revised.'),

  ('tpl_payment_reminder_subject',   'Invoice {doc_number} falls due on {due_date}'),
  ('tpl_payment_reminder_intro',
   'A friendly reminder that invoice {doc_number} has a balance of {balance} falling due on {due_date}. If you have already paid, thank you, and please ignore this message.'),

  ('tpl_payment_partial_subject',    'Part payment received for {doc_number}'),
  ('tpl_payment_partial_intro',
   'We have received {paid_now} towards invoice {doc_number}. Thank you. A balance of {balance} remains outstanding.'),

  ('tpl_receipt_issued_subject',     'Receipt {doc_number} from {company_name}'),
  ('tpl_receipt_issued_intro',
   'Please find your receipt for {amount} below, covering invoice {paid_for}. Thank you for your business.'),

  ('tpl_proof_ready_subject',        'Proof ready for your approval — {job_number}'),
  ('tpl_proof_ready_intro',
   'The proof for {job_title} is ready for your approval. We start production as soon as you confirm, so please have a look and let us know if anything needs changing.'),

  ('tpl_job_in_production_subject',  'Your order {job_number} is now in production'),
  ('tpl_job_in_production_intro',
   'Good news — {job_title} has gone into production. We will let you know as soon as it is ready.'),

  ('tpl_delivery_dispatched_subject','Your order {job_number} is on the way'),
  ('tpl_delivery_dispatched_intro',
   '{job_title} has left our workshop on delivery note {dn_number} and is on its way to you.'),

  ('tpl_delivery_confirmed_subject', 'Delivery confirmed — {dn_number}'),
  ('tpl_delivery_confirmed_intro',
   'This confirms that {job_title} was delivered and signed for by {received_by}. Thank you for your business.')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- ---------------------------------------------------------------------
-- SMS templates. Kept under 160 characters where possible so each one
-- stays a single billable part.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('tpl_sms_quotation_sent',
   'Hi {contact_name}, quotation {doc_number} for {amount} is ready. View: {link} - {company_name}'),

  ('tpl_sms_quotation_accepted',
   'Hi {contact_name}, thank you for approving quote {doc_number}. We are going ahead with the work. - {company_name}'),

  ('tpl_sms_quotation_expiring',
   'Hi {contact_name}, quote {doc_number} ({amount}) expires {valid_until}. Call us to go ahead. {link} - {company_name}'),

  ('tpl_sms_payment_reminder',
   'Hi {contact_name}, invoice {doc_number} balance {balance} is due on {due_date}. {link} - {company_name}'),

  ('tpl_sms_payment_partial',
   'Hi {contact_name}, we received {paid_now} for {doc_number}. Balance {balance}. Thank you. - {company_name}'),

  ('tpl_sms_receipt_issued',
   'Hi {contact_name}, receipt {doc_number} for {amount} is ready. {link} - {company_name}'),

  ('tpl_sms_proof_ready',
   'Hi {contact_name}, the proof for {job_number} is ready for your approval. Please confirm so we can start production. - {company_name}'),

  ('tpl_sms_job_in_production',
   'Hi {contact_name}, your order {job_number} is now in production. We will let you know when it is ready. - {company_name}'),

  ('tpl_sms_delivery_dispatched',
   'Hi {contact_name}, your order {job_number} is on the way to you on {dn_number}. - {company_name}'),

  ('tpl_sms_delivery_confirmed',
   'Hi {contact_name}, delivery {dn_number} was received by {received_by}. Thank you for your business. - {company_name}')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- ---------------------------------------------------------------------
-- The overdue SMS runs two characters over one billable part even on the
-- new short link, so tighten the wording. Only touched when it is still
-- the shipped default — a template someone has edited is left alone.
-- ---------------------------------------------------------------------
UPDATE settings
   SET setting_value = 'Hi {contact_name}, invoice {doc_number} for {balance} is overdue. Kindly settle when you can. {link} - {company_name}'
 WHERE setting_key = 'tpl_sms_invoice_overdue'
   AND setting_value = 'Hi {contact_name}, invoice {doc_number} for {balance} is overdue. Please settle at your earliest convenience. {link} - {company_name}';
