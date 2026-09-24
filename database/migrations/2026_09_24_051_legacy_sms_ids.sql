-- =====================================================================
-- Migration 051 — Remembering what came from the old SMS platform
--
-- Migration of the old platform (sms.shanfixtechnology.com, database
-- bulk_sms_system) started with accounts and balances: import-bulk-sms
-- remembers each old user in bulk_accounts.legacy_user_id, so a second
-- run finds its own work and leaves it alone. bulk_campaigns was given
-- a legacy_id for the same reason.
--
-- The rest of what a customer owns — their contacts, the groups those
-- sit in, their sender IDs and every message they have sent — had
-- nowhere to record where it came from. Without that the import is a
-- one-shot: run it twice and every contact arrives twice, and a run
-- that dies half way cannot be finished, only undone by hand.
--
-- A cutover is exactly the moment when a run dies half way. So each of
-- these tables now remembers its old id, and each is unique: a second
-- insert of the same old row is refused by the database rather than
-- relying on the importer to remember.
--
-- NULL means "did not come from the old platform", which is every row
-- created here since. UNIQUE allows any number of NULLs in MySQL, so
-- that costs nothing.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Contact groups
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'bulk_contact_groups' AND column_name = 'legacy_id');

SET @sql := IF(@col = 0,
  'ALTER TABLE bulk_contact_groups
     ADD COLUMN legacy_id INT UNSIGNED DEFAULT NULL COMMENT ''contact_groups.id on the old platform'',
     ADD UNIQUE KEY uq_bcg_legacy (legacy_id)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Contacts
--
-- The largest of these by row count after the messages: a customer with
-- a few years of marketing behind them has tens of thousands.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'bulk_contacts' AND column_name = 'legacy_id');

SET @sql := IF(@col = 0,
  'ALTER TABLE bulk_contacts
     ADD COLUMN legacy_id INT UNSIGNED DEFAULT NULL COMMENT ''contacts.id on the old platform'',
     ADD UNIQUE KEY uq_bc_legacy (legacy_id)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Sender IDs
--
-- These matter more than their row count suggests. A sender ID is
-- registered with the networks under the customer's name and takes days
-- to approve; arriving here as "pending" would take a working sender
-- away from somebody who already has one.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'bulk_sender_ids' AND column_name = 'legacy_id');

SET @sql := IF(@col = 0,
  'ALTER TABLE bulk_sender_ids
     ADD COLUMN legacy_id INT UNSIGNED DEFAULT NULL COMMENT ''sender_ids.id on the old platform'',
     ADD UNIQUE KEY uq_bsi_legacy (legacy_id)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Messages
--
-- Millions of rows on a busy platform, so this one is imported in
-- batches and the unique key is what makes stopping and restarting
-- safe. BIGINT to match the old table, which is also BIGINT.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'bulk_messages' AND column_name = 'legacy_id');

SET @sql := IF(@col = 0,
  'ALTER TABLE bulk_messages
     ADD COLUMN legacy_id BIGINT UNSIGNED DEFAULT NULL COMMENT ''messages.id on the old platform'',
     ADD UNIQUE KEY uq_bm_legacy (legacy_id)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
