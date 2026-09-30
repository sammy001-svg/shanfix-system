-- =====================================================================
-- Migration 059 — One standard VAT rate, not two
--
-- Migration 057 added a tax_rate_b setting for the standard rate. The
-- standard rate already had a setting: vat_rate, which is what the
-- settings page has always edited and what every document stores a copy
-- of. Holding it in two places meant an administrator could change the
-- rate on the settings page and watch nothing happen to an invoice,
-- because the arithmetic was reading the other one.
--
-- So the arithmetic reads vat_rate, and tax_rate_b goes. Where somebody
-- had already changed tax_rate_b — possible only between 057 and this,
-- but it costs nothing to be careful — that value is carried across
-- first, because it is the figure that was actually being charged.
--
-- tax_rate_e stays. The reduced rate is a genuinely separate figure and
-- had nowhere to live before; it is now on the settings page beside the
-- standard one.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- Whatever was actually being charged wins, since that is what the
-- invoices raised in between say.
UPDATE settings s
  JOIN settings b ON b.setting_key = 'tax_rate_b'
   SET s.setting_value = b.setting_value
 WHERE s.setting_key = 'vat_rate'
   AND b.setting_value <> ''
   AND b.setting_value <> s.setting_value;

DELETE FROM settings WHERE setting_key = 'tax_rate_b';

-- The reduced rate, if 057 did not already put it there.
INSERT INTO settings (setting_key, setting_value) VALUES ('tax_rate_e', '8')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
