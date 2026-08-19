-- =====================================================================
-- Migration 018 — Discussion attached to the work it is about
--
-- Chat has been free-floating: a conversation about the Acme job lives
-- in a channel somewhere, and the job card knows nothing about it. Open
-- the job a month later and the reasoning behind a decision is lost in
-- scrollback, if it is findable at all.
--
-- A thread is an ordinary conversation with an entity on it, so it
-- inherits messages, attachments, participants, unread counts, mentions
-- and search with no new machinery.
--
-- The 'record' type keeps these out of the main chat list — they belong
-- on the job, not alongside your channels.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'chat_conversations' AND column_name = 'entity_type');

SET @sql := IF(@col = 0,
  'ALTER TABLE chat_conversations
     ADD COLUMN entity_type VARCHAR(40) DEFAULT NULL AFTER dm_key,
     ADD COLUMN entity_id INT UNSIGNED DEFAULT NULL AFTER entity_type,
     ADD UNIQUE KEY uq_conv_entity (entity_type, entity_id)',
  'SELECT "chat_conversations.entity_type already present"');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- The type column was ENUM('dm','channel'). A record thread is neither.
-- ---------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'chat_conversations' AND column_name = 'type'
                AND COLUMN_TYPE LIKE '%record%');

SET @sql2 := IF(@has = 0,
  "ALTER TABLE chat_conversations
     MODIFY type ENUM('dm','channel','record') NOT NULL DEFAULT 'dm'",
  'SELECT "chat_conversations.type already covers record threads"');

PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;
