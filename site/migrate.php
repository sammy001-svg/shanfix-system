<?php
/**
 * SHANFIX TECHNOLOGY — Database Migration Runner
 * Visit this page once in the browser to apply pending schema changes.
 * DELETE or restrict this file after running in production.
 */
require_once 'includes/db_connect.php';

$results = [];

$migrations = [

    // ── categories: add image_url ─────────────────────────────────────────
    'categories.image_url' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'image_url'",
        'sql'   => "ALTER TABLE `categories` ADD COLUMN `image_url` varchar(255) DEFAULT NULL AFTER `description`",
    ],

    // ── products: add image_url ───────────────────────────────────────────
    'products.image_url' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'image_url'",
        'sql'   => "ALTER TABLE `products` ADD COLUMN `image_url` varchar(255) DEFAULT NULL AFTER `price`",
    ],

    // ── products: add features ────────────────────────────────────────────
    'products.features' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'features'",
        'sql'   => "ALTER TABLE `products` ADD COLUMN `features` text DEFAULT NULL AFTER `description`",
    ],

    // ── products: add is_featured ─────────────────────────────────────────
    'products.is_featured' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_featured'",
        'sql'   => "ALTER TABLE `products` ADD COLUMN `is_featured` tinyint(1) DEFAULT 0 AFTER `image_url`",
    ],

    // ── password_resets table ─────────────────────────────────────────────
    'table.password_resets' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets'",
        'sql'   => "CREATE TABLE IF NOT EXISTS `password_resets` (
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],

    // ── portfolio_projects table ──────────────────────────────────────────
    'table.portfolio_projects' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio_projects'",
        'sql'   => "CREATE TABLE IF NOT EXISTS `portfolio_projects` (
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],

    // ── testimonials table ────────────────────────────────────────────────
    'table.testimonials' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'testimonials'",
        'sql'   => "CREATE TABLE IF NOT EXISTS `testimonials` (
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],

    // ── blog_posts table ──────────────────────────────────────────────────
    'table.blog_posts' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_posts'",
        'sql'   => "CREATE TABLE IF NOT EXISTS `blog_posts` (
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
                      KEY `status` (`status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],

    // ── adverts table ─────────────────────────────────────────────────────
    'table.adverts' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'adverts'",
        'sql'   => "CREATE TABLE IF NOT EXISTS `adverts` (
                      `id`         int(11) NOT NULL AUTO_INCREMENT,
                      `headline`   varchar(200) NOT NULL,
                      `subtitle`   text DEFAULT NULL,
                      `btn1_text`  varchar(100) DEFAULT 'Explore Services',
                      `btn1_link`  varchar(255) DEFAULT '#services',
                      `btn2_text`  varchar(100) DEFAULT NULL,
                      `btn2_link`  varchar(255) DEFAULT NULL,
                      `bg_image`   varchar(255) DEFAULT NULL,
                      `sort_order` int(11) DEFAULT 0,
                      `is_active`  tinyint(1) DEFAULT 1,
                      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],

    // ── banners table ─────────────────────────────────────────────────────
    'table.banners' => [
        'check' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'banners'",
        'sql'   => "CREATE TABLE IF NOT EXISTS `banners` (
                      `id`         int(11) NOT NULL AUTO_INCREMENT,
                      `title`      varchar(100) DEFAULT NULL,
                      `image_url`  varchar(255) NOT NULL,
                      `link_url`   varchar(255) DEFAULT NULL,
                      `sort_order` int(11) DEFAULT 0,
                      `is_active`  tinyint(1) DEFAULT 1,
                      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
];

foreach ($migrations as $key => $m) {
    try {
        $exists = (int)$pdo->query($m['check'])->fetchColumn();
        if ($exists) {
            $results[$key] = ['status' => 'skipped', 'msg' => 'Already exists — no change needed.'];
        } else {
            $pdo->exec($m['sql']);
            $results[$key] = ['status' => 'applied', 'msg' => 'Applied successfully.'];
        }
    } catch (PDOException $e) {
        $results[$key] = ['status' => 'error', 'msg' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Migration — Shanfix</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 40px 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #6366f1; font-size: 1.8rem; margin-bottom: 8px; }
        p.sub { color: #94a3b8; margin-bottom: 32px; font-size: 0.9rem; }
        .row { display: flex; align-items: flex-start; gap: 16px; padding: 14px 20px; border-radius: 12px; margin-bottom: 10px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07); }
        .key { font-family: monospace; font-size: 0.85rem; color: #a5b4fc; width: 220px; flex-shrink: 0; }
        .msg { font-size: 0.85rem; flex: 1; }
        .badge { display: inline-block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; padding: 3px 10px; border-radius: 6px; margin-right: 8px; }
        .applied  { background: rgba(34,197,94,0.15); color: #22c55e; }
        .skipped  { background: rgba(148,163,184,0.15); color: #94a3b8; }
        .error    { background: rgba(239,68,68,0.15); color: #ef4444; }
        .warning { background: #fef3c7; color: #92400e; border-radius: 12px; padding: 16px 20px; margin-top: 32px; font-size: 0.85rem; }
        a.btn { display: inline-block; margin-top: 28px; background: #6366f1; color: white; padding: 12px 28px; border-radius: 10px; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
<div class="container">
    <h1>🗄 Shanfix Database Migration</h1>
    <p class="sub">Running schema checks and applying pending changes to <strong><?= htmlspecialchars($_ENV['DB_NAME'] ?? 'shanfix_tech') ?></strong>…</p>

    <?php foreach ($results as $key => $r): ?>
    <div class="row">
        <div class="key"><?= htmlspecialchars($key) ?></div>
        <div class="msg">
            <span class="badge <?= $r['status'] ?>"><?= $r['status'] ?></span>
            <?= htmlspecialchars($r['msg']) ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php
    $errors  = array_filter($results, fn($r) => $r['status'] === 'error');
    $applied = array_filter($results, fn($r) => $r['status'] === 'applied');
    ?>

    <?php if (empty($errors)): ?>
    <p style="color:#22c55e; margin-top:24px; font-weight:700;">
        ✅ Migration complete — <?= count($applied) ?> change(s) applied, <?= count($results) - count($applied) ?> already up-to-date.
    </p>
    <?php else: ?>
    <p style="color:#ef4444; margin-top:24px; font-weight:700;">
        ⚠ <?= count($errors) ?> error(s) — review the messages above.
    </p>
    <?php endif; ?>

    <div class="warning">
        <strong>Security reminder:</strong> Delete or password-protect <code>migrate.php</code> after running this in production.
    </div>

    <a href="/" class="btn">← Back to the website</a>
</div>
</body>
</html>
