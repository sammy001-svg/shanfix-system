-- =====================================================================
-- Migration 006 — Product images on inventory items
--
-- A separate table rather than a column on inventory_items, because a
-- branded product usually needs several shots: the plain item, the item
-- with artwork applied, colour variants, a size reference.
--
-- One image per item is flagged primary; that is the one shown in
-- listings, on quotations and in the catalogue picker.
--
-- Safe to re-run. Apply with:  php migrate.php
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_images (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id      INT UNSIGNED NOT NULL,
  file_path    VARCHAR(255) NOT NULL,   -- relative to storage/, full size
  thumb_path   VARCHAR(255) DEFAULT NULL, -- NULL when GD is unavailable
  file_name    VARCHAR(200) NOT NULL,   -- original name, for download
  file_size    INT UNSIGNED NOT NULL DEFAULT 0,
  width        SMALLINT UNSIGNED DEFAULT NULL,
  height       SMALLINT UNSIGNED DEFAULT NULL,
  alt_text     VARCHAR(200) DEFAULT NULL,
  is_primary   TINYINT(1) NOT NULL DEFAULT 0,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by  INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_invimg_item (item_id, sort_order),
  KEY idx_invimg_primary (item_id, is_primary),
  CONSTRAINT fk_invimg_item FOREIGN KEY (item_id)
    REFERENCES inventory_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_invimg_user FOREIGN KEY (uploaded_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- How many images an item may carry, and how large they may be.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('product_images_max',       '6'),
  ('product_image_max_px',     '1600'),   -- longest edge after resizing
  ('product_thumb_px',         '400')     -- longest edge of the thumbnail
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
