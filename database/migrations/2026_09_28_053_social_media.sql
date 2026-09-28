-- =====================================================================
-- Migration 053 — Running the company's social media
--
-- Somebody here posts for Shanfix every week and has been doing it out
-- of their own head: what went out, when, which picture went with it,
-- what the caption said, whether anybody saw it. None of that was
-- written down anywhere, so nobody could answer "what did we post in
-- June" or "which of these is actually worth the effort".
--
-- This is the record and the plan. Four ideas, in order of how much
-- they matter:
--
--   A post is one idea, sent to several places. "December offers" goes
--   to Facebook, Instagram and LinkedIn — one caption written once, one
--   picture, three places it lands. So the caption lives on the post
--   and each place it goes is a row of its own, carrying the link it
--   ended up at and the numbers it did there. Keeping one row per
--   platform instead would mean writing the same caption three times
--   and then discovering they had drifted apart.
--
--   Nothing is published from here. The numbers and the links are typed
--   in by the person who posted. Publishing to Facebook or Instagram
--   needs an app the networks have reviewed and approved, which is
--   paperwork rather than programming, and none of the planning is
--   worth less for being done by hand in the meantime. The columns that
--   a publisher would fill are the same ones a person fills now, so
--   adding one later changes nothing about this shape.
--
--   A campaign is optional. Most posts are not part of anything; the
--   ones that are want grouping in the reports.
--
--   Pictures go through ImageLibrary, like products and services, so
--   resizing, thumbnails and the size cap are the ones already in use.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- The profiles we post from
--
-- Several per network on purpose: a company ends up with a main page
-- and a second one for a product line, and posting to the wrong one is
-- the mistake this is meant to make visible.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_accounts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- Matches a key of Settings::SOCIAL, so the icons and the labels the
  -- website footer already uses work here without a second list.
  network       VARCHAR(20)  NOT NULL,
  -- What it is called in the office: "Shanfix Kenya", "Shanfix Signs".
  name          VARCHAR(80)  NOT NULL,
  handle        VARCHAR(80)  DEFAULT NULL,
  profile_url   VARCHAR(255) DEFAULT NULL,
  -- Roughly how many people follow it, typed in when somebody looks.
  -- Enough to say whether the number is going up.
  followers     INT UNSIGNED NOT NULL DEFAULT 0,
  followers_at  DATETIME     DEFAULT NULL,
  notes         VARCHAR(255) DEFAULT NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  position      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_social_acc_live (status, position),
  KEY idx_social_acc_net (network)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- A push worth grouping
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_campaigns (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  -- What it is for, in a sentence. Read in the report months later by
  -- somebody deciding whether to run it again.
  goal          VARCHAR(255) DEFAULT NULL,
  starts_on     DATE         DEFAULT NULL,
  ends_on       DATE         DEFAULT NULL,
  -- What was spent boosting it, if anything. Kept here rather than in
  -- expenses because this is the figure the report divides by.
  spend         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status        ENUM('planned','running','done','cancelled') NOT NULL DEFAULT 'planned',
  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_social_camp_when (starts_on, ends_on),
  CONSTRAINT fk_social_camp_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The post itself
--
-- One idea. The caption is written once here, whatever number of places
-- it is going to.
--
-- 'scheduled' is not a status. A post is approved or it is not, and it
-- has a date or it has not — two facts, not three states. Making it a
-- status means a post can be scheduled without being approved, which is
-- exactly the thing an approval step exists to prevent.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_posts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- Short reference somebody can say out loud: SP-2609-0041.
  ref           VARCHAR(20)  NOT NULL,
  campaign_id   INT UNSIGNED DEFAULT NULL,
  -- What it is about, for the calendar and the lists. The caption is
  -- too long to read at a glance and often starts with an emoji.
  title         VARCHAR(140) NOT NULL,
  caption       TEXT         DEFAULT NULL,
  -- The hashtags, kept apart from the caption so they can be reused and
  -- counted without being picked back out of prose.
  hashtags      VARCHAR(500) DEFAULT NULL,
  -- What a caption cannot hold: the link in the bio, the first comment.
  link_url      VARCHAR(255) DEFAULT NULL,
  first_comment VARCHAR(500) DEFAULT NULL,
  post_type     ENUM('post','story','reel','video','carousel','article') NOT NULL DEFAULT 'post',
  status        ENUM('idea','draft','awaiting','approved','published','cancelled')
                NOT NULL DEFAULT 'draft',
  -- When it should go out. A post with no date is an idea waiting for a
  -- slot, and the calendar leaves it in the holding list rather than
  -- guessing at today.
  scheduled_for DATETIME     DEFAULT NULL,
  published_at  DATETIME     DEFAULT NULL,
  -- Who is writing it, and who said yes.
  owner_id      INT UNSIGNED DEFAULT NULL,
  created_by    INT UNSIGNED DEFAULT NULL,
  approved_by   INT UNSIGNED DEFAULT NULL,
  approved_at   DATETIME     DEFAULT NULL,
  -- Why it was sent back, so the writer is told something more useful
  -- than that it was not approved.
  changes_asked VARCHAR(500) DEFAULT NULL,
  notes         VARCHAR(1000) DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_social_post_ref (ref),
  -- The calendar's own query: everything with a date, in a month.
  KEY idx_social_post_when (scheduled_for),
  KEY idx_social_post_state (status, scheduled_for),
  KEY idx_social_post_camp (campaign_id),
  KEY idx_social_post_owner (owner_id, status),
  CONSTRAINT fk_social_post_camp  FOREIGN KEY (campaign_id) REFERENCES social_campaigns(id) ON DELETE SET NULL,
  CONSTRAINT fk_social_post_owner FOREIGN KEY (owner_id)    REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_social_post_maker FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_social_post_appr  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Where each post went, and how it did there
--
-- One row per place a post lands. The same caption on Instagram and on
-- LinkedIn is two different results, and averaging them hides which of
-- the two is worth the effort.
--
-- The numbers are typed in by whoever posted, days later, from the app.
-- All nullable and all defaulting to zero: a post nobody has checked on
-- yet is not a post with no engagement, and the reports have to be able
-- to tell those apart — which is what metrics_at is for.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_post_targets (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id       BIGINT UNSIGNED NOT NULL,
  account_id    INT UNSIGNED    NOT NULL,
  -- Where it ended up. Pasted in after posting, and the only way back
  -- to the thing itself from a report.
  post_url      VARCHAR(255) DEFAULT NULL,
  published_at  DATETIME     DEFAULT NULL,

  reach         INT UNSIGNED NOT NULL DEFAULT 0,
  impressions   INT UNSIGNED NOT NULL DEFAULT 0,
  likes         INT UNSIGNED NOT NULL DEFAULT 0,
  comments      INT UNSIGNED NOT NULL DEFAULT 0,
  shares        INT UNSIGNED NOT NULL DEFAULT 0,
  saves         INT UNSIGNED NOT NULL DEFAULT 0,
  clicks        INT UNSIGNED NOT NULL DEFAULT 0,
  -- People who followed the page because of this one. The figure that
  -- says whether posting is growing anything.
  follows       INT UNSIGNED NOT NULL DEFAULT 0,
  -- When somebody last wrote the numbers down. NULL means never, which
  -- is a different thing from all zeroes.
  metrics_at    DATETIME     DEFAULT NULL,

  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- A post goes to a profile once. Sending it there twice is a mistake,
  -- not a plan.
  UNIQUE KEY uq_social_target (post_id, account_id),
  KEY idx_social_target_acc (account_id, published_at),
  CONSTRAINT fk_social_target_post FOREIGN KEY (post_id)    REFERENCES social_posts(id)    ON DELETE CASCADE,
  CONSTRAINT fk_social_target_acc  FOREIGN KEY (account_id) REFERENCES social_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The pictures
--
-- Shaped exactly like inventory_images and service_images, because
-- ImageLibrary handles all three the same way: the upload, the resize,
-- the thumbnail and the cap are already written and already tested.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_post_images (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id       BIGINT UNSIGNED NOT NULL,
  file_path     VARCHAR(255) NOT NULL,
  thumb_path    VARCHAR(255) DEFAULT NULL,
  file_name     VARCHAR(200) DEFAULT NULL,
  file_size     INT UNSIGNED NOT NULL DEFAULT 0,
  width         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  height        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  alt_text      VARCHAR(200) DEFAULT NULL,
  is_primary    TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by   INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_social_img_post (post_id, sort_order),
  CONSTRAINT fk_social_img_post FOREIGN KEY (post_id)     REFERENCES social_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_social_img_user FOREIGN KEY (uploaded_by) REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  -- Whether a post needs somebody's yes before it can be marked
  -- published. On by default: what goes out under the company's name is
  -- worth a second pair of eyes, and turning it off is a decision.
  ('social_approval_required', '1'),
  -- How many pictures one post may carry. Read by ImageLibrary.
  ('social_images_max',        '10'),
  -- How long before a scheduled post the person writing it is reminded.
  ('social_remind_hours',      '24')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
