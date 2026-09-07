-- =====================================================================
-- Migration 031 — the messages the partner portal sends
--
-- The partner flow dispatched three notification events that no template
-- existed for. What that meant in practice:
--
--   * the EMAIL went out with the raw event name as its subject
--     ("Partner_code") and an empty body;
--   * the SMS did not go out at all, because queueSms refuses an event
--     with no template rather than inventing one.
--
-- A partner therefore had no way to receive the code that sets their
-- password, which is the only way into the portal. These are the
-- defaults, so the flow works on a fresh install without anybody having
-- to write them first.
--
-- Written in the same voice as the client portal's: plain, short, and
-- saying who it is from. {company} is filled in for every message now,
-- not only on the paths that remembered to pass it.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
  -- The code that lets a partner set their password. The one message the
  -- whole portal depends on.
  ('tpl_partner_code_subject', 'Your {company} partner code'),
  ('tpl_partner_code_intro',
   'Use the code below to set your password for the {company} partner portal. It is good for {minutes} minutes, and nobody from {company} will ever ask you for it.'),
  ('tpl_sms_partner_code',
   '{company}: your partner code is {code}. It expires in {minutes} minutes. Do not share it with anyone.'),

  -- Saying yes to an application, or handing over an account we set up
  -- ourselves. Both end in the same place: go and set a password.
  ('tpl_partner_approved_subject', 'You are now a {company} partner'),
  ('tpl_partner_approved_intro',
   'Good news — you are now a {company} partner. Your commission rate is {rate} on what your customers spend with us, and some services pay more. Set your password at {link} and you can see your customers and your earnings straight away.'),
  ('tpl_sms_partner_approved',
   '{company}: you are now a partner. Set your password at {link} to see your customers and earnings.'),

  -- Telling them the money has gone out.
  ('tpl_partner_paid_subject', '{company} commission payment'),
  ('tpl_partner_paid_intro',
   'We have paid you {amount} in commission. The reference is {payment_ref}. You can see every entry behind it in your partner portal.'),
  ('tpl_sms_partner_paid',
   '{company}: we have paid you {amount} in commission. Ref {payment_ref}.'),

  -- Not a partner message, but the same fault, found by the same check.
  -- The "ask for job details" button has been dispatching job_request
  -- since it was built, with nobody having written it anything to say:
  -- the email went out titled "Job_request" with an empty body, and the
  -- text was refused outright.
  ('tpl_job_request_subject', '{company}: a few questions about your {brief}'),
  ('tpl_job_request_intro',
   'Before we start on your {brief}, we need a little detail from you. Open {link} and fill in what you can — it takes a few minutes, and you can attach anything you already have. Quote {reference} if you call us about it.'),
  ('tpl_sms_job_request',
   '{company}: please fill in the details for your {brief} here: {link} (ref {reference})')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- ---------------------------------------------------------------------
-- Turn them on
-- ---------------------------------------------------------------------
-- Every other event is off until somebody switches it on, which is right
-- for marketing-ish messages. These three are not that: without the code
-- a partner cannot sign in at all, and an approval nobody is told about
-- is not an approval. They are dispatched with force anyway, so this is
-- belt and braces for anyone reading the Settings screen and wondering
-- why they look disabled.
INSERT INTO settings (setting_key, setting_value) VALUES
  ('notify_partner_code_email',     '1'),
  ('notify_partner_code_sms',       '1'),
  ('notify_partner_approved_email', '1'),
  ('notify_partner_approved_sms',   '1'),
  ('notify_partner_paid_email',     '1'),
  ('notify_partner_paid_sms',       '0')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
