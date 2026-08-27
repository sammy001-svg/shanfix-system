-- =====================================================================
-- Migration 027 — price requests from the portal
--
-- A client browses the catalogue, ticks the things they are interested
-- in, and asks us something about the price of them: is this still
-- current, can we quote for this lot, is there anything on the price for
-- taking all of it.
--
-- The three questions are one table because they are the same
-- conversation. What differs is only what the client is asking for, and
-- that is a column.
--
-- Prices are copied onto the request rather than referenced. A client who
-- asks about a price today should be answered about the price they saw,
-- not the one it changed to on Thursday — and the record of what they
-- were shown is the only way to settle that later.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS price_requests (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference      VARCHAR(30)  NOT NULL,

  client_id      INT UNSIGNED NOT NULL,

  -- Which person at that client asked. Kept so a reply goes back to them
  -- rather than to whichever address is on the company record.
  client_user_id INT UNSIGNED NULL,

  -- review     is this price still right?
  -- quotation  please quote me for these
  -- discount   what can you do on the price for all of it?
  kind           ENUM('review','quotation','discount') NOT NULL DEFAULT 'quotation',

  note           TEXT NULL,

  -- new       nobody has looked yet
  -- seen      somebody has opened it
  -- answered  a quotation was raised, or they were replied to
  -- declined  we are not taking it further
  status         ENUM('new','seen','answered','declined') NOT NULL DEFAULT 'new',

  -- The quotation raised from it, if one was.
  document_id    INT UNSIGNED NULL,

  answered_by    INT UNSIGNED NULL,
  answered_at    DATETIME NULL,
  reply_note     VARCHAR(500) NULL,

  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_price_requests_reference (reference),
  KEY idx_price_requests_client (client_id, status),
  KEY idx_price_requests_status (status, created_at),

  CONSTRAINT fk_price_requests_client
    FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_price_requests_user
    FOREIGN KEY (client_user_id) REFERENCES client_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_price_requests_document
    FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL,
  CONSTRAINT fk_price_requests_answered_by
    FOREIGN KEY (answered_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_request_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id    INT UNSIGNED NOT NULL,

  item_type     ENUM('service','inventory') NOT NULL,
  ref_id        INT UNSIGNED NOT NULL,

  -- What the client was actually shown. A name and a price copied at the
  -- moment of asking, so the answer can be about what they saw even after
  -- the catalogue moves on — and so the row still reads sensibly if the
  -- item is later withdrawn altogether.
  name_snapshot  VARCHAR(200) NOT NULL,
  price_snapshot DECIMAL(14,2) NOT NULL DEFAULT 0,

  quantity      DECIMAL(12,2) NOT NULL DEFAULT 1,

  PRIMARY KEY (id),
  KEY idx_price_request_items (request_id),

  CONSTRAINT fk_price_request_items
    FOREIGN KEY (request_id) REFERENCES price_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('price_request_prefix', 'PRQ'),

  -- Whether the catalogue shows prices at all. On, because a client who
  -- has to ring up to learn a price mostly does not ring up. Off makes
  -- the catalogue a list of what we do, and every price a conversation —
  -- worth considering, since anybody who registers can see it.
  ('portal_show_prices', '1'),

  -- Whether the portal shows stock items as well as services.
  ('portal_show_inventory', '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
