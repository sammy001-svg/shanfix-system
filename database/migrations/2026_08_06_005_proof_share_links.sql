-- =====================================================================
-- Migration 005 — Let a client approve a proof from a link
--
-- Until now the proof_ready message asked a client to approve artwork
-- they had no way of opening: job files sit behind a login. This adds a
-- share token per proof, the same idea as the one on documents, so the
-- client can see the proof and approve or reject it themselves.
--
-- decided_via records who pressed the button. A staff-recorded decision
-- still sets approved_by, a client decision leaves it NULL — without
-- this column the two are indistinguishable afterwards.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'job_files' AND column_name = 'public_token');

SET @sql := IF(@col = 0,
  'ALTER TABLE job_files
     ADD COLUMN public_token CHAR(48) DEFAULT NULL AFTER status,
     ADD COLUMN viewed_at DATETIME DEFAULT NULL AFTER public_token,
     ADD COLUMN decided_via ENUM(''staff'',''client'') DEFAULT NULL AFTER approved_at,
     ADD UNIQUE KEY uq_jfile_token (public_token)',
  'SELECT "job_files.public_token already present"');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Every decision on record so far was entered by a member of staff.
-- ---------------------------------------------------------------------
UPDATE job_files
   SET decided_via = 'staff'
 WHERE decided_via IS NULL AND status IN ('approved','rejected') AND approved_at IS NOT NULL;

-- ---------------------------------------------------------------------
-- The proof message now carries a link, so say so in the templates —
-- but only where they are still the wording shipped in migration 004.
-- ---------------------------------------------------------------------
UPDATE settings
   SET setting_value = 'Hi {contact_name}, the proof for {job_number} is ready. Approve it here: {link} - {company_name}'
 WHERE setting_key = 'tpl_sms_proof_ready'
   AND setting_value = 'Hi {contact_name}, the proof for {job_number} is ready for your approval. Please confirm so we can start production. - {company_name}';

UPDATE settings
   SET setting_value = 'The proof for {job_title} is ready for your approval. Please open the link below to see it and let us know whether to go ahead — we start production as soon as you approve.'
 WHERE setting_key = 'tpl_proof_ready_intro'
   AND setting_value = 'The proof for {job_title} is ready for your approval. We start production as soon as you confirm, so please have a look and let us know if anything needs changing.';
