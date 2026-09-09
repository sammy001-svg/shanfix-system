<?php
require_once 'includes/db_connect.php';
require_once 'includes/brand.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: blog.php'); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published'");
    $stmt->execute([$slug]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $post = null; }

if (!$post) {
    header('HTTP/1.0 404 Not Found');
    include 'includes/header.php';
    echo '<div style="text-align:center; padding:120px 20px;"><h1 style="color:#1e293b;">Post Not Found</h1><p><a href="blog.php" style="color:#6366f1;">← Back to Blog</a></p></div>';
    include 'includes/footer.php';
    exit;
}

// Increment view count (fire-and-forget)
try {
    $pdo->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?")->execute([$post['id']]);
} catch (Exception $e) {}

// Fetch 3 related posts
try {
    $relStmt = $pdo->prepare("SELECT id, title, slug, excerpt, featured_image, category, author_name, published_at FROM blog_posts WHERE status='published' AND id != ? AND category = ? ORDER BY published_at DESC LIMIT 3");
    $relStmt->execute([$post['id'], $post['category']]);
    $related = $relStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($related) < 3) {
        $moreStmt = $pdo->prepare("SELECT id, title, slug, excerpt, featured_image, category, author_name, published_at FROM blog_posts WHERE status='published' AND id != ? AND id NOT IN (" . implode(',', array_column($related, 'id') ?: [0]) . ") ORDER BY published_at DESC LIMIT ?");
        $moreStmt->execute([$post['id'], 3 - count($related)]);
        $related = array_merge($related, $moreStmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) { $related = []; }

$dateStr   = $post['published_at'] ? date('d F Y', strtotime($post['published_at'])) : '';
$readTime  = max(1, (int)ceil(str_word_count(strip_tags($post['content'])) / 200));
$initial   = strtoupper(substr($post['author_name'] ?? 'S', 0, 1));

$_postUrl    = site_url('post.php?slug=' . urlencode($post['slug']));
$_postImage  = !empty($post['featured_image']) ? $post['featured_image'] : site_url('assets/og-image.png');
$_postExcerpt = !empty($post['excerpt']) ? $post['excerpt'] : strip_tags(mb_substr($post['content'], 0, 160));
$_published  = $post['published_at'] ? date('c', strtotime($post['published_at'])) : '';

$pageSEO = [
    'title'       => $post['title'] . ' | Shanfix Technology Blog',
    'description' => $_postExcerpt,
    'keywords'    => ($post['category'] ?? '') . ', tech blog Kenya, IT insights Nairobi, Shanfix Technology',
    'canonical'   => $_postUrl,
    'og_type'     => 'article',
    'og_image'    => $_postImage,
    'json_ld'     => json_encode([
        '@context'         => 'https://schema.org',
        '@type'            => 'BlogPosting',
        'headline'         => $post['title'],
        'description'      => $_postExcerpt,
        'image'            => $_postImage,
        'url'              => $_postUrl,
        'datePublished'    => $_published,
        'dateModified'     => $_published,
        'author'           => [
            '@type' => 'Person',
            'name'  => $post['author_name'] ?? 'Shanfix Technology',
        ],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => 'Shanfix Technology',
            'logo'  => ['@type' => 'ImageObject', 'url' => site_url('assets/shanfix-logo.png')],
        ],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $_postUrl],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
];
include 'includes/header.php';
?>
<link rel="stylesheet" href="<?= site_asset('blog.css') ?>">
<style>
    /* Override header title for this page */
    head title { font-size: 0; }
</style>

<main class="post-page">

    <!-- Post Header -->
    <section class="post-header">
        <div class="post-header-inner">
            <div class="post-breadcrumb">
                <a href="index.php">Home</a>
                <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
                <a href="blog.php">Blog</a>
                <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
                <span><?= htmlspecialchars($post['category'] ?? 'Article') ?></span>
            </div>
            <span class="post-category-tag"><?= htmlspecialchars($post['category'] ?? 'News') ?></span>
            <h1 class="post-title"><?= htmlspecialchars($post['title']) ?></h1>
            <div class="post-meta">
                <div class="post-meta-item">
                    <i class="fas fa-user-circle"></i>
                    <span><?= htmlspecialchars($post['author_name'] ?? 'Shanfix Team') ?></span>
                </div>
                <?php if ($dateStr): ?>
                <div class="post-meta-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span><?= $dateStr ?></span>
                </div>
                <?php endif; ?>
                <div class="post-meta-item">
                    <i class="fas fa-clock"></i>
                    <span><?= $readTime ?> min read</span>
                </div>
                <div class="post-meta-item">
                    <i class="fas fa-eye"></i>
                    <span><?= number_format((int)$post['views']) ?> views</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Featured Image -->
    <?php if (!empty($post['featured_image'])): ?>
    <div class="post-featured-img-wrap">
        <img src="<?= htmlspecialchars($post['featured_image']) ?>" alt="<?= htmlspecialchars($post['title']) ?>">
    </div>
    <?php else: ?>
    <div style="height:30px;"></div>
    <?php endif; ?>

    <!-- Article Content -->
    <div class="post-body">
        <?php if (!empty($post['excerpt'])): ?>
        <p style="font-size:1.15rem; color:#475569; line-height:1.8; margin-bottom:2rem; padding-bottom:2rem; border-bottom:1px solid #e2e8f0; font-style:italic;">
            <?= htmlspecialchars($post['excerpt']) ?>
        </p>
        <?php endif; ?>
        <div class="post-content">
            <?= $post['content'] /* Already sanitised on save; admin-only input */ ?>
        </div>
    </div>

    <!-- Bottom CTA bar -->
    <div class="post-cta-bar">
        <a href="blog.php" style="display:inline-flex; align-items:center; gap:8px; color:#6366f1; font-weight:700; text-decoration:none; font-size:0.9rem;">
            <i class="fas fa-arrow-left"></i> Back to Blog
        </a>
        <div style="display:flex; gap:10px; align-items:center;">
            <span style="color:#64748b; font-size:0.82rem; font-weight:600;">Share:</span>
            <a href="https://twitter.com/intent/tweet?url=<?= urlencode((isset($_ENV['APP_URL']) ? $_ENV['APP_URL'] : '') . '/post.php?slug=' . $post['slug']) ?>&text=<?= urlencode($post['title']) ?>"
               target="_blank" rel="noopener"
               style="width:34px; height:34px; border-radius:50%; background:#e7f3ff; display:flex; align-items:center; justify-content:center; color:#1d9bf0; text-decoration:none;">
               <i class="fab fa-twitter"></i>
            </a>
            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode((isset($_ENV['APP_URL']) ? $_ENV['APP_URL'] : '') . '/post.php?slug=' . $post['slug']) ?>"
               target="_blank" rel="noopener"
               style="width:34px; height:34px; border-radius:50%; background:#e8f0fe; display:flex; align-items:center; justify-content:center; color:#0077b5; text-decoration:none;">
               <i class="fab fa-linkedin-in"></i>
            </a>
            <a href="https://wa.me/?text=<?= urlencode($post['title'] . ' ' . (isset($_ENV['APP_URL']) ? $_ENV['APP_URL'] : '') . '/post.php?slug=' . $post['slug']) ?>"
               target="_blank" rel="noopener"
               style="width:34px; height:34px; border-radius:50%; background:#e6f9f1; display:flex; align-items:center; justify-content:center; color:#25d366; text-decoration:none;">
               <i class="fab fa-whatsapp"></i>
            </a>
        </div>
    </div>

    <!-- Related Posts -->
    <?php if (!empty($related)): ?>
    <div class="related-posts">
        <h3>More Articles</h3>
        <div class="related-grid">
            <?php foreach ($related as $rel):
                $relDate = $rel['published_at'] ? date('d M Y', strtotime($rel['published_at'])) : '';
            ?>
            <a href="post.php?slug=<?= urlencode($rel['slug']) ?>" class="blog-card" style="text-decoration:none;">
                <?php if (!empty($rel['featured_image'])): ?>
                    <img src="<?= htmlspecialchars($rel['featured_image']) ?>" alt="" class="blog-card-image" loading="lazy">
                <?php else: ?>
                    <div class="blog-card-image-placeholder"><i class="fas fa-newspaper"></i></div>
                <?php endif; ?>
                <div class="blog-card-body">
                    <span class="blog-card-category"><?= htmlspecialchars($rel['category'] ?? 'News') ?></span>
                    <h3 class="blog-card-title" style="font-size:1rem;"><?= htmlspecialchars($rel['title']) ?></h3>
                    <div class="blog-card-meta" style="margin-top:auto;">
                        <span><?= htmlspecialchars($rel['author_name'] ?? 'Shanfix Team') ?></span>
                        <span><?= $relDate ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

<?php include 'includes/footer.php'; ?>
