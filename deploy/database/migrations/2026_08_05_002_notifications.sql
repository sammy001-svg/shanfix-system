-- =====================================================================
-- Migration 002 — Email & SMS notifications
--
-- Adds an outbound message queue with a full audit trail, plus the
-- tokenised public links that let a client open their quotation or
-- invoice without an account.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Outbound queue. Every send attempt is recorded, successful or not, so
-- "did the client actually get the invoice?" always has an answer.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel       ENUM('email','sms') NOT NULL,
  event         VARCHAR(60)  NOT NULL,          -- quotation_sent, invoice_overdue…
  recipient     VARCHAR(255) NOT NULL,          -- email address or 2547XXXXXXXX
  recipient_name VARCHAR(160) DEFAULT NULL,
  subject       VARCHAR(255) DEFAULT NULL,      -- email only
  body          MEDIUMTEXT   DEFAULT NULL,      -- rendered at queue time
  entity_type   VARCHAR(40)  DEFAULT NULL,      -- document, job, client
  entity_id     INT UNSIGNED DEFAULT NULL,
  client_id     INT UNSIGNED DEFAULT NULL,
  status        ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error    VARCHAR(500) DEFAULT NULL,
  provider_ref  VARCHAR(120) DEFAULT NULL,      -- gateway message id
  cost          DECIMAL(10,4) DEFAULT NULL,     -- SMS cost, when the gateway reports it
  scheduled_at  DATETIME     DEFAULT NULL,      -- NULL = send on next run
  sent_at       DATETIME     DEFAULT NULL,
  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_notif_status (status, scheduled_at),
  KEY idx_notif_entity (entity_type, entity_id),
  KEY idx_notif_client (client_id),
  KEY idx_notif_event (event),
  KEY idx_notif_created (created_at),
  CONSTRAINT fk_notif_client FOREIGN KEY (client_id)  REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_notif_user   FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Stops the same automatic reminder going out twice for one document.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_locks (
  lock_key   VARCHAR(120) NOT NULL PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Public share token on documents — lets a client open their invoice
-- from an email link with no login. Random, revocable, per-document.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'documents' AND column_name = 'public_token');

SET @sql := IF(@col = 0,
  'ALTER TABLE documents
     ADD COLUMN public_token CHAR(48) DEFAULT NULL AFTER doc_number,
     ADD COLUMN viewed_at DATETIME DEFAULT NULL AFTER sent_at,
     ADD UNIQUE KEY uq_doc_token (public_token)',
  'SELECT "documents.public_token already present"');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Settings: transport config, per-event toggles and message templates.
-- Placeholders available: {client_name} {contact_name} {company_name}
-- {doc_number} {doc_type} {amount} {balance} {due_date} {issue_date}
-- {link} {job_number} {job_title} {phone} {payment_ref}
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  -- SMTP
  ('smtp_enabled',     '0'),
  ('smtp_host',        ''),
  ('smtp_port',        '587'),
  ('smtp_encryption',  'tls'),          -- tls | ssl | none
  ('smtp_username',    ''),
  ('smtp_password',    ''),             -- encrypted at rest
  ('smtp_from_email',  ''),
  ('smtp_from_name',   'Shanfix Technology'),
  ('smtp_reply_to',    ''),

  -- SMS (Africa's Talking)
  ('sms_enabled',      '0'),
  ('sms_provider',     'africastalking'),
  ('sms_username',     ''),
  ('sms_api_key',      ''),             -- encrypted at rest
  ('sms_sender_id',    ''),             -- shortcode or alphanumeric sender

  -- Which events send, and on which channel
  ('notify_quotation_sent_email', '1'),
  ('notify_quotation_sent_sms',   '0'),
  ('notify_invoice_sent_email',   '1'),
  ('notify_invoice_sent_sms',     '1'),
  ('notify_payment_received_email','1'),
  ('notify_payment_received_sms', '1'),
  ('notify_invoice_overdue_email','1'),
  ('notify_invoice_overdue_sms',  '1'),
  ('notify_job_ready_email',      '1'),
  ('notify_job_ready_sms',        '1'),

  ('notify_overdue_days',   '1,7,14'),  -- days past due to chase on
  ('notify_send_window',    '08:00-18:00'),
  ('notify_max_attempts',   '3'),

  -- Templates
  ('tpl_quotation_sent_subject', 'Quotation {doc_number} from {company_name}'),
  ('tpl_quotation_sent_intro',
   'Thank you for your enquiry. Please find your quotation below, valid until {valid_until}.'),

  ('tpl_invoice_sent_subject',   'Invoice {doc_number} from {company_name}'),
  ('tpl_invoice_sent_intro',
   'Please find your invoice below. Payment of {balance} is due by {due_date}.'),

  ('tpl_payment_received_subject','Payment received — thank you ({doc_number})'),
  ('tpl_payment_received_intro',
   'We have received your payment of {amount}. Thank you.'),

  ('tpl_invoice_overdue_subject','Reminder: invoice {doc_number} is overdue'),
  ('tpl_invoice_overdue_intro',
   'Our records show invoice {doc_number} for {balance} is now past its due date of {due_date}. If you have already paid, please ignore this message.'),

  ('tpl_job_ready_subject',      'Your order is ready for collection ({job_number})'),
  ('tpl_job_ready_intro',
   'Good news — {job_title} is finished and ready for collection.'),

  ('tpl_sms_invoice_sent',
   'Hi {contact_name}, invoice {doc_number} for {balance} is due {due_date}. View: {link} - {company_name}'),
  ('tpl_sms_payment_received',
   'Hi {contact_name}, we have received {amount} for {doc_number}. Thank you. - {company_name}'),
  ('tpl_sms_invoice_overdue',
   'Hi {contact_name}, invoice {doc_number} for {balance} is overdue. Please settle at your earliest convenience. {link} - {company_name}'),
  ('tpl_sms_job_ready',
   'Hi {contact_name}, your order {job_number} is ready for collection at {company_name}.'),

  ('email_footer_note',
   'This is an automated message. Please do not reply directly to this email.')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- Tokens are minted in PHP with random_bytes() the first time a document is
-- shared — MySQL's RAND() is not a cryptographic source, and RANDOM_BYTES()
-- is unavailable on the MariaDB versions cPanel commonly ships.
