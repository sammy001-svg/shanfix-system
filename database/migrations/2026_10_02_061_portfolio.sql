-- =====================================================================
-- Migration 061 — The portfolio: what we have built, printed and for whom
--
-- The website has a portfolio page and it has never shown any real work.
-- It reads a portfolio_projects table in the site's own database, finds
-- it empty, and falls back to three case studies written into the page:
-- a "Skyline E-Commerce Platform", an "Apex Real-time Trading Dashboard"
-- and a "Nexus Health Tracking App", with figures to match — +140% sales
-- conversion, 1M+ downloads, 10ms latency.
--
-- None of that is ours. It is the same fault the testimonials had, where
-- the site published quotes nobody had said, and it is worse here
-- because it claims client projects and the results of them. A visitor
-- cannot tell the difference, which is the whole problem.
--
-- So the portfolio moves to where the testimonials went: the system's
-- database, entered by staff who know what we actually did, and the
-- website shows what is here and nothing when there is nothing.
--
-- Three things, because the company sells three different kinds of work
-- and they do not display the same way:
--
--   site_projects   websites and systems. A thing with a name, a client,
--                   what it does, what it was built with, and a link
--                   somebody can follow to see it running.
--   site_work       printing and branding. A gallery: these are
--                   photographs of a finished job, and a photograph of a
--                   vehicle wrap says more than any paragraph about it.
--   site_clients    who we have worked for, and their logo. Shown on the
--                   portfolio page and again on the home page, which is
--                   where somebody deciding whether to trust us looks.
--
-- Photographs go through ImageLibrary like every other picture in the
-- system, so the resizing, the thumbnails, the cap and — much more to
-- the point — the checks on what a file actually is are the ones already
-- in use rather than a second set written for this.
--
-- Safe to re-run. Apply with:  php migrate.php  (or open upgrade.php)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Websites and systems
--
-- One table for both. A website and a business system are the same shape
-- on a portfolio page — a name, a client, what it does and a link — and
-- splitting them into two tables would mean writing the screen twice to
-- show the same five fields.
--
-- client_name is free text rather than a link to clients. Some of this
-- work was done for people who are not in the client list, and the name
-- on a public page is the name the client wants shown, which is not
-- always the name on their invoice.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_projects (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind          ENUM('website','system') NOT NULL DEFAULT 'website',
  title         VARCHAR(160) NOT NULL,
  client_name   VARCHAR(160) DEFAULT NULL COMMENT 'shown as "for X"; blank where the client would rather not be named',
  summary       VARCHAR(400) DEFAULT NULL COMMENT 'the one-line version, used on cards',
  description   TEXT         DEFAULT NULL COMMENT 'what the job actually was',
  built_with    VARCHAR(255) DEFAULT NULL COMMENT 'comma separated; shown as tags',
  live_url      VARCHAR(255) DEFAULT NULL,
  completed_on  DATE         DEFAULT NULL,
  is_featured   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'given the large treatment at the top of the page',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by    INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shown (is_active, kind, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_project_images (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  thumb_path  VARCHAR(255) DEFAULT NULL,
  alt_text    VARCHAR(200) DEFAULT NULL,
  is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project (project_id, is_primary, sort_order),
  CONSTRAINT fk_site_project_image FOREIGN KEY (project_id)
    REFERENCES site_projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Printing and branding, as a gallery
--
-- Less text than a project on purpose. Nobody reads a paragraph about a
-- roll-up banner; they look at the photograph and decide whether the
-- finish is what they want. The category is what the filter buttons on
-- the page are built from.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_work (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title        VARCHAR(160) NOT NULL,
  category     VARCHAR(80)  DEFAULT NULL COMMENT 'signage, vehicle branding, large format, corporate gifts…',
  client_name  VARCHAR(160) DEFAULT NULL,
  description  VARCHAR(400) DEFAULT NULL,
  completed_on DATE         DEFAULT NULL,
  is_featured  TINYINT(1)   NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shown (is_active, sort_order, id),
  KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_work_images (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_id     INT UNSIGNED NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  thumb_path  VARCHAR(255) DEFAULT NULL,
  alt_text    VARCHAR(200) DEFAULT NULL,
  is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_work (work_id, is_primary, sort_order),
  CONSTRAINT fk_site_work_image FOREIGN KEY (work_id)
    REFERENCES site_work (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Who we have worked for
--
-- A logo and a name. The logo is a picture like any other here, so it
-- goes through the same upload path — one per client, which the cap in
-- settings enforces.
--
-- A client's logo is their property, and putting it on our website says
-- they are a customer of ours. permission_note records that somebody
-- checked; it is for us, never shown. A row with nothing in it is not a
-- reason to refuse to publish, because plenty of permissions are given
-- in a conversation — but the field being there is the question being
-- asked.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_clients (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160) NOT NULL,
  website_url     VARCHAR(255) DEFAULT NULL,
  what_we_did     VARCHAR(255) DEFAULT NULL COMMENT 'one line, shown under the logo on the portfolio page',
  permission_note VARCHAR(255) DEFAULT NULL COMMENT 'who agreed to us showing their mark, and when. Internal.',
  is_featured     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'on the home page strip as well as the portfolio page',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shown (is_active, is_featured, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_client_images (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id   INT UNSIGNED NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  thumb_path  VARCHAR(255) DEFAULT NULL,
  alt_text    VARCHAR(200) DEFAULT NULL,
  is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_client (client_id, is_primary, sort_order),
  CONSTRAINT fk_site_client_image FOREIGN KEY (client_id)
    REFERENCES site_clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- How many pictures each thing may have
--
-- A project gets a handful of screens. A printing job gets more, because
-- a vehicle wrap is worth showing from several sides. A client gets one,
-- which is their logo.
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
  ('project_images_max', '6'),
  ('work_images_max',    '8'),
  ('client_images_max',  '1')
ON DUPLICATE KEY UPDATE setting_value = settings.setting_value;
