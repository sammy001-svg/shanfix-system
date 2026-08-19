-- =====================================================================
-- Migration 015 — Proposals and purchase agreements
--
-- Two new document types on the same engine that already drives
-- quotations, invoices and receipts, so they inherit numbering, client
-- links, VAT, the share token, printing and the conversion chain rather
-- than duplicating any of it.
--
--   proposal   the written pitch, with priced lines. Converts into a
--              quotation, and the two stay linked.
--   agreement  what the client signs before work starts. Accepted on the
--              share link, with the acceptance kept as evidence.
--
-- The narrative belongs in document_sections: a proposal is mostly prose
-- with a price at the end, and prose does not fit a line-items table.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Widen doc_type. Checked first so a re-run does not rewrite the column
-- on a large table for nothing.
-- ---------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'documents' AND column_name = 'doc_type'
                AND COLUMN_TYPE LIKE '%proposal%');

SET @sql := IF(@has = 0,
  "ALTER TABLE documents MODIFY doc_type
     ENUM('quotation','invoice','receipt','proposal','agreement') NOT NULL",
  'SELECT "documents.doc_type already covers proposals"');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Evidence of a client accepting an agreement online. Kept on the
-- document rather than a side table: it is a property of this one
-- agreement, and it has to print alongside it.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'documents' AND column_name = 'accepted_at');

SET @sql2 := IF(@col = 0,
  'ALTER TABLE documents
     ADD COLUMN accepted_at DATETIME DEFAULT NULL AFTER viewed_at,
     ADD COLUMN accepted_name VARCHAR(160) DEFAULT NULL AFTER accepted_at,
     ADD COLUMN accepted_ip VARCHAR(45) DEFAULT NULL AFTER accepted_name',
  'SELECT "documents.accepted_at already present"');

PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- The written part. Ordered blocks of heading + body, so a proposal can
-- run to whatever length the work needs.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_sections (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  heading     VARCHAR(200) NOT NULL,
  body        MEDIUMTEXT   DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  KEY idx_dsections_doc (document_id, sort_order),
  CONSTRAINT fk_dsections_doc FOREIGN KEY (document_id)
    REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Numbering, and the starting sections a new document is built from.
-- Operators edit these once in Settings rather than retyping the same
-- headings on every proposal.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('proposal_prefix',  'PRO'),
  ('agreement_prefix', 'AGR'),
  ('proposal_validity_days', '30'),

  ('tpl_proposal_sections',
   'Introduction|Thank you for the opportunity to propose on this work. This document sets out what we understand you need, how we would deliver it, and what it costs.
Understanding of your requirement|Describe the problem in the client''s own words, so they can see it has been understood before any solution is offered.
Our approach|How the work will be carried out, and why this way.
Scope of work|Exactly what is included. Being specific here is what prevents a dispute later.
What is not included|Anything a reasonable client might assume is covered but is not.
Timeline|Key stages and how long each takes, from the day the agreement is signed.
Why Shanfix Technology|Relevant work, capability and support after handover.'),

  ('tpl_agreement_sections',
   'Parties|This agreement is between {company_name} ("the Provider") and {client_name} ("the Client").
Services|The Provider will supply the services set out in this agreement and any schedule attached to it.
Fees and payment|Fees are as set out below. Invoices fall due within the agreed terms. Work may be suspended on overdue accounts.
Client responsibilities|The Client will supply content, approvals and access promptly. Delays caused by the Client extend the timeline accordingly.
Intellectual property|Ownership of deliverables passes to the Client on payment in full. The Provider retains ownership of its own tools and pre-existing materials.
Confidentiality|Each party will keep the other''s business information confidential.
Variations|Any change to scope will be agreed in writing before it is carried out, and may affect the fee and the timeline.
Termination|Either party may end this agreement in writing. The Client pays for work completed up to that date.
Governing law|This agreement is governed by the laws of Kenya.')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
