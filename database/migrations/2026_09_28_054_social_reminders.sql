-- =====================================================================
-- Migration 054 — Reminding somebody a post is due
--
-- Migration 053 seeded social_remind_hours and nothing ever read it.
-- Social media was not in cron at all, so a post scheduled for nine
-- tomorrow morning reminded nobody, and one that should have gone out
-- an hour ago sat there looking exactly like one that had.
--
-- That is the whole difference between a calendar somebody works from
-- and a calendar somebody fills in once and abandons.
--
-- Two moments, and they are not the same thing:
--
--   The nudge, some hours before — "this is due tomorrow, is it ready".
--   Useful, easy to ignore, and the writer's own business.
--
--   The miss, after the time has passed with nothing published — "this
--   should have gone out". That one is a problem, not a note, and it
--   goes to whoever can approve as well as to the writer, because a
--   post sitting unapproved at its own publishing time is usually
--   waiting on somebody else.
--
-- A stamp each, so each fires once. Cleared when the post is moved, so
-- a rescheduled post gets reminded about again — the alternative is a
-- post moved to next week that nobody is ever told about twice.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'social_posts' AND column_name = 'reminded_at');

SET @sql := IF(@col = 0,
  'ALTER TABLE social_posts
     ADD COLUMN reminded_at DATETIME DEFAULT NULL
         COMMENT ''When the writer was nudged that this is coming up''
         AFTER scheduled_for,
     ADD COLUMN missed_at DATETIME DEFAULT NULL
         COMMENT ''When somebody was told this had not gone out''
         AFTER reminded_at',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The sweep asks for posts with a date, not yet published, whose stamp
-- is still null. Without this it is a scan of every post ever written,
-- four times an hour, for ever.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = 'social_posts'
                AND index_name = 'idx_social_post_due');

SET @sql := IF(@idx = 0,
  'ALTER TABLE social_posts
     ADD KEY idx_social_post_due (status, scheduled_for, reminded_at, missed_at)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  -- Whether to send the Monday note at all. On: a weekly line saying
  -- what is coming and what is still owed numbers is the thing that
  -- keeps a calendar being worked from.
  ('social_weekly_digest', '1'),
  -- Which day it goes out. 1 is Monday, which is when somebody plans
  -- the week they are about to have rather than the one they had.
  ('social_digest_day',    '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
