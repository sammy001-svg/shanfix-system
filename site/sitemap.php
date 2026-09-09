<?php
require_once 'includes/db_connect.php';

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';

$base = 'https://shanfixtechnology.com';
$today = date('Y-m-d');

$staticPages = [
    ['loc' => '/',                     'lastmod' => $today,       'changefreq' => 'weekly',  'priority' => '1.0'],
    ['loc' => '/who-we-are.php',       'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => '/portfolio.php',        'lastmod' => $today,       'changefreq' => 'weekly',  'priority' => '0.8'],
    ['loc' => '/web-development.php',  'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.9'],
    ['loc' => '/app-development.php',  'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => '/software-solution.php','lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.9'],
    ['loc' => '/web-hosting.php',      'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => '/networking-solution.php','lastmod'=> '2026-05-01','changefreq' => 'monthly', 'priority' => '0.7'],
    ['loc' => '/digital-marketing.php','lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => '/seo-boost.php',        'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => '/bulk-sms.php',         'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => '/graphics-design.php',  'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['loc' => '/consultancy.php',      'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.6'],
    ['loc' => '/printing-branding.php','lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['loc' => '/signage-solution.php', 'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['loc' => '/erp-solution.php',     'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => '/pos-solution.php',     'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => '/school-management.php','lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['loc' => '/event-management.php', 'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['loc' => '/event-ticketing.php',  'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['loc' => '/blog.php',             'lastmod' => $today,       'changefreq' => 'daily',   'priority' => '0.8'],
    ['loc' => '/contact.php',          'lastmod' => '2026-05-01', 'changefreq' => 'monthly', 'priority' => '0.6'],
];

// Fetch published blog posts
$blogPosts = [];
try {
    $s = $pdo->query("SELECT slug, updated_at, published_at FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC");
    $blogPosts = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($staticPages as $p): ?>
  <url>
    <loc><?= $base . $p['loc'] ?></loc>
    <lastmod><?= $p['lastmod'] ?></lastmod>
    <changefreq><?= $p['changefreq'] ?></changefreq>
    <priority><?= $p['priority'] ?></priority>
  </url>
<?php endforeach; ?>
<?php foreach ($blogPosts as $post): ?>
  <url>
    <loc><?= $base ?>/post.php?slug=<?= urlencode($post['slug']) ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($post['updated_at'] ?? $post['published_at'])) ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.6</priority>
  </url>
<?php endforeach; ?>
</urlset>
