-- =====================================================================
-- Migration 056 — Social posts and the work the studio actually made
--
-- Nearly every post is a picture somebody in the studio made, and until
-- now the way to get it onto a post was to find the file again and
-- upload it a second time. The artwork module has it already: approved,
-- versioned, with the client's sign-off recorded against it.
--
-- Two columns, and the second is the one that matters.
--
--   social_posts.client_id — whose work this post is showing. The
--   "client story" post is the commonest kind a print shop makes: here
--   is the signage we did for the hotel. Recording which client lets
--   the report say who has been featured, and stops the same one going
--   out twice in a fortnight.
--
--   social_post_images.artwork_file_id — where the picture came from.
--   The file itself is copied rather than referenced, so removing an
--   artwork request cannot blank a post that has already gone out; this
--   only remembers the origin, for somebody asking "which proof is
--   that?" a year later.
--
-- What this deliberately does not add is the other direction: raising
-- an artwork request from a social post. artwork_requests.client_id is
-- NOT NULL, because every request in that module belongs to a client,
-- and our own marketing graphics belong to nobody. Making that column
-- nullable is a decision about the artwork module, to be taken on its
-- own merits rather than as a side effect of this one.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Whose work the post is showing
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'social_posts' AND column_name = 'client_id');

SET @sql := IF(@col = 0,
  'ALTER TABLE social_posts
     ADD COLUMN client_id INT UNSIGNED DEFAULT NULL
         COMMENT ''The client whose work this post features, if any''
         AFTER campaign_id,
     ADD KEY idx_social_post_client (client_id),
     ADD CONSTRAINT fk_social_post_client FOREIGN KEY (client_id)
         REFERENCES clients(id) ON DELETE SET NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Where a picture came from
--
-- ON DELETE SET NULL rather than CASCADE, and the file copied rather
-- than shared: a post that has gone out is a record of what the public
-- saw, and tidying up an old artwork request two years later must not
-- reach into it.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'social_post_images' AND column_name = 'artwork_file_id');

SET @sql := IF(@col = 0,
  'ALTER TABLE social_post_images
     ADD COLUMN artwork_file_id INT UNSIGNED DEFAULT NULL
         COMMENT ''The artwork_files row this was taken from, if any''
         AFTER post_id,
     ADD KEY idx_social_img_artwork (artwork_file_id),
     ADD CONSTRAINT fk_social_img_artwork FOREIGN KEY (artwork_file_id)
         REFERENCES artwork_files(id) ON DELETE SET NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
