-- =====================================================================
-- Migration 008 — Shareable client statements
--
-- Gives each client the same kind of unguessable share token documents
-- already have, so a statement of account can be sent as a link the
-- client opens with no login.
--
-- The token is minted on first use rather than here: a client who has
-- never been sent a statement has no link to leak.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'clients' AND column_name = 'public_token');

SET @sql := IF(@col = 0,
  'ALTER TABLE clients
     ADD COLUMN public_token CHAR(48) DEFAULT NULL AFTER status,
     ADD COLUMN statement_sent_at DATETIME DEFAULT NULL AFTER public_token,
     ADD UNIQUE KEY uq_client_token (public_token)',
  'SELECT "clients.public_token already present"');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
