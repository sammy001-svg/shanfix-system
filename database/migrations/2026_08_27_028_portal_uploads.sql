-- =====================================================================
-- Migration 028 — files a client sends us through the portal
--
-- Artwork for printing, a logo, a document to be typeset. Until now these
-- arrived by WhatsApp or email and lived in whichever inbox caught them,
-- which is how a job gets held up for a file somebody definitely sent.
--
-- Deliberately not job_files. Those hang off a job that already exists;
-- these arrive before there is one, and often before there is even a
-- quotation. A member of staff attaches them to a job once they know
-- which job it is.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS portal_uploads (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,

  client_id      INT UNSIGNED NOT NULL,
  client_user_id INT UNSIGNED NULL COMMENT 'Which person at that client sent it',

  original_name  VARCHAR(255) NOT NULL COMMENT 'What they called it; never used as a path',
  stored_name    VARCHAR(255) NOT NULL COMMENT 'What we called it on disk',
  mime           VARCHAR(120) NULL,
  bytes          INT UNSIGNED NOT NULL DEFAULT 0,

  -- What it is for, in their words. "Banner for the expo", "logo in
  -- vector" — the sentence that saves a phone call.
  note           VARCHAR(500) NULL,

  -- new       nobody has looked yet
  -- seen      a member of staff has opened it
  -- attached  now hanging off a job
  status         ENUM('new','seen','attached') NOT NULL DEFAULT 'new',

  job_id         INT UNSIGNED NULL,
  document_id    INT UNSIGNED NULL COMMENT 'The quotation or invoice it relates to, if any',

  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_portal_uploads_client (client_id, created_at),
  KEY idx_portal_uploads_status (status, created_at),

  CONSTRAINT fk_portal_uploads_client
    FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_portal_uploads_user
    FOREIGN KEY (client_user_id) REFERENCES client_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_portal_uploads_job
    FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE SET NULL,
  CONSTRAINT fk_portal_uploads_document
    FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('portal_uploads_enabled', '1'),

  -- Files one client may send in an hour. Print-ready artwork is large and
  -- the disk is not, and without a cap an upload form is a way to fill it.
  ('portal_uploads_per_hour', '20')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
