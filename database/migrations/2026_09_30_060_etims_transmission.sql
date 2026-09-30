-- =====================================================================
-- Migration 060 — Sending an invoice to KRA
--
-- Migration 057 gave every line a tax class, which is what eTIMS needs
-- to accept an invoice at all. This is the other half: the record of
-- whether a particular invoice has actually been sent, what KRA said
-- back, and what has to be printed on the client's copy as a result.
--
-- Three things are being kept, and they are different things:
--
--   where it is       queued, sending, sent, failed, voided — ours
--   what KRA said     the fiscal details that come back and that make
--                     the printed document a tax invoice rather than a
--                     piece of paper with a total on it
--   what went wrong   the error, and how many times, because a failed
--                     transmission is a compliance problem somebody has
--                     to be told about rather than a row to retry
--                     silently for ever
--
-- The fiscal fields are stored under our own names, with KRA's whole
-- reply kept verbatim beside them. The reply is the record of what the
-- authority actually said and it is not ours to reshape; our columns
-- are what the invoice prints from. When KRA changes a field name — and
-- they have — only the mapping moves.
--
-- Nothing here sends anything. Transmission is off until somebody puts
-- the company's own KRA details in, and an invoice raised before that
-- sits at 'not_sent', which is the truth about it.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Where a document stands with KRA
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'documents' AND column_name = 'etims_status');

SET @sql := IF(@col = 0,
  'ALTER TABLE documents
     ADD COLUMN etims_status ENUM(''not_sent'',''queued'',''sending'',''sent'',''failed'',''voided'')
         NOT NULL DEFAULT ''not_sent'',
     ADD COLUMN etims_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
     ADD COLUMN etims_last_error VARCHAR(500) DEFAULT NULL,
     ADD COLUMN etims_queued_at DATETIME DEFAULT NULL,
     ADD COLUMN etims_sent_at DATETIME DEFAULT NULL,

     -- What KRA gives back, and what the client''s copy must show.
     ADD COLUMN etims_receipt_no VARCHAR(40) DEFAULT NULL
         COMMENT ''the receipt number KRA assigns'',
     ADD COLUMN etims_invoice_no VARCHAR(40) DEFAULT NULL
         COMMENT ''the control unit invoice number'',
     ADD COLUMN etims_signature VARCHAR(64) DEFAULT NULL
         COMMENT ''the receipt signature printed on the invoice'',
     ADD COLUMN etims_internal_data VARCHAR(64) DEFAULT NULL,
     ADD COLUMN etims_signed_at DATETIME DEFAULT NULL
         COMMENT ''KRA''''s own timestamp, not ours'',
     ADD COLUMN etims_verify_url VARCHAR(500) DEFAULT NULL
         COMMENT ''where a client can check the invoice; the QR encodes this'',

     -- Verbatim. The authority''s reply is the record of what it said.
     ADD COLUMN etims_response MEDIUMTEXT DEFAULT NULL,

     ADD KEY idx_etims_status (etims_status, etims_queued_at)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Every attempt, kept
--
-- Separate from the document because a document has one current state
-- and a compliance question has a history. "It says sent — when, on
-- which attempt, and what did the two before it say" is the question
-- that gets asked, and the document row cannot answer it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS etims_log (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id   INT UNSIGNED NOT NULL,
  action        VARCHAR(30)  NOT NULL COMMENT 'send, void, confirm',
  ok            TINYINT(1)   NOT NULL DEFAULT 0,
  http_code     SMALLINT     DEFAULT NULL,
  message       VARCHAR(500) DEFAULT NULL,
  request       MEDIUMTEXT   DEFAULT NULL,
  response      MEDIUMTEXT   DEFAULT NULL,
  took_ms       INT UNSIGNED DEFAULT NULL,
  user_id       INT UNSIGNED DEFAULT NULL COMMENT 'null when the sweep did it',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_doc (document_id, created_at),
  KEY idx_when (created_at),
  CONSTRAINT fk_etims_log_doc FOREIGN KEY (document_id)
    REFERENCES documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The company's own KRA details
--
-- Off by default, and it stays off until somebody fills these in. A
-- half-configured integration that sends anyway produces invoices KRA
-- rejects, and a rejected invoice is a compliance problem rather than
-- an error message.
--
-- The device credentials are not listed here. Settings::SECRETS
-- encrypts them at rest, and a row inserted with an empty value would
-- be an empty encrypted value — they are written the first time
-- somebody saves the form.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('etims_enabled',      '0'),
  ('etims_environment',  'sandbox'),
  ('etims_pin',          ''),
  ('etims_branch_id',    '00'),
  ('etims_device_serial',''),
  ('etims_base_url',     ''),

  -- Whether an invoice goes on its own as soon as it is raised, or
  -- waits for somebody to send it. Defaults to waiting: the first
  -- weeks of an eTIMS integration are spent finding out what KRA
  -- rejects, and finding that out one invoice at a time is kinder
  -- than finding it out across a day's billing.
  ('etims_auto_send',    '0')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
