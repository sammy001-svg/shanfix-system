-- =====================================================================
-- Migration 038 — the machines
--
-- A printing and branding business runs on equipment, and until now the
-- system had no idea any of it existed. What we own, what it cost, where
-- it is, who has it, and — the part that actually costs money when it is
-- forgotten — when it was last serviced and when it is next due.
--
-- The service history is a table of its own rather than a "last serviced"
-- column, because the question people actually ask is "what has been done
-- to this machine and what did it cost us", and a single date cannot
-- answer it. next_service_on is kept on the machine as well so the list
-- of what is falling due is one cheap query rather than a scan of every
-- history row.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS equipment (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_code    VARCHAR(30) NOT NULL,

  name          VARCHAR(180) NOT NULL,
  category      VARCHAR(80) NULL COMMENT 'Printer, cutter, laminator, vehicle, computer…',
  make          VARCHAR(120) NULL,
  model         VARCHAR(120) NULL,
  serial_number VARCHAR(120) NULL,

  location      VARCHAR(180) NULL COMMENT 'Which workshop, room or vehicle it lives in',

  -- Who is answerable for it. An employee rather than a login, because
  -- the person responsible for a guillotine may well never sign in.
  assigned_to   INT UNSIGNED NULL,

  purchased_on   DATE NULL,
  purchase_cost  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  supplier_id    INT UNSIGNED NULL,
  warranty_until DATE NULL,

  -- in_service   — working, in use
  -- idle         — working, not in use
  -- under_repair — out of action
  -- retired      — kept but finished with
  -- disposed     — sold or scrapped; the row stays for the history
  status        ENUM('in_service','idle','under_repair','retired','disposed')
                NOT NULL DEFAULT 'in_service',

  -- How often it wants looking at. Kept as an interval plus the last
  -- date so the next date can be worked out, and stored as well so
  -- "what is due" does not have to compute it for every machine.
  service_interval_days SMALLINT UNSIGNED NULL COMMENT 'NULL means it is not on a schedule',
  last_serviced_on      DATE NULL,
  next_service_on       DATE NULL,

  notes         TEXT NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_equipment_code (asset_code),
  KEY idx_equipment_status (status),
  KEY idx_equipment_due (next_service_on),
  KEY idx_equipment_assigned (assigned_to),
  KEY idx_equipment_category (category),
  CONSTRAINT fk_equipment_assignee FOREIGN KEY (assigned_to) REFERENCES employees (id) ON DELETE SET NULL,
  CONSTRAINT fk_equipment_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
  CONSTRAINT fk_equipment_creator  FOREIGN KEY (created_by)  REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- What has been done to it
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equipment_service (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipment_id  INT UNSIGNED NOT NULL,

  serviced_on   DATE NOT NULL,
  kind          ENUM('service','repair','inspection','part') NOT NULL DEFAULT 'service',
  summary       VARCHAR(255) NOT NULL COMMENT 'What was done, in a line',
  detail        TEXT NULL,

  -- Whoever actually did it: one of ours, an outside firm, or both.
  done_by_employee INT UNSIGNED NULL,
  done_by_supplier INT UNSIGNED NULL,
  done_by_name     VARCHAR(140) NULL COMMENT 'For a person or firm we do not hold a record of',

  cost          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  -- The expense it was booked to, where one was raised. Left NULL rather
  -- than made compulsory: recording that a machine was serviced should
  -- never wait on the paperwork.
  expense_id    INT UNSIGNED NULL,

  next_service_on DATE NULL COMMENT 'Set here when the engineer names a date',

  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_service_equipment (equipment_id, serviced_on),
  CONSTRAINT fk_service_equipment FOREIGN KEY (equipment_id)     REFERENCES equipment (id) ON DELETE CASCADE,
  CONSTRAINT fk_service_employee  FOREIGN KEY (done_by_employee) REFERENCES employees (id) ON DELETE SET NULL,
  CONSTRAINT fk_service_supplier  FOREIGN KEY (done_by_supplier) REFERENCES suppliers (id) ON DELETE SET NULL,
  CONSTRAINT fk_service_expense   FOREIGN KEY (expense_id)       REFERENCES expenses (id)  ON DELETE SET NULL,
  CONSTRAINT fk_service_creator   FOREIGN KEY (created_by)       REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Being told before it falls due, not after
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('equipment_service_warn_days', '14'),
  ('notify_equipment_due_email',  '1'),
  ('notify_equipment_due_sms',    '0')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
