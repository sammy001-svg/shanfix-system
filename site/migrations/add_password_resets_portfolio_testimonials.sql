-- ==========================================================================
-- MIGRATION: Password Resets, Portfolio Projects, Testimonials
-- Run once against the shanfix_tech database
-- ==========================================================================

-- ── 1. Password reset tokens ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `email`      varchar(100) NOT NULL,
  `token`      varchar(64)  NOT NULL,
  `role`       enum('client','admin') NOT NULL DEFAULT 'client',
  `expires_at` datetime NOT NULL,
  `used`       tinyint(1) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2. Portfolio projects ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `portfolio_projects` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `title`       varchar(200) NOT NULL,
  `badge`       varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_url`   varchar(255) DEFAULT NULL,
  `live_url`    varchar(255) DEFAULT NULL,
  `stat1_val`   varchar(50)  DEFAULT NULL,
  `stat1_label` varchar(100) DEFAULT NULL,
  `stat2_val`   varchar(50)  DEFAULT NULL,
  `stat2_label` varchar(100) DEFAULT NULL,
  `sort_order`  int(11) DEFAULT 0,
  `is_active`   tinyint(1) DEFAULT 1,
  `is_featured` tinyint(1) DEFAULT 0,
  `created_at`  timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. Testimonials ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `testimonials` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `quote`      text NOT NULL,
  `author`     varchar(100) NOT NULL,
  `company`    varchar(100) DEFAULT NULL,
  `role`       varchar(100) DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `rating`     tinyint(1) DEFAULT 5,
  `sort_order` int(11) DEFAULT 0,
  `is_active`  tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed one default testimonial so homepage doesn't show empty
INSERT IGNORE INTO `testimonials` (`id`, `quote`, `author`, `company`, `role`, `rating`, `sort_order`) VALUES
(1, 'Shanfix didn\'t just build our platform; they completely revolutionized our digital business model. Their premium aesthetic and engineering depth are unmatched.', 'Sarah Jenkins', 'Global Retail Enterprises', 'CTO', 5, 0);
