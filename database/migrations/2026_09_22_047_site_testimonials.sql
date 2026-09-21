-- =====================================================================
-- Migration 047 — Client testimonials for the website, managed here
--
-- The website showed testimonials from its own database, which had no
-- screen to edit them, and fell back to quotes written into the page
-- code whenever that table was empty. The one row it held, and every
-- fallback, was placeholder text — "Sarah Jenkins, CTO, Global Retail
-- Enterprises" — shown to visitors as though a real client had said it.
--
-- They live in the system now, where staff can add real ones, and the
-- website reads them through site/includes/system.php like its services
-- and prices. With none here, the website shows no testimonials section
-- at all rather than inventing one.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS site_testimonials (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote       TEXT         NOT NULL,
  author      VARCHAR(120) NOT NULL,
  role        VARCHAR(120) DEFAULT NULL,
  company     VARCHAR(160) DEFAULT NULL,
  rating      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  -- Off means kept but not shown: a client who asked for their quote to
  -- come down, or one waiting for their permission to publish.
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_site_testimonials_live (is_active, sort_order),
  CONSTRAINT fk_site_testimonials_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
