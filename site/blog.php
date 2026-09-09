<?php
require_once 'includes/db_connect.php';

// Fetch published posts (with optional category filter)
$cat    = trim($_GET['category'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;
$offset = ($page - 1) * $perPage;

try {
    $where  = "WHERE status = 'published'";
    $params = [];
    if ($cat) { $where .= " AND category = ?"; $params[] = $cat; }

    $total = (int)$pdo->prepare("SELECT COUNT(*) FROM blog_posts $where")->execute($params)
        ? $pdo->prepare("SELECT COUNT(*) FROM blog_posts $where")->execute($params) && ($s = $pdo->prepare("SELECT COUNT(*) FROM blog_posts $where")) && $s->execute($params) ? $s->fetchColumn() : 0
        : 0;

    // Simpler count query
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM blog_posts $where");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $stmt = $pdo->prepare("SELECT id, title, slug, excerpt, featured_image, category, author_name, views, published_at FROM blog_posts $where ORDER BY published_at DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch available categories for filter bar
    $catStmt = $pdo->query("SELECT DISTINCT category FROM blog_posts WHERE status='published' AND category IS NOT NULL ORDER BY category");
    $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $posts = []; $total = 0; $totalPages = 1; $categories = [];
}

$pageSEO = [
    'title'       => 'Blog | Tech Insights & News - Shanfix Technology Nairobi',
    'description' => 'Read the latest tech insights, industry news, and tips from Shanfix Technology. Covering web development, digital marketing, software, and IT in Kenya.',
    'keywords'    => 'tech blog Kenya, IT news Nairobi, technology insights, Shanfix Technology blog',
    'canonical'   => 'https://shanfixtechnology.com/blog.php',
];
include 'includes/header.php';
?>
<link rel="stylesheet" href="<?= site_asset('blog.css') ?>">

<main class="blog-page">

    <!-- Hero -->
    <section class="blog-hero">
        <div class="container" style="position:relative; z-index:1;">
            <div class="blog-hero-tag">Insights & Updates</div>
            <h1>The Shanfix <span>Blog</span></h1>
            <p>Technology insights, project updates, and industry news from our team in Nairobi.</p>
        </div>
    </section>

    <!-- Category Filter -->
    <?php if (!empty($categories)): ?>
    <div class="blog-filter-bar">
        <div class="blog-filter-inner">
            <button class="blog-filter-btn <?= !$cat ? 'active' : '' ?>" onclick="window.location='blog.php'">All</button>
            <?php foreach ($categories as $c): ?>
            <button class="blog-filter-btn <?= $cat === $c ? 'active' : '' ?>" onclick="window.location='blog.php?category=<?= urlencode($c) ?>'">
                <?= htmlspecialchars($c) ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Posts Grid -->
    <div class="blog-container">
        <?php if (empty($posts)): ?>
        <div class="blog-grid">
            <div class="blog-empty">
                <i class="fas fa-newspaper"></i>
                <h3 style="color:#334155; margin-bottom:8px;">No posts yet</h3>
                <p>Check back soon — our team is working on some great content.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="blog-grid">
            <?php foreach ($posts as $i => $post):
                $isFeatured = ($i === 0 && $page === 1 && !$cat);
                $url = 'post.php?slug=' . urlencode($post['slug']);
                $dateStr = $post['published_at'] ? date('d M Y', strtotime($post['published_at'])) : '';
                $initial = strtoupper(substr($post['author_name'] ?? 'S', 0, 1));
            ?>
            <a href="<?= $url ?>" class="blog-card<?= $isFeatured ? ' featured' : '' ?>">
                <?php if (!empty($post['featured_image'])): ?>
                    <img src="<?= htmlspecialchars($post['featured_image']) ?>" alt="<?= htmlspecialchars($post['title']) ?>" class="blog-card-image" loading="lazy">
                <?php else: ?>
                    <div class="blog-card-image-placeholder"><i class="fas fa-newspaper"></i></div>
                <?php endif; ?>
                <div class="blog-card-body">
                    <span class="blog-card-category"><?= htmlspecialchars($post['category'] ?? 'News') ?></span>
                    <h2 class="blog-card-title"><?= htmlspecialchars($post['title']) ?></h2>
                    <?php if (!empty($post['excerpt'])): ?>
                    <p class="blog-card-excerpt"><?= htmlspecialchars($post['excerpt']) ?></p>
                    <?php endif; ?>
                    <div class="blog-card-meta">
                        <div class="blog-card-meta-author">
                            <div class="blog-card-avatar"><?= $initial ?></div>
                            <span><?= htmlspecialchars($post['author_name'] ?? 'Shanfix Team') ?></span>
                        </div>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <span><?= $dateStr ?></span>
                            <span class="blog-card-read-more">Read <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i></span>
                        </div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="blog-pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="blog.php?page=<?= $p ?><?= $cat ? '&category=' . urlencode($cat) : '' ?>"
               class="blog-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

</main>

<?php include 'includes/footer.php'; ?>
