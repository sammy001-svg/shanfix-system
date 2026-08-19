-- =====================================================================
-- Migration 021 — job detail requests
--
-- Asking a client what they actually want, in writing, before any work
-- starts. Staff raise a request from the client's profile choosing what
-- it is for; the client gets a link by email and text, fills in the brief
-- and attaches whatever they have. The answers land back on the client's
-- profile to build the job from.
--
-- The point is that a brief taken over the phone lives in one person's
-- memory and is remembered differently by both sides once the work is
-- delivered. This puts it on the record in the client's own words.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS job_requests (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id      INT UNSIGNED NOT NULL,
  reference      VARCHAR(30)  NOT NULL,

  -- Which of the three briefs the client is asked to fill in. Staff pick
  -- this when raising the request: they already know what the job is, and
  -- one type per request keeps each brief to the point.
  brief_type     ENUM('design','website','system') NOT NULL,

  -- draft     raised but not sent to anyone yet
  -- sent      the client has been given the link
  -- opened    the client has loaded it at least once
  -- submitted the client has answered
  -- actioned  turned into a job, or otherwise dealt with
  -- cancelled no longer wanted
  status         ENUM('draft','sent','opened','submitted','actioned','cancelled')
                 NOT NULL DEFAULT 'draft',

  -- The credential. There is no client login, so the token is what proves
  -- the holder was given the link. 48 hex characters, same as everywhere
  -- else a client is sent a private page.
  public_token   CHAR(48)     NOT NULL,

  title          VARCHAR(200) NULL,
  note           TEXT         NULL COMMENT 'Internal note from whoever raised it; never shown to the client',

  created_by     INT UNSIGNED NULL,
  sent_at        DATETIME     NULL,
  opened_at      DATETIME     NULL,
  submitted_at   DATETIME     NULL,

  -- Set when a colleague filled the form in on the client's behalf, over
  -- the phone or at the counter. Worth recording: an answer typed by
  -- staff and an answer typed by the client carry different weight when
  -- there is a disagreement later.
  filled_by_staff INT UNSIGNED NULL,

  -- The job this brief became, once it becomes one.
  job_id         INT UNSIGNED NULL,

  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_job_requests_token (public_token),
  UNIQUE KEY uq_job_requests_reference (reference),
  KEY idx_job_requests_client (client_id, status),
  KEY idx_job_requests_status (status, created_at),
  KEY idx_job_requests_job (job_id),

  CONSTRAINT fk_job_requests_client
    FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_job_requests_user
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Answers are rows rather than columns, and rather than one JSON blob.
--
-- The three briefs ask different things and those questions will change
-- as the company learns which ones are worth asking. Columns would mean a
-- migration every time; a blob would mean the answers cannot be searched.
-- This way the questions live in PHP where they are easy to edit, and the
-- answers stay queryable — "every client who asked for M-Pesa on their
-- website" is a plain WHERE clause.
CREATE TABLE IF NOT EXISTS job_request_answers (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id  INT UNSIGNED NOT NULL,
  field_key   VARCHAR(60)  NOT NULL,

  -- The question as it was worded when this was answered. Kept alongside
  -- the answer so an old brief still reads correctly after the wording of
  -- a question has been changed.
  field_label VARCHAR(255) NOT NULL,

  answer      MEDIUMTEXT   NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  PRIMARY KEY (id),
  UNIQUE KEY uq_answer_per_field (request_id, field_key),
  KEY idx_answers_field (field_key),

  CONSTRAINT fk_answers_request
    FOREIGN KEY (request_id) REFERENCES job_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Whatever the client attached: a logo to match, photos, a document of
-- content, an example of something they like.
CREATE TABLE IF NOT EXISTS job_request_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id    INT UNSIGNED NOT NULL,

  original_name VARCHAR(255) NOT NULL COMMENT 'What the client called it; never used as a path',
  stored_name   VARCHAR(255) NOT NULL COMMENT 'What we called it on disk',
  mime          VARCHAR(120) NULL,
  bytes         INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_request_files (request_id),

  CONSTRAINT fk_request_files_request
    FOREIGN KEY (request_id) REFERENCES job_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('job_request_prefix', 'JDR'),

  -- Told by email and by text, because the two fail in different ways: an
  -- email goes to a spam folder unseen, a text is read but easy to lose.
  ('notify_job_request_email', '1'),
  ('notify_job_request_sms',   '1'),

  -- Largest single attachment a client may send, in megabytes. Shared
  -- hosting caps the request size anyway, so this is about giving a clear
  -- message rather than an error page.
  ('job_request_max_mb', '10')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('tpl_job_request_subject',
   'A few details about your {brief} — {company}'),
  ('tpl_job_request_intro',
   'Before we start we would like to get your requirements down in your own words, so that what we deliver is what you had in mind. It takes a few minutes and you can attach any files you already have.'),
  ('tpl_sms_job_request',
   '{company}: please tell us what you need for your {brief} — {link}')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
