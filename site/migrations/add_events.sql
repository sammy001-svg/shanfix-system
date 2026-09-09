-- ============================================================
-- Events, Ticket Types, Bookings
-- ============================================================

CREATE TABLE IF NOT EXISTS `events` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `title`         varchar(200) NOT NULL,
  `slug`          varchar(220) NOT NULL,
  `description`   text         DEFAULT NULL,
  `event_date`    datetime     NOT NULL,
  `end_date`      datetime     DEFAULT NULL,
  `venue`         varchar(200) DEFAULT NULL,
  `venue_address` varchar(300) DEFAULT NULL,
  `image_url`     varchar(255) DEFAULT NULL,
  `organizer`     varchar(100) DEFAULT 'Shanfix Technology',
  `status`        enum('draft','published','cancelled','completed') DEFAULT 'published',
  `is_featured`   tinyint(1)   DEFAULT 0,
  `created_at`    timestamp    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `event_ticket_types` (
  `id`          int(11)       NOT NULL AUTO_INCREMENT,
  `event_id`    int(11)       NOT NULL,
  `name`        varchar(100)  NOT NULL,
  `description` varchar(255)  DEFAULT NULL,
  `price`       decimal(10,2) NOT NULL DEFAULT 0.00,
  `capacity`    int(11)       DEFAULT NULL,
  `sold_count`  int(11)       NOT NULL DEFAULT 0,
  `sale_starts` datetime      DEFAULT NULL,
  `sale_ends`   datetime      DEFAULT NULL,
  `status`      enum('active','paused','sold_out') DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `ett_event_fk` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `event_bookings` (
  `id`                  int(11)       NOT NULL AUTO_INCREMENT,
  `reference`           varchar(20)   NOT NULL,
  `event_id`            int(11)       NOT NULL,
  `buyer_name`          varchar(100)  NOT NULL,
  `buyer_email`         varchar(100)  NOT NULL,
  `buyer_phone`         varchar(25)   NOT NULL,
  `total_amount`        decimal(10,2) NOT NULL,
  `payment_status`      enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `mpesa_receipt`       varchar(50)   DEFAULT NULL,
  `checkout_request_id` varchar(100)  DEFAULT NULL,
  `created_at`          timestamp     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  KEY `event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `event_booking_tickets` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `booking_id`       int(11)       NOT NULL,
  `ticket_type_id`   int(11)       NOT NULL,
  `ticket_type_name` varchar(100)  NOT NULL,
  `quantity`         int(11)       NOT NULL DEFAULT 1,
  `unit_price`       decimal(10,2) NOT NULL,
  `ticket_codes`     text          DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure testimonials table exists (referenced by admin API)
CREATE TABLE IF NOT EXISTS `testimonials` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `quote`       text         NOT NULL,
  `author`      varchar(100) NOT NULL,
  `company`     varchar(100) DEFAULT NULL,
  `role`        varchar(100) DEFAULT NULL,
  `rating`      tinyint(1)   DEFAULT 5,
  `sort_order`  int(11)      DEFAULT 0,
  `is_active`   tinyint(1)   DEFAULT 1,
  `created_at`  timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample events
INSERT IGNORE INTO `events` (`id`,`title`,`slug`,`description`,`event_date`,`venue`,`organizer`,`status`,`is_featured`) VALUES
(1,'Nairobi Tech Summit 2026','nairobi-tech-summit-2026','Kenya\'s premier technology conference — featuring keynotes, workshops, and networking with industry leaders across East Africa.','2026-08-15 09:00:00','Kenyatta International Convention Centre, Nairobi','Shanfix Technology','published',1),
(2,'Digital Marketing Masterclass','digital-marketing-masterclass-2026','A full-day intensive workshop on SEO, social media strategy, paid ads, and content marketing for Kenyan businesses.','2026-07-20 08:30:00','Radisson Blu Hotel, Nairobi','Shanfix Technology','published',0);

INSERT IGNORE INTO `event_ticket_types` (`event_id`,`name`,`description`,`price`,`capacity`,`status`) VALUES
(1,'Early Bird',   'Limited seats — grab this while it lasts!',  1500.00, 100, 'active'),
(1,'Regular',      'Standard conference admission',               2500.00, 300, 'active'),
(1,'VIP',          'VIP lounge access + conference proceedings',  5000.00,  50, 'active'),
(2,'Workshop Seat','Full-day workshop with materials',            3500.00, 80,  'active'),
(2,'Group (5+)',   'Book 5+ seats and save 20%',                  2800.00, NULL,'active');
