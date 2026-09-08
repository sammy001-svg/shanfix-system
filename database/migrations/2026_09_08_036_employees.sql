-- =====================================================================
-- Migration 036 — the people who work here
--
-- There has never been an employee record. `users` is a login: a name, an
-- email, a role and a password. It says who may open the system, not who
-- is employed, on what terms, from when, or what they are paid. Half the
-- people on a print floor never sign in at all, and a person who leaves
-- keeps their employment history long after their login is switched off.
--
-- So the two are separate things that point at each other. An employee
-- may have a login and usually does not need one; a login belongs to at
-- most one employee.
--
-- Names are in three parts here for the same reason as on a partner: this
-- is the name that has to match an ID and a KRA certificate when money
-- moves, and `name` is written from the parts so the two cannot drift.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_number VARCHAR(30) NOT NULL,

  -- Their login, where they have one. ON DELETE SET NULL because
  -- deleting somebody's account must not delete the fact that they
  -- worked here.
  user_id         INT UNSIGNED NULL COMMENT 'Their login, if they have one',

  first_name      VARCHAR(60) NOT NULL,
  middle_name     VARCHAR(60) NULL,
  last_name       VARCHAR(60) NOT NULL,
  name            VARCHAR(180) NOT NULL COMMENT 'Written from the three parts, never edited alone',

  -- What the statutory returns are filed against. Nobody can be put on a
  -- payroll run without them, which the payroll screen enforces rather
  -- than the schema — a new starter is often hired before the paperwork
  -- catches up, and refusing to record them at all helps nobody.
  id_number       VARCHAR(30) NULL COMMENT 'National ID',
  kra_pin         VARCHAR(30) NULL,
  nssf_number     VARCHAR(30) NULL,
  shif_number     VARCHAR(30) NULL COMMENT 'Health insurance number (SHIF, formerly NHIF)',

  date_of_birth   DATE NULL,
  gender          ENUM('female','male','other') NULL,

  phone           VARCHAR(30) NULL,
  email           VARCHAR(160) NULL,
  address         VARCHAR(255) NULL,
  town            VARCHAR(80) NULL,

  -- Asked once, when nobody needs it, so it is there on the day somebody
  -- does.
  kin_name        VARCHAR(140) NULL,
  kin_phone       VARCHAR(30) NULL,
  kin_relationship VARCHAR(60) NULL,

  job_title       VARCHAR(120) NULL,
  department      VARCHAR(120) NULL,
  employment_type ENUM('permanent','contract','casual','intern') NOT NULL DEFAULT 'permanent',

  started_on      DATE NULL,
  ended_on        DATE NULL COMMENT 'Their last day, once there is one',
  end_reason      VARCHAR(255) NULL,

  status          ENUM('active','on_leave','suspended','left') NOT NULL DEFAULT 'active',

  -- The figure everything else is built on. Allowances and deductions
  -- that vary by person live in employee_pay_items rather than as columns
  -- here, because every business has a different list of them.
  basic_salary    DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  -- Where their pay goes. Same shape as a partner's, and set by staff for
  -- the same reason: the destination of money is not the account holder's
  -- to change on their own say-so.
  pay_method        ENUM('mpesa','bank','cash') NULL,
  pay_phone         VARCHAR(30) NULL,
  bank_name         VARCHAR(120) NULL,
  bank_branch       VARCHAR(120) NULL,
  bank_account_name VARCHAR(160) NULL,
  bank_account_no   VARCHAR(40) NULL,

  notes           TEXT NULL COMMENT 'Internal',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_number (employee_number),
  -- One login belongs to at most one employee, so a payslip can never be
  -- ambiguous about who it is for.
  UNIQUE KEY uq_employee_user (user_id),
  KEY idx_employees_status (status),
  KEY idx_employees_name (name),
  KEY idx_employees_department (department),
  CONSTRAINT fk_employees_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_employees_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- What each person is paid on top of, or out of, their basic
-- ---------------------------------------------------------------------
-- A table rather than columns because the list differs by business and by
-- person: one has a house allowance, another a bicycle, a third is paying
-- back a salary advance over four months.
--
-- `taxable` matters and is not decoration. A non-taxable allowance is
-- added to what somebody is paid without being added to what they are
-- taxed on, and getting that backwards is a real underpayment of PAYE.
CREATE TABLE IF NOT EXISTS employee_pay_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id INT UNSIGNED NOT NULL,

  kind        ENUM('allowance','deduction') NOT NULL,
  name        VARCHAR(120) NOT NULL,
  amount      DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  taxable     TINYINT(1) NOT NULL DEFAULT 1
              COMMENT 'Allowances only: whether it is added to taxable pay',

  -- A one-off runs in a single month and then stops on its own; a
  -- recurring item runs every month until somebody ends it.
  recurring   TINYINT(1) NOT NULL DEFAULT 1,
  starts_on   DATE NULL,
  ends_on     DATE NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  note        VARCHAR(255) NULL,

  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_pay_items_employee (employee_id, is_active),
  CONSTRAINT fk_pay_items_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_items_creator  FOREIGN KEY (created_by)  REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Numbering
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('employee_prefix',  'EMP'),
  ('equipment_prefix', 'EQP')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
