-- =====================================================================
-- Migration 044 — Portal support messages + notification feed
--
-- Two features, one migration, because they share the same motivation:
-- giving clients a way to know what is happening without having to ring.
--
-- portal_messages: one thread per client account, alternating client
-- and staff turns. Simple and enough — a client with a question about
-- a job does not need a ticket system, they need one place to send the
-- message and one place to read the reply.
--
-- portal_notifications: events written by the system whenever something
-- changes that the client should know about (job moved, invoice issued,
-- payment received). Read by the portal layout to drive the bell badge;
-- cleared by opening the notifications list.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Support messages
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS portal_messages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id       INT UNSIGNED NOT NULL,
  sender          ENUM('client','staff') NOT NULL,
  body            TEXT NOT NULL,
  staff_user_id   INT UNSIGNED DEFAULT NULL,   -- who replied on the staff side
  client_user_id  INT UNSIGNED DEFAULT NULL,   -- which portal login sent it
  read_at         DATETIME     DEFAULT NULL,   -- set when the other side opens it
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pm_client   (client_id),
  KEY idx_pm_unread   (client_id, read_at),
  CONSTRAINT fk_pm_client      FOREIGN KEY (client_id)      REFERENCES clients(id)      ON DELETE CASCADE,
  CONSTRAINT fk_pm_staff_user  FOREIGN KEY (staff_user_id)  REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_pm_client_user FOREIGN KEY (client_user_id) REFERENCES client_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Notification feed
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS portal_notifications (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  event      VARCHAR(60)  NOT NULL,          -- e.g. 'job_stage_changed', 'invoice_issued'
  title      VARCHAR(200) NOT NULL,
  body       VARCHAR(500) DEFAULT NULL,
  link       VARCHAR(255) DEFAULT NULL,      -- relative URL to the relevant page
  read_at    DATETIME     DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pn_client (client_id),
  KEY idx_pn_unread (client_id, read_at),
  CONSTRAINT fk_pn_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
