-- =====================================================================
-- Migration 037 — payroll
--
-- Gross to net, month by month, with the statutory deductions worked out
-- rather than typed in.
--
-- READ THIS BEFORE THE FIRST LIVE RUN.
--
-- Every rate, band, floor and ceiling below is a SETTING, not code. That
-- is deliberate and it is not tidiness: Kenyan payroll law has changed
-- three times in three years — NHIF became SHIF, the housing levy
-- arrived, NSSF moved to two tiers on a rising ceiling, and the Tax Laws
-- (Amendment) Act 2024 turned SHIF and the levy from reliefs into
-- deductions from taxable pay. Anything hard-coded would have been wrong
-- within a year, silently, on somebody's wages.
--
-- The figures seeded here are the ones believed current when this was
-- written. They are a starting point and NOT authority. Check every one
-- of them against KRA and your accountant before you pay anybody:
-- payroll_rates_confirmed stays at 0 until somebody does, and the payroll
-- screen says so in as many words until it is set.
--
-- What a payslip records is what was calculated at the time, not a
-- recipe to recompute later. Rates change; a payslip from March must
-- still say what March said, so every figure is stored on the row.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- A month's pay
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_runs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  period       CHAR(7) NOT NULL COMMENT 'YYYY-MM',

  -- draft     — worked out, still being checked, nothing committed
  -- approved  — signed off, ready to pay
  -- paid      — the money has gone
  -- closed    — done with; kept for the records and the returns
  status       ENUM('draft','approved','paid','closed') NOT NULL DEFAULT 'draft',

  employee_count   INT UNSIGNED NOT NULL DEFAULT 0,
  total_gross      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_paye       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_nssf       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_shif       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_housing    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_other      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_net        DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  -- What the business owes on top of wages. Kept apart from the employee
  -- side because it is our cost, not their deduction, and the returns
  -- ask for the two separately.
  total_employer_nssf    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_employer_housing DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  created_by   INT UNSIGNED NULL,
  approved_by  INT UNSIGNED NULL,
  approved_at  DATETIME NULL,
  paid_at      DATETIME NULL,
  closed_at    DATETIME NULL,
  note         VARCHAR(255) NULL,

  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_payroll_runs_period (period, status),
  CONSTRAINT fk_payroll_runs_created  FOREIGN KEY (created_by)  REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_runs_approved FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- One person's pay for that month
-- ---------------------------------------------------------------------
-- Every figure is stored rather than derived. A payslip is a statement
-- of what was worked out at the time; if it were recomputed from today's
-- rates, last March's payslip would quietly change the next time the law
-- did, and the copy in somebody's file would stop matching ours.
CREATE TABLE IF NOT EXISTS payslips (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id        INT UNSIGNED NOT NULL,
  employee_id   INT UNSIGNED NOT NULL,

  -- Copied in, because people change name, number and job, and a payslip
  -- has to keep saying what it said when it was issued.
  employee_name   VARCHAR(180) NOT NULL,
  employee_number VARCHAR(30) NULL,
  job_title       VARCHAR(120) NULL,
  kra_pin         VARCHAR(30) NULL,

  basic         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  allowances    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  gross         DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  -- The statutory side, each one on its own so a return can be filed
  -- from it without unpicking a total.
  nssf          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  shif          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  housing_levy  DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  taxable_pay   DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Gross less whatever the law allows before PAYE',
  paye_gross    DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Tax from the bands, before any relief',
  personal_relief   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  insurance_relief  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  paye          DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'What is actually withheld',

  other_deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  net_pay          DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  employer_nssf    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  employer_housing DECIMAL(14,2) NOT NULL DEFAULT 0.00,

  -- Where it went, copied at the time for the same reason as a payout
  -- line: account numbers change, and last March's payment must still
  -- say where last March's money went.
  pay_method   ENUM('mpesa','bank','cash') NULL,
  destination  VARCHAR(200) NULL,
  account_name VARCHAR(160) NULL,

  status       ENUM('pending','paid','held') NOT NULL DEFAULT 'pending',
  ref          VARCHAR(80) NULL,
  paid_at      DATETIME NULL,
  hold_reason  VARCHAR(255) NULL,

  note         VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- One payslip per person per run, or somebody is paid twice.
  UNIQUE KEY uq_payslip (run_id, employee_id),
  KEY idx_payslips_employee (employee_id),
  KEY idx_payslips_status (status),
  CONSTRAINT fk_payslips_run      FOREIGN KEY (run_id)      REFERENCES payroll_runs (id) ON DELETE CASCADE,
  CONSTRAINT fk_payslips_employee FOREIGN KEY (employee_id) REFERENCES employees (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The lines behind the totals
-- ---------------------------------------------------------------------
-- So a payslip can show "house allowance 15,000" and "salary advance
-- 4,000" rather than one unexplained figure, and so the arithmetic can be
-- checked by the person it was done to.
CREATE TABLE IF NOT EXISTS payslip_lines (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payslip_id INT UNSIGNED NOT NULL,

  kind       ENUM('allowance','deduction','statutory') NOT NULL,
  name       VARCHAR(120) NOT NULL,
  amount     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  taxable    TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  PRIMARY KEY (id),
  KEY idx_payslip_lines (payslip_id, sort_order),
  CONSTRAINT fk_payslip_lines FOREIGN KEY (payslip_id) REFERENCES payslips (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The law, as settings
-- ---------------------------------------------------------------------
-- CHECK EVERY ONE OF THESE. They are what was believed current when this
-- was written, and they are the whole of what the calculation knows.
INSERT INTO settings (setting_key, setting_value) VALUES

  -- Nothing has been checked by a human yet. The payroll screen keeps
  -- saying so until somebody sets this to 1, because a wrong rate here is
  -- invisible on the screen and very visible on a payslip.
  ('payroll_rates_confirmed', '0'),

  -- PAYE, monthly. Each band gives the ceiling it applies up to and the
  -- rate on the slice below it; the last has no ceiling.
  ('payroll_paye_bands',
   '[{"upto":24000,"rate":10},{"upto":32333,"rate":25},{"upto":500000,"rate":30},{"upto":800000,"rate":32.5},{"upto":null,"rate":35}]'),

  -- Taken off the tax itself, not off pay.
  ('payroll_personal_relief', '2400'),

  -- Relief on life and health premiums: a percentage of what was paid,
  -- capped. Only applies where a premium has been recorded against the
  -- employee, so it is nil for most people.
  ('payroll_insurance_relief_rate', '15'),
  ('payroll_insurance_relief_cap',  '5000'),

  -- NSSF, two tiers on a rising ceiling. The employer matches, and that
  -- match is our cost rather than the employee's deduction.
  ('payroll_nssf_enabled', '1'),
  ('payroll_nssf_rate',    '6'),
  ('payroll_nssf_lel',     '8000'),
  ('payroll_nssf_uel',     '72000'),

  -- SHIF, which replaced NHIF. A percentage of gross with a floor and no
  -- ceiling.
  ('payroll_shif_enabled', '1'),
  ('payroll_shif_rate',    '2.75'),
  ('payroll_shif_min',     '300'),

  -- Affordable housing levy. Employee pays it and the employer pays the
  -- same again.
  ('payroll_housing_enabled',       '1'),
  ('payroll_housing_rate',          '1.5'),
  ('payroll_housing_employer_rate', '1.5'),

  -- WHICH DEDUCTIONS COME OFF BEFORE PAYE IS WORKED OUT.
  --
  -- The single setting most likely to be wrong, and the one that moves
  -- everybody's tax when it is. The Tax Laws (Amendment) Act 2024 made
  -- SHIF and the housing levy deductible from taxable pay, where before
  -- they were reliefs against the tax. If your accountant says otherwise
  -- for the period you are running, change this and nothing else.
  ('payroll_pre_tax_deductions', '["nssf","shif","housing"]'),

  -- Rounding. Payslips are shown and paid to the shilling by convention;
  -- set to 2 if you would rather keep the cents.
  ('payroll_round_dp', '0')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
