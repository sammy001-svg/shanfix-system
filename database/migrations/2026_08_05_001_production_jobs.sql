-- =====================================================================
-- Migration 001 — Production job tracking
--
-- Adds the shop-floor workflow that sits between "invoice raised" and
-- "client has their goods": job cards, stage history, artwork approval,
-- delivery notes and per-job costing.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Production staff need to move jobs along, so they get their own role.
-- (MySQL has no "ALTER ENUM ... IF NOT EXISTS"; re-stating the full list
--  is idempotent.)
-- ---------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','manager','sales','finance','production','staff')
  NOT NULL DEFAULT 'staff';

-- ---------------------------------------------------------------------
-- Job cards
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jobs (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_number      VARCHAR(40)  NOT NULL,
  client_id       INT UNSIGNED NOT NULL,
  document_id     INT UNSIGNED DEFAULT NULL,   -- invoice or quotation it came from
  title           VARCHAR(200) NOT NULL,
  description     TEXT         DEFAULT NULL,
  stage           ENUM('pending','artwork','proof_sent','approved','production',
                       'finishing','ready','delivered','on_hold','cancelled')
                    NOT NULL DEFAULT 'pending',
  priority        ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assigned_to     INT UNSIGNED DEFAULT NULL,
  due_date        DATETIME     DEFAULT NULL,
  started_at      DATETIME     DEFAULT NULL,
  completed_at    DATETIME     DEFAULT NULL,   -- reached "ready"
  delivered_at    DATETIME     DEFAULT NULL,
  hold_reason     VARCHAR(255) DEFAULT NULL,
  production_notes TEXT        DEFAULT NULL,   -- internal, never shown to client
  created_by      INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_job_number (job_number),
  KEY idx_jobs_stage (stage),
  KEY idx_jobs_assigned (assigned_to),
  KEY idx_jobs_due (due_date),
  KEY idx_jobs_client (client_id),
  CONSTRAINT fk_jobs_client   FOREIGN KEY (client_id)   REFERENCES clients(id)   ON DELETE CASCADE,
  CONSTRAINT fk_jobs_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_assignee FOREIGN KEY (assigned_to) REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT fk_jobs_creator  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- What actually has to be produced. Copied from the source document so
-- the shop floor sees a checklist, not a financial line item.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id      INT UNSIGNED NOT NULL,
  description VARCHAR(500) NOT NULL,
  quantity    DECIMAL(14,2) NOT NULL DEFAULT 1.00,
  unit        VARCHAR(30)  DEFAULT NULL,
  specs       VARCHAR(500) DEFAULT NULL,   -- size, material, finish
  is_done     TINYINT(1)   NOT NULL DEFAULT 0,
  done_at     DATETIME     DEFAULT NULL,
  done_by     INT UNSIGNED DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  KEY idx_jitems_job (job_id),
  CONSTRAINT fk_jitems_job  FOREIGN KEY (job_id)  REFERENCES jobs(id)  ON DELETE CASCADE,
  CONSTRAINT fk_jitems_user FOREIGN KEY (done_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Stage history — who moved the job, when, and why.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_stages (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id     INT UNSIGNED NOT NULL,
  from_stage VARCHAR(30)  DEFAULT NULL,
  to_stage   VARCHAR(30)  NOT NULL,
  notes      VARCHAR(500) DEFAULT NULL,
  user_id    INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jstages_job (job_id, id),
  CONSTRAINT fk_jstages_job  FOREIGN KEY (job_id)  REFERENCES jobs(id)  ON DELETE CASCADE,
  CONSTRAINT fk_jstages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Artwork, proofs and final files, with the client's approval on record.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_files (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id        INT UNSIGNED NOT NULL,
  file_type     ENUM('artwork','proof','reference','final') NOT NULL DEFAULT 'artwork',
  file_path     VARCHAR(255) NOT NULL,
  file_name     VARCHAR(200) NOT NULL,
  file_size     INT UNSIGNED NOT NULL DEFAULT 0,
  version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  notes         VARCHAR(500) DEFAULT NULL,
  client_feedback VARCHAR(500) DEFAULT NULL,
  approved_by   INT UNSIGNED DEFAULT NULL,   -- staff member who logged the approval
  approved_at   DATETIME     DEFAULT NULL,
  uploaded_by   INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jfiles_job (job_id),
  KEY idx_jfiles_status (status),
  CONSTRAINT fk_jfiles_job      FOREIGN KEY (job_id)      REFERENCES jobs(id)  ON DELETE CASCADE,
  CONSTRAINT fk_jfiles_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_jfiles_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Delivery notes — proof the client received the goods.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delivery_notes (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dn_number       VARCHAR(40)  NOT NULL,
  job_id          INT UNSIGNED DEFAULT NULL,
  client_id       INT UNSIGNED NOT NULL,
  document_id     INT UNSIGNED DEFAULT NULL,
  delivery_date   DATE NOT NULL,
  delivered_to    VARCHAR(160) DEFAULT NULL,   -- contact person at the client
  delivery_address VARCHAR(255) DEFAULT NULL,
  delivered_by    VARCHAR(160) DEFAULT NULL,   -- our driver / rider
  vehicle_reg     VARCHAR(40)  DEFAULT NULL,
  received_by     VARCHAR(160) DEFAULT NULL,
  received_at     DATETIME     DEFAULT NULL,
  notes           TEXT         DEFAULT NULL,
  status          ENUM('draft','dispatched','delivered') NOT NULL DEFAULT 'draft',
  created_by      INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dn_number (dn_number),
  KEY idx_dn_job (job_id),
  KEY idx_dn_client (client_id),
  CONSTRAINT fk_dn_job      FOREIGN KEY (job_id)      REFERENCES jobs(id)      ON DELETE SET NULL,
  CONSTRAINT fk_dn_client   FOREIGN KEY (client_id)   REFERENCES clients(id)   ON DELETE CASCADE,
  CONSTRAINT fk_dn_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_dn_creator  FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_note_items (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_note_id INT UNSIGNED NOT NULL,
  description      VARCHAR(500) NOT NULL,
  quantity         DECIMAL(14,2) NOT NULL DEFAULT 1.00,
  unit             VARCHAR(30) DEFAULT NULL,
  sort_order       INT NOT NULL DEFAULT 0,
  KEY idx_dnitems_dn (delivery_note_id),
  CONSTRAINT fk_dnitems_dn FOREIGN KEY (delivery_note_id)
    REFERENCES delivery_notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Per-job costing: attribute an expense to the job that consumed it.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'expenses' AND column_name = 'job_id');

SET @sql := IF(@col = 0,
  'ALTER TABLE expenses
     ADD COLUMN job_id INT UNSIGNED DEFAULT NULL AFTER client_id,
     ADD KEY idx_exp_job (job_id),
     ADD CONSTRAINT fk_exp_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL',
  'SELECT "expenses.job_id already present"');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Numbering prefixes for the new document types
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('job_prefix', 'JOB'),
  ('delivery_note_prefix', 'DN'),
  ('job_default_lead_days', '3')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
