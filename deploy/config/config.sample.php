<?php
/**
 * Shanfix Technology BMS - configuration
 *
 * Copy this file to config.php and fill in your cPanel database details.
 * config.php is git-ignored and must never be committed.
 */

return [

    // -----------------------------------------------------------------
    // Database (cPanel > MySQL Databases)
    // -----------------------------------------------------------------
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'cpaneluser_shanfix',
        'username' => 'cpaneluser_shanfix',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // -----------------------------------------------------------------
    // Application
    // -----------------------------------------------------------------
    'app' => [
        'name'     => 'Shanfix Technology',

        // Full public URL of the app, no trailing slash.
        // Used to build KopoKopo callback URLs and links in emails.
        'url'      => 'https://erp.shanfix.co.ke',

        // 'production' hides error details from users. Use 'development' locally.
        'env'      => 'production',
        'debug'    => false,

        'timezone' => 'Africa/Nairobi',
        'locale'   => 'en_KE',

        // Idle session timeout in minutes
        'session_lifetime' => 480,
    ],

    // -----------------------------------------------------------------
    // Security
    // -----------------------------------------------------------------
    'security' => [
        // 32+ random chars. Generate with:
        //   php -r "echo bin2hex(random_bytes(32));"
        // Changing this invalidates all encrypted KopoKopo secrets in settings.
        'app_key'          => '',

        // Set true once the site is served over HTTPS (recommended).
        'secure_cookies'   => true,

        // Failed logins allowed before the account is locked out
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,
    ],

    // -----------------------------------------------------------------
    // File uploads
    // -----------------------------------------------------------------
    'uploads' => [
        'max_size_mb'   => 8,
        'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'],
    ],
];
