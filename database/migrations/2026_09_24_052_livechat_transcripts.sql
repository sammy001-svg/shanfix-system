-- =====================================================================
-- Migration 052 — Finishing the live chat
--
-- Two things migration 045 left room for and nothing ever filled.
--
-- live_conversations.transcript_sent_at has been sitting there since the
-- chat was built, waiting for something to post the customer a copy of
-- their own conversation. A chat window closes and takes the price they
-- were quoted with it — this is the only record they end up holding.
--
-- live_canned held saved replies that could be read into the desk's
-- dropdown and put there by nobody: there was no screen for them, so the
-- feature was off unless somebody opened MySQL. The table needs one more
-- column to be worth managing — who last changed a reply, which is the
-- question asked when one turns out to say the wrong price.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Who last touched a saved reply
--
-- created_by is already there. This is the other half: a reply quoting
-- last year's price is somebody's edit, and "who wrote this originally"
-- does not answer it.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'live_canned' AND column_name = 'updated_by');

SET @sql := IF(@col = 0,
  'ALTER TABLE live_canned
     ADD COLUMN updated_by INT UNSIGNED DEFAULT NULL AFTER created_by,
     ADD CONSTRAINT fk_live_canned_editor FOREIGN KEY (updated_by)
         REFERENCES users(id) ON DELETE SET NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  -- Post the visitor their conversation when it closes, if they left an
  -- address. On by default: somebody who gave us their e-mail did so
  -- expecting to hear from us.
  ('livechat_transcript_email', '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;

-- ---------------------------------------------------------------------
-- Somewhere to start
--
-- Three replies that every print shop types out a dozen times a week.
-- The office will rewrite them; the point is that the dropdown has
-- something in it the first time somebody opens the desk, rather than
-- being an empty control that looks broken.
-- ---------------------------------------------------------------------
INSERT INTO live_canned (department_id, title, body)
SELECT * FROM (SELECT
  NULL AS department_id,
  'Ask for the artwork' AS title,
  'Could you send the artwork through? PDF or AI is best, at actual size with 3mm bleed. If you only have a JPEG we can work with it — send the largest version you have.' AS body) AS c
WHERE NOT EXISTS (SELECT 1 FROM live_canned WHERE title = 'Ask for the artwork');

INSERT INTO live_canned (department_id, title, body)
SELECT * FROM (SELECT
  NULL AS department_id,
  'Turnaround' AS title,
  'Once the artwork is approved it is usually ready in 2 to 3 working days. If you need it sooner tell us the date and we will say honestly whether we can make it.' AS body) AS c
WHERE NOT EXISTS (SELECT 1 FROM live_canned WHERE title = 'Turnaround');

INSERT INTO live_canned (department_id, title, body)
SELECT * FROM (SELECT
  NULL AS department_id,
  'How to pay' AS title,
  'We take M-Pesa and bank transfer. We raise an invoice first so you have something with our details on it — what name should it be in?' AS body) AS c
WHERE NOT EXISTS (SELECT 1 FROM live_canned WHERE title = 'How to pay');
