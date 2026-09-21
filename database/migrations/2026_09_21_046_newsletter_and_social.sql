-- =====================================================================
-- Migration 046 — Newsletter subscribers, and the company's social links
--
-- The website footer has always had a "Subscribe to our newsletter" box
-- that saved nothing: the field had no name and the form no action, so
-- a visitor typed their address, pressed the button, watched the page
-- reload, and believed they had subscribed. This is where those
-- addresses go now.
--
-- It lives in the system rather than the website's own database so that
-- staff can see and export the list from the same place as everything
-- else, and so the list is backed up with everything else.
--
-- Kenya's Data Protection Act 2019 expects a person to be able to take
-- back their consent as easily as they gave it. Every subscriber has an
-- unsubscribe token for that, and unsubscribing marks the row rather
-- than deleting it — a deleted address can be re-added by anybody, a
-- marked one is remembered as "asked not to hear from us".
--
-- The social links were icons pointing at "#". They become settings,
-- and an empty one means the icon is not shown at all.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email            VARCHAR(160) NOT NULL,
  status           ENUM('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
  -- Which page they signed up on, so marketing can see what brings
  -- people in.
  source_page      VARCHAR(255) DEFAULT NULL,
  -- Random, and the only thing the unsubscribe link carries. Not the
  -- email address, so the link cannot be used to unsubscribe somebody
  -- else by guessing their address.
  unsubscribe_token CHAR(40) NOT NULL,
  ip               VARBINARY(16) DEFAULT NULL,
  subscribed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unsubscribed_at  DATETIME DEFAULT NULL,
  UNIQUE KEY uq_newsletter_email (email),
  UNIQUE KEY uq_newsletter_token (unsubscribe_token),
  KEY idx_newsletter_status (status, subscribed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('company_facebook',  ''),
  ('company_instagram', ''),
  ('company_linkedin',  ''),
  ('company_x',         ''),
  ('company_tiktok',    ''),
  ('company_youtube',   ''),
  -- A number, not a link: the footer builds the wa.me address from it.
  ('company_whatsapp',  '')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
