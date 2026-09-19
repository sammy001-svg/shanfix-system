-- =====================================================================
-- Migration 040 — what Bulk SMS tells its customers
--
-- The old platform wrote these e-mails inside its send engine. They are
-- ordinary notification templates now, editable in Settings like every
-- other message, and switched on and off the same way.
--
-- E-mail is on by default for all five: each one is about the customer's
-- own money or their own campaign, and they would expect to hear. SMS is
-- off — texting someone to say their texts have been sent is noise —
-- apart from a top-up, which is a payment and deserves a receipt.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('tpl_bulk_campaign_done_subject', 'Your SMS campaign "{campaign}" has finished'),
  ('tpl_bulk_campaign_done_intro',
   'Your campaign "{campaign}" has finished sending. {sent} of {total} messages went out ({rate}), {failed} could not be sent, and it used {units}. The full delivery report is in your portal.'),
  ('tpl_sms_bulk_campaign_done',
   '{company}: campaign "{campaign}" finished. {sent} sent, {failed} failed, {units} used.'),

  ('tpl_bulk_low_balance_subject', 'Your {company} SMS balance is running low'),
  ('tpl_bulk_low_balance_intro',
   'Your SMS balance is down to {balance}, below the {threshold} you asked to be warned at. Top up in your portal to keep your messages going out without a break.'),
  ('tpl_sms_bulk_low_balance',
   '{company}: your SMS balance is down to {balance}. Top up in your portal to keep sending.'),

  ('tpl_bulk_topup_subject', '{units} added to your {company} SMS account'),
  ('tpl_bulk_topup_intro',
   'Thank you. {units} have been added to your SMS account for {amount} (reference {reference}). Your balance is now {balance}.'),
  ('tpl_sms_bulk_topup',
   '{company}: {units} added to your SMS account for {amount}. Balance {balance}. Ref {reference}.'),

  ('tpl_bulk_sender_approved_subject', 'Sender ID {sender_id} is approved'),
  ('tpl_bulk_sender_approved_intro',
   'Your sender ID {sender_id} has been approved by the networks. Your messages can now go out under that name — choose it on the send screen.'),
  ('tpl_sms_bulk_sender_approved',
   '{company}: sender ID {sender_id} is approved and ready to use.'),

  ('tpl_bulk_sender_rejected_subject', 'Sender ID {sender_id} was not approved'),
  ('tpl_bulk_sender_rejected_intro',
   'We could not get sender ID {sender_id} approved. {reason} You can apply again from your portal, or call us and we will help you with the paperwork.'),
  ('tpl_sms_bulk_sender_rejected',
   '{company}: sender ID {sender_id} was not approved. See your portal for the reason.')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_bulk_campaign_done_email',   '1'),
  ('notify_bulk_campaign_done_sms',     '0'),
  ('notify_bulk_low_balance_email',     '1'),
  ('notify_bulk_low_balance_sms',       '0'),
  ('notify_bulk_topup_email',           '1'),
  ('notify_bulk_topup_sms',             '1'),
  ('notify_bulk_sender_approved_email', '1'),
  ('notify_bulk_sender_approved_sms',   '0'),
  ('notify_bulk_sender_rejected_email', '1'),
  ('notify_bulk_sender_rejected_sms',   '0'),
  -- How many campaigns may send at once. Each is a PHP process and a
  -- database connection; five suits a VPS, two or three shared hosting.
  ('bulk_sms_max_workers',              '5')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
