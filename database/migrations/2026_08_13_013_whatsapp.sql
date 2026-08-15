-- =====================================================================
-- Migration 013 — WhatsApp, as a shared company inbox
--
-- Built on Meta's official WhatsApp Business Cloud API: we send over
-- HTTPS and receive on a webhook, which is exactly the shape ordinary
-- hosting can serve. There is no QR code and no browser session to keep
-- alive — the number is registered with Meta once and stays registered.
--
-- Two tables, mirroring how the conversation actually works:
--   whatsapp_conversations  one per customer number, whoever replies
--   whatsapp_messages       every message either way, in order
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_conversations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- The customer's number in the form WhatsApp uses: digits only, with
  -- country code, no plus. This is the identity of the conversation.
  wa_id         VARCHAR(20) NOT NULL,
  display_name  VARCHAR(160) DEFAULT NULL,

  -- Matched to a client where we recognise the number, so a chat sits
  -- alongside their invoices rather than floating on its own.
  client_id     INT UNSIGNED DEFAULT NULL,

  -- open | closed
  status        VARCHAR(20) NOT NULL DEFAULT 'open',
  assigned_to   INT UNSIGNED DEFAULT NULL,

  last_message_at DATETIME DEFAULT NULL,

  -- When the customer last wrote to us.
  --
  -- WhatsApp only allows a free-typed reply within 24 hours of this;
  -- after that Meta requires a pre-approved template. Storing it means
  -- the inbox can say so before someone types a message that would be
  -- rejected, rather than after.
  last_inbound_at DATETIME DEFAULT NULL,

  unread_count  INT UNSIGNED NOT NULL DEFAULT 0,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- One thread per number. Everything else keys off this.
  UNIQUE KEY uq_wa_conversation (wa_id),
  KEY idx_wa_recent (status, last_message_at),
  KEY idx_wa_client (client_id),

  CONSTRAINT fk_wa_conv_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_wa_conv_user FOREIGN KEY (assigned_to)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_messages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,

  -- Meta's own id for the message. Unique because their webhooks are
  -- delivered at least once — the same message arrives again if our
  -- acknowledgement is slow, and this is what stops it being stored twice.
  wa_message_id   VARCHAR(128) DEFAULT NULL,

  -- in | out
  direction       VARCHAR(4) NOT NULL,
  -- text | image | document | audio | video | sticker | location | contacts | unknown
  msg_type        VARCHAR(20) NOT NULL DEFAULT 'text',
  body            TEXT DEFAULT NULL,

  -- Media stays on Meta's servers behind an id; we keep the reference and
  -- fetch it on demand rather than mirroring every photo a customer sends.
  media_id        VARCHAR(128) DEFAULT NULL,
  media_mime      VARCHAR(100) DEFAULT NULL,
  media_name      VARCHAR(200) DEFAULT NULL,

  -- queued | sent | delivered | read | failed
  status          VARCHAR(20) NOT NULL DEFAULT 'sent',
  error           VARCHAR(400) DEFAULT NULL,

  -- Null for anything the customer sent.
  sent_by         INT UNSIGNED DEFAULT NULL,

  wa_timestamp    DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_wa_message (wa_message_id),
  KEY idx_wa_thread (conversation_id, id),

  CONSTRAINT fk_wa_msg_conv FOREIGN KEY (conversation_id)
    REFERENCES whatsapp_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_msg_user FOREIGN KEY (sent_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Connection settings.
--
-- The access token and app secret are held encrypted — Settings treats
-- both as secrets. Everything here is blank until an administrator fills
-- it in on the Settings page.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('whatsapp_enabled',          '0'),
  ('whatsapp_phone_number_id',  ''),
  ('whatsapp_business_id',      ''),
  ('whatsapp_access_token',     ''),
  ('whatsapp_app_secret',       ''),
  -- Ours to choose: Meta echoes it back when it first calls the webhook,
  -- and a mismatch is how we know the call was not from them.
  ('whatsapp_verify_token',     ''),
  ('whatsapp_number_display',   '')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
