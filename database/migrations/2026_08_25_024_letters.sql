-- =====================================================================
-- Migration 024 — letters
--
-- Company letters written inside the system rather than in Word: an
-- introduction, a quotation cover note, a reference, a notice to a
-- landlord, a letter to a bank. They come out on the company letterhead
-- with the logo, the contact details and the address, and close with the
-- company's vision.
--
-- The point is that every letter that leaves the company then looks the
-- same, and there is a record of what was sent to whom. A letter typed
-- in Word lives on one person's laptop and nobody else can find it.
--
-- Recipients are free text with an optional link to a client, because a
-- letter is as likely to be going to a bank, a supplier, a landlord or a
-- county office as to somebody already on file.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS letters (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference         VARCHAR(30)  NOT NULL,

  -- Set when the letter goes to somebody already on file. The recipient
  -- fields below are still filled in, so the letter keeps the name and
  -- address it was actually sent to even if the client record changes.
  client_id         INT UNSIGNED NULL,

  recipient_name    VARCHAR(160) NOT NULL,
  recipient_title   VARCHAR(120) NULL COMMENT 'The Manager, The Registrar, and so on',
  recipient_org     VARCHAR(160) NULL,
  recipient_address TEXT         NULL,

  letter_date       DATE         NOT NULL,
  subject           VARCHAR(200) NOT NULL,

  salutation        VARCHAR(120) NOT NULL DEFAULT 'Dear Sir/Madam',
  body              MEDIUMTEXT   NOT NULL,

  -- "Yours faithfully" to a stranger, "Yours sincerely" to somebody
  -- addressed by name. Kept per letter because the writer chooses.
  closing           VARCHAR(80)  NOT NULL DEFAULT 'Yours faithfully',

  signatory_name    VARCHAR(120) NOT NULL,
  signatory_title   VARCHAR(120) NULL,

  -- draft  still being written
  -- final  signed off and sent; kept as the record of what went out
  status            ENUM('draft','final') NOT NULL DEFAULT 'draft',

  created_by        INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_letters_reference (reference),
  KEY idx_letters_client (client_id),
  KEY idx_letters_status (status, letter_date),
  KEY idx_letters_date (letter_date),

  CONSTRAINT fk_letters_client
    FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL,
  CONSTRAINT fk_letters_user
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('letter_prefix', 'LTR'),

  -- Printed small at the foot of every letter, under the rule. Editable
  -- in Settings so it can be reworded without touching any code.
  ('company_vision',
   'To be the partner Kenyan businesses turn to for printing, branding and software that works.'),

  -- Whoever signs most of the letters. Only a default; each letter
  -- carries its own signatory.
  ('letter_default_signatory_title', 'Managing Director')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
