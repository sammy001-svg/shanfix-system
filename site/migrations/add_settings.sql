-- --------------------------------------------------------
-- Settings table — key/value store for all admin-configurable settings
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `setting_key`   varchar(100) NOT NULL,
  `setting_value` text         DEFAULT NULL,
  `setting_group` varchar(50)  NOT NULL DEFAULT 'general',
  `is_sensitive`  tinyint(1)   NOT NULL DEFAULT 0,
  `updated_at`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default seed values
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `is_sensitive`) VALUES
-- General
('company_name',        'Shanfix Technology',                     'general', 0),
('company_tagline',     'Premier IT Solutions & Digital Services', 'general', 0),
('company_phone',       '+254 751 869 165',                        'general', 0),
('company_email',       'info@shanfixtechnology.com',              'general', 0),
('company_address',     'Tana House, Karen - Nairobi, Kenya',      'general', 0),
('company_website',     'https://shanfixtechnology.com',           'general', 0),
('company_logo',        'assets/shanfix-logo.png',                 'general', 0),

-- Email / SMTP
('smtp_host',           '',                 'email', 0),
('smtp_port',           '587',              'email', 0),
('smtp_encryption',     'tls',              'email', 0),
('smtp_username',       '',                 'email', 0),
('smtp_password',       '',                 'email', 1),
('smtp_from_name',      'Shanfix Technology','email', 0),
('smtp_from_email',     'noreply@shanfixtechnology.com', 'email', 0),

-- M-PESA (Daraja)
('mpesa_environment',   'sandbox',          'mpesa', 0),
('mpesa_shortcode',     '',                 'mpesa', 0),
('mpesa_consumer_key',  '',                 'mpesa', 1),
('mpesa_consumer_secret','',                'mpesa', 1),
('mpesa_passkey',       '',                 'mpesa', 1),
('mpesa_callback_url',  'https://shanfixtechnology.com/api/mpesa/callback.php', 'mpesa', 0),

-- Notifications
('notify_new_order',    '1',    'notifications', 0),
('notify_new_ticket',   '1',    'notifications', 0),
('notify_new_client',   '1',    'notifications', 0),
('notify_channel_email','1',    'notifications', 0),
('notify_channel_sms',  '0',    'notifications', 0),
('notify_admin_email',  'admin@shanfixtechnology.com', 'notifications', 0),

-- Bulk SMS
('sms_provider',        'africastalking',   'sms', 0),
('sms_api_key',         '',                 'sms', 1),
('sms_username',        '',                 'sms', 0),
('sms_sender_id',       'Shanfix',          'sms', 0),

-- Social Media
('social_facebook',     '',  'social', 0),
('social_twitter',      '',  'social', 0),
('social_linkedin',     '',  'social', 0),
('social_instagram',    '',  'social', 0),
('social_youtube',      '',  'social', 0),
('social_whatsapp',     '',  'social', 0),

-- SEO & Analytics
('google_analytics_id', '',  'seo', 0),
('google_search_console_verification', '', 'seo', 0),
('meta_pixel_id',       '',  'seo', 0),

-- Security
('session_timeout_minutes', '60',  'security', 0),
('maintenance_mode',        '0',   'security', 0);
