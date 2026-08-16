-- =====================================================================
-- Migration 006 — Offline queue support
--
-- A device working offline holds actions until it reconnects, then
-- replays them. Replay is not safe on its own: a flaky connection can
-- deliver the same request twice, and the client cannot always tell
-- whether the first attempt landed before the socket dropped.
--
-- Every queued action therefore carries a key generated once, on the
-- device, at the moment the user pressed the button. The server records
-- the key the first time it sees it; a second arrival is recognised and
-- ignored rather than applied again.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS idempotency_keys (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  key_hash   CHAR(64)     NOT NULL,          -- sha256 of the client key
  user_id    INT UNSIGNED DEFAULT NULL,
  route      VARCHAR(190) DEFAULT NULL,      -- what it was spent on, for support
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_idem_key (key_hash),
  KEY idx_idem_created (created_at),
  CONSTRAINT fk_idem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
