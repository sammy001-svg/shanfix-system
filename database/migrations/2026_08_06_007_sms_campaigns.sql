-- =====================================================================
-- Migration 007 — Bulk SMS campaigns
--
-- One message to many clients, sent through the gateway's bulksend
-- endpoint in batches.
--
-- Two tables rather than one. The gateway reports totals per batch, not a
-- status per handset, so the campaign row holds what it actually told us.
-- The recipients table answers the question the totals cannot: "did Acme
-- get the message?" — which is the first thing anyone asks afterwards.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sms_campaigns (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(140) NOT NULL,
  message       VARCHAR(918) NOT NULL,
  audience      VARCHAR(255) DEFAULT NULL,   -- the filter, in words, for the log
  parts         TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- credits per recipient
  recipients    INT UNSIGNED NOT NULL DEFAULT 0,
  sent          INT UNSIGNED NOT NULL DEFAULT 0,
  failed        INT UNSIGNED NOT NULL DEFAULT 0,
  invalid       INT UNSIGNED NOT NULL DEFAULT 0,
  cost          DECIMAL(12,4) DEFAULT NULL,  -- units the gateway charged
  balance_after VARCHAR(30)  DEFAULT NULL,
  status        ENUM('sending','sent','partial','failed') NOT NULL DEFAULT 'sending',
  error         VARCHAR(500) DEFAULT NULL,
  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at  DATETIME DEFAULT NULL,
  KEY idx_campaign_created (created_at),
  KEY idx_campaign_status (status),
  CONSTRAINT fk_campaign_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Who the message went to. "skipped" covers a number the gateway would
-- not accept, recorded so nobody wonders why a client heard nothing.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sms_campaign_recipients (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  client_id   INT UNSIGNED DEFAULT NULL,
  name        VARCHAR(180) DEFAULT NULL,
  phone       VARCHAR(20)  NOT NULL,
  status      ENUM('submitted','skipped') NOT NULL DEFAULT 'submitted',
  reason      VARCHAR(120) DEFAULT NULL,
  KEY idx_crecip_campaign (campaign_id),
  KEY idx_crecip_client (client_id),
  CONSTRAINT fk_crecip_campaign FOREIGN KEY (campaign_id)
    REFERENCES sms_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_crecip_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
