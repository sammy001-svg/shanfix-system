-- =====================================================================
-- Migration 017 — Artwork requests, and telling the team about them
--
-- Design is sold on its own, not only as a step inside a print job, so
-- it gets its own module: a client asks for artwork, it is allocated to
-- a designer, the designer submits it, the client approves it, and only
-- then does it become a job for production.
--
-- Also adds the thing the system has never had — notifications for our
-- own people. Everything in `notifications` goes outward to clients;
-- staff have had no way of being told anything.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- The request itself.
--
-- job_id is filled in when approved artwork is pushed to production, so
-- the two halves of the story stay joined without either owning the
-- other.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artwork_requests (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_number VARCHAR(40)  NOT NULL,
  client_id      INT UNSIGNED NOT NULL,
  document_id    INT UNSIGNED DEFAULT NULL,   -- the quote or invoice it sits under
  job_id         INT UNSIGNED DEFAULT NULL,   -- set once pushed to production
  title          VARCHAR(200) NOT NULL,
  brief          TEXT         DEFAULT NULL,
  specs          VARCHAR(500) DEFAULT NULL,   -- size, material, colours
  assigned_to    INT UNSIGNED DEFAULT NULL,   -- the designer
  status         ENUM('requested','assigned','in_progress','proof_sent',
                      'changes_requested','approved','completed','cancelled')
                   NOT NULL DEFAULT 'requested',
  priority       ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  due_date       DATE         DEFAULT NULL,
  -- The client's share link. Minted on first send, so a request nobody
  -- has sent out has no link that could leak.
  public_token   CHAR(48)     DEFAULT NULL,
  viewed_at      DATETIME     DEFAULT NULL,
  approved_at    DATETIME     DEFAULT NULL,
  approved_name  VARCHAR(160) DEFAULT NULL,
  approved_ip    VARCHAR(45)  DEFAULT NULL,
  created_by     INT UNSIGNED DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_artwork_number (request_number),
  UNIQUE KEY uq_artwork_token (public_token),
  KEY idx_artwork_client (client_id),
  KEY idx_artwork_assignee (assigned_to),
  KEY idx_artwork_status (status),
  CONSTRAINT fk_artwork_client   FOREIGN KEY (client_id)   REFERENCES clients(id)   ON DELETE CASCADE,
  CONSTRAINT fk_artwork_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_artwork_job      FOREIGN KEY (job_id)      REFERENCES jobs(id)      ON DELETE SET NULL,
  CONSTRAINT fk_artwork_designer FOREIGN KEY (assigned_to) REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT fk_artwork_creator  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Files against a request. A reference is what the client sent in; a
-- proof is what we send back for approval and is the only kind that
-- carries a decision.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artwork_files (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id      INT UNSIGNED NOT NULL,
  file_type       ENUM('reference','draft','proof','final') NOT NULL DEFAULT 'proof',
  file_path       VARCHAR(255) NOT NULL,
  file_name       VARCHAR(200) NOT NULL,
  file_size       INT UNSIGNED NOT NULL DEFAULT 0,
  version         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  notes           VARCHAR(500) DEFAULT NULL,
  client_feedback VARCHAR(500) DEFAULT NULL,
  decided_via     ENUM('staff','client') DEFAULT NULL,
  decided_at      DATETIME     DEFAULT NULL,
  uploaded_by     INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_afiles_request (request_id, version),
  KEY idx_afiles_status (status),
  CONSTRAINT fk_afiles_request  FOREIGN KEY (request_id)  REFERENCES artwork_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_afiles_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- What happened, in order. A designer picking the job up a week later
-- needs to see the conversation, not just the current status.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS artwork_events (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(30) DEFAULT NULL,
  to_status   VARCHAR(30) DEFAULT NULL,
  note       VARCHAR(500) DEFAULT NULL,
  user_id    INT UNSIGNED DEFAULT NULL,   -- NULL when the client did it
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aevents_request (request_id, id),
  CONSTRAINT fk_aevents_request FOREIGN KEY (request_id) REFERENCES artwork_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_aevents_user    FOREIGN KEY (user_id)    REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Notifications for our own people.
--
-- Everything in `notifications` is addressed to a client. This is the
-- other direction: the bell in the top bar, one row per person who needs
-- to know, so read state is per person rather than shared.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS staff_notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  event       VARCHAR(60)  NOT NULL,
  title       VARCHAR(200) NOT NULL,
  body        VARCHAR(500) DEFAULT NULL,
  link        VARCHAR(255) DEFAULT NULL,
  entity_type VARCHAR(40)  DEFAULT NULL,
  entity_id   INT UNSIGNED DEFAULT NULL,
  read_at     DATETIME     DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_snotif_user (user_id, read_at),
  KEY idx_snotif_created (created_at),
  CONSTRAINT fk_snotif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Numbering, and the client-facing messages for the artwork events.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('artwork_prefix', 'ART'),

  ('notify_artwork_ready_email',    '1'),
  ('notify_artwork_ready_sms',      '1'),
  ('notify_artwork_approved_email', '1'),
  ('notify_artwork_approved_sms',   '1'),

  ('tpl_artwork_ready_subject', 'Your artwork is ready to review — {request_number}'),
  ('tpl_artwork_ready_intro',
   'The artwork for {title} is ready for you to look at. Please open the link below, check it carefully, and either approve it or tell us what to change. Nothing goes to print until you approve.'),

  ('tpl_sms_artwork_ready',
   'Hi {contact_name}, your artwork for {title} is ready to review. Approve it here: {link} - {company_name}'),

  ('tpl_artwork_approved_subject', 'Thank you — {request_number} approved'),
  ('tpl_artwork_approved_intro',
   'Thank you for approving the artwork for {title}. It has gone to our production team and we will let you know as it progresses.'),

  ('tpl_sms_artwork_approved',
   'Hi {contact_name}, thank you for approving the artwork for {title}. It is now with production. - {company_name}')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
