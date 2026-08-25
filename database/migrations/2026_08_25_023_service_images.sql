-- =====================================================================
-- Migration 023 — photos on a service
--
-- Inventory items could carry photos; services could not. But half of
-- what this company sells is a service — signage, branding, a website —
-- and showing a client three examples of work already done is worth more
-- than any description of it.
--
-- Deliberately the same shape as inventory_images rather than something
-- cleverer. The upload handling, the resizing, the thumbnails and the
-- viewer all already exist for that table, and a second table shaped the
-- same way reuses every bit of it.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS service_images (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id  INT UNSIGNED NOT NULL,

  file_path   VARCHAR(255) NOT NULL,
  thumb_path  VARCHAR(255) NULL,
  file_name   VARCHAR(200) NOT NULL COMMENT 'What it was called on the way in; never used as a path',
  file_size   INT UNSIGNED NOT NULL DEFAULT 0,
  width       SMALLINT UNSIGNED NULL,
  height      SMALLINT UNSIGNED NULL,
  alt_text    VARCHAR(200) NULL,

  -- The one shown wherever a single picture has to stand for the service.
  is_primary  TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  uploaded_by INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_service_images (service_id, sort_order),
  KEY idx_service_images_user (uploaded_by),

  CONSTRAINT fk_service_images_service
    FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE,
  CONSTRAINT fk_service_images_user
    FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('service_images_max', '6')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
