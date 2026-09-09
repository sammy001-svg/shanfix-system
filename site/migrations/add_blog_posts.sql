-- ==========================================================================
-- MIGRATION: Blog / News Posts
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `title`           varchar(255) NOT NULL,
  `slug`            varchar(255) NOT NULL,
  `excerpt`         text DEFAULT NULL,
  `content`         longtext NOT NULL,
  `featured_image`  varchar(255) DEFAULT NULL,
  `category`        varchar(100) DEFAULT 'News',
  `author_id`       int(11) DEFAULT NULL,
  `author_name`     varchar(100) DEFAULT 'Shanfix Team',
  `status`          enum('draft','published') DEFAULT 'draft',
  `views`           int(11) DEFAULT 0,
  `published_at`    datetime DEFAULT NULL,
  `created_at`      timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `status` (`status`),
  KEY `published_at` (`published_at`),
  CONSTRAINT `blog_posts_ibfk_1` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
