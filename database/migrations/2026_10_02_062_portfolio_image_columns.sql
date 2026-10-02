-- =====================================================================
-- Migration 062 — The portfolio image tables, matching what writes to them
--
-- 061 created the three image tables by hand and gave them the columns
-- the portfolio page reads: the path, the thumbnail, the alt text, which
-- one leads, and the order. It left out the five that ImageLibrary also
-- writes — the original filename, the size, the dimensions and who
-- uploaded it — because nothing on the page displays them.
--
-- ImageLibrary does not care what the page displays. It writes the same
-- row for every kind of picture in the system, so the insert failed on
-- the first upload and the picture was silently not there.
--
-- They are worth having for their own sake. file_name is what somebody
-- recognises when they are looking for the photograph they meant to
-- upload; width and height are how a page reserves the right space
-- before the image arrives; uploaded_by is who to ask about a picture a
-- client has objected to.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- site_project_images -------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'site_project_images' AND column_name = 'file_name');

SET @sql := IF(@col = 0,
  'ALTER TABLE site_project_images
     ADD COLUMN file_name   VARCHAR(200) DEFAULT NULL AFTER thumb_path,
     ADD COLUMN file_size   INT UNSIGNED DEFAULT NULL AFTER file_name,
     ADD COLUMN width       SMALLINT UNSIGNED DEFAULT NULL AFTER file_size,
     ADD COLUMN height      SMALLINT UNSIGNED DEFAULT NULL AFTER width,
     ADD COLUMN uploaded_by INT UNSIGNED DEFAULT NULL AFTER sort_order',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- site_work_images ----------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'site_work_images' AND column_name = 'file_name');

SET @sql := IF(@col = 0,
  'ALTER TABLE site_work_images
     ADD COLUMN file_name   VARCHAR(200) DEFAULT NULL AFTER thumb_path,
     ADD COLUMN file_size   INT UNSIGNED DEFAULT NULL AFTER file_name,
     ADD COLUMN width       SMALLINT UNSIGNED DEFAULT NULL AFTER file_size,
     ADD COLUMN height      SMALLINT UNSIGNED DEFAULT NULL AFTER width,
     ADD COLUMN uploaded_by INT UNSIGNED DEFAULT NULL AFTER sort_order',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- site_client_images --------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'site_client_images' AND column_name = 'file_name');

SET @sql := IF(@col = 0,
  'ALTER TABLE site_client_images
     ADD COLUMN file_name   VARCHAR(200) DEFAULT NULL AFTER thumb_path,
     ADD COLUMN file_size   INT UNSIGNED DEFAULT NULL AFTER file_name,
     ADD COLUMN width       SMALLINT UNSIGNED DEFAULT NULL AFTER file_size,
     ADD COLUMN height      SMALLINT UNSIGNED DEFAULT NULL AFTER width,
     ADD COLUMN uploaded_by INT UNSIGNED DEFAULT NULL AFTER sort_order',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
