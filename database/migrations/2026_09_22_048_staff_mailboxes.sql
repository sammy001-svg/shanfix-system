-- =====================================================================
-- Migration 048 — Each member of staff's own mailbox, inside the system
--
-- The company's email lives on cPanel. This lets each person read and
-- send their own mail from inside the system, over IMAP and SMTP to that
-- same server, so nothing changes about where mail is actually kept.
--
-- One row per member of staff, and only ever read for that person. There
-- is deliberately no screen, route or query anywhere that opens somebody
-- else's mailbox — not for an administrator either. An administrator who
-- needs a colleague's mail can reset that mailbox's password in cPanel,
-- which the colleague will notice; reading it quietly through here is not
-- something this system will do.
--
-- The password is stored encrypted with the application key. It has to
-- be recoverable, not hashed, because it is handed to the mail server on
-- every request — that is how IMAP works.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mail_accounts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_enc  TEXT         NOT NULL,
  display_name  VARCHAR(120) DEFAULT NULL,
  signature     TEXT         DEFAULT NULL,
  -- The folder names on this account, found once and remembered, since
  -- cPanel calls it "INBOX.Sent" and other servers call it "Sent".
  sent_folder   VARCHAR(190) DEFAULT NULL,
  trash_folder  VARCHAR(190) DEFAULT NULL,
  last_ok_at    DATETIME     DEFAULT NULL,
  last_error    VARCHAR(255) DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_accounts_user (user_id),
  CONSTRAINT fk_mail_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The server, once for everybody. cPanel's usual arrangement: the same
-- host for both, IMAP on 993 and SMTP on 465, both encrypted from the
-- first byte.
INSERT INTO settings (setting_key, setting_value) VALUES
  ('mail_enabled',       '1'),
  ('mail_imap_host',     ''),
  ('mail_imap_port',     '993'),
  ('mail_imap_security', 'ssl'),
  ('mail_smtp_host',     ''),
  ('mail_smtp_port',     '465'),
  ('mail_smtp_security', 'ssl'),
  -- Largest attachment total a message may carry, in megabytes. cPanel's
  -- own default limit is 50MB; staying under it avoids a send that the
  -- server then bounces.
  ('mail_max_attach_mb', '20')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
