-- =====================================================================
-- Migration 008 — Sign-in page background
--
-- Stored as a setting pointing at storage/uploads/branding, so the image
-- can be changed from Settings → Company by anyone with admin access.
-- Previously it had to be dropped into public/assets/img over FTP, which
-- meant server access for what is really a branding choice.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('login_background', '')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
