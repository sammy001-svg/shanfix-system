-- =====================================================================
-- Migration 025 — a quotation or invoice is approved before it goes out
--
-- A price leaving the company is a commitment. When somebody other than
-- an administrator raises or changes a quotation or an invoice, it now
-- waits: it cannot be printed, downloaded, or sent to the client, and the
-- client's own link will not open it, until an administrator has looked
-- at it and approved it. The administrators are texted so the wait is
-- short.
--
-- Only quotations and invoices. A receipt records money already taken and
-- a proposal or agreement is prose — holding those back would stop work
-- without protecting anything.
--
-- Everything already in the system is marked approved. Anything else
-- would freeze every existing document the moment this is applied, which
-- is not a change anybody asked for.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE documents
  -- not_required  a receipt, proposal or agreement, or raised by an admin
  -- pending       waiting for an administrator to look at it
  -- approved      cleared to print, download and send
  ADD COLUMN approval_status ENUM('not_required','pending','approved')
      NOT NULL DEFAULT 'not_required' AFTER status,
  ADD COLUMN approved_by INT UNSIGNED NULL AFTER approval_status,
  ADD COLUMN approved_at DATETIME NULL AFTER approved_by,

  -- What the person was asked to change, when an administrator sends one
  -- back rather than approving it.
  ADD COLUMN approval_note VARCHAR(255) NULL AFTER approved_at;

ALTER TABLE documents
  ADD KEY idx_documents_approval (approval_status, doc_type);

-- Nothing that already exists starts life blocked.
UPDATE documents SET approval_status = 'approved' WHERE approval_status = 'not_required';

INSERT INTO settings (setting_key, setting_value) VALUES
  -- The whole feature, in one switch.
  ('approval_required', '1'),

  -- Text the administrators, because the person who raised it is usually
  -- standing in front of a client waiting to hand it over.
  ('approval_notify_sms',   '1'),
  ('approval_notify_email', '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('tpl_sms_document_approval',
   '{company}: {doc_type} {doc_number} for {client_name} ({amount}) needs your approval. Raised by {raised_by}. {link}')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
