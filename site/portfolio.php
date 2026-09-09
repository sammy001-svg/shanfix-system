<?php
require_once 'includes/db_connect.php';

// Load portfolio projects from DB; fall back to static items if table empty
$dbProjects = [];
try {
    $s = $pdo->query("SELECT * FROM portfolio_projects WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $dbProjects = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

$useDb = !empty($dbProjects);

$pageSEO = [
    'title'       => 'Portfolio | Our Work & Projects - Shanfix Technology',
    'description' => 'Explore Shanfix Technology\'s portfolio of completed projects — web development, software solutions, branding, and digital marketing work across Kenya.',
    'keywords'    => 'Shanfix Technology portfolio, IT projects Kenya, web development portfolio',
    'canonical'   => '{{base}}/portfolio.php',
];
include 'includes/header.php';
?>
<link rel="stylesheet" href="<?= site_asset('portfolio-modern.css') ?>">

<main class="page-modern-portfolio">
    <!-- Hero Section -->
    <section class="portfolio-hero">
        <div class="portfolio-hero-content">
            <h1 class="portfolio-hero-title" data-aos="fade-up">Our <span class="highlight">Portfolio</span></h1>
            <p class="portfolio-hero-subtitle" data-aos="fade-up" data-aos-delay="100">
                Discover how we engineer digital excellence through bold designs, robust architecture, and flawless execution for forward-thinking brands worldwide.
            </p>
        </div>
    </section>

    <!-- Featured Case Studies -->
    <section class="featured-work-section container">
        <div class="section-label" data-aos="fade-right">Featured Case Studies</div>

        <?php
        // Static fallback projects used when DB is empty
        $staticProjects = [
            ['badge'=>'E-Commerce & Retail','title'=>'Skyline E-Commerce Platform','description'=>'We architected a high-conversion, headless e-commerce solution that seamlessly handles high traffic volumes while providing an immersive, blazing-fast shopping experience.','image_url'=>null,'_static_img'=>'assets/mockup_ecommerce.png','stat1_val'=>'+140%','stat1_label'=>'Sales Conversion','stat2_val'=>'< 0.8s','stat2_label'=>'Page Load Time','live_url'=>null],
            ['badge'=>'Fintech & Analytics','title'=>'Apex Real-time Trading Dashboard','description'=>'A mission-critical financial analytics platform delivering real-time trading data with zero latency. Designed a highly customizable, dark-themed UI that reduces cognitive load for traders.','image_url'=>null,'_static_img'=>'assets/mockup_fintech.png','stat1_val'=>'10ms','stat1_label'=>'Data Latency','stat2_val'=>'100k+','stat2_label'=>'Concurrent Users','live_url'=>null],
            ['badge'=>'Mobile App Development','title'=>'Nexus Health Tracking App','description'=>'A vibrant, cross-platform mobile application gamifying personal health and wellness with secure biometric login and real-time IoT wearable synchronization.','image_url'=>null,'_static_img'=>'assets/mockup_app.png','stat1_val'=>'4.9★','stat1_label'=>'App Store Rating','stat2_val'=>'1M+','stat2_label'=>'Active Downloads','live_url'=>null],
        ];
        $projects = $useDb ? $dbProjects : $staticProjects;

        foreach ($projects as $i => $p):
            $imgSrc   = !empty($p['image_url']) ? $p['image_url'] : ($p['_static_img'] ?? '');
            $isReverse = ($i % 2 === 1) ? ' reverse' : '';
        ?>
        <article class="featured-project<?= $isReverse ?>" data-aos="fade-up">
            <div class="fp-image">
                <?php if ($imgSrc): ?>
                <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($p['title']) ?>">
                <?php else: ?>
                <div style="background:linear-gradient(135deg,#1e293b,#0f172a); height:100%; display:flex; align-items:center; justify-content:center; color:#475569; font-size:3rem;"><i class="fas fa-image"></i></div>
                <?php endif; ?>
            </div>
            <div class="fp-content">
                <?php if (!empty($p['badge'])): ?>
                <span class="fp-badge"><?= htmlspecialchars($p['badge']) ?></span>
                <?php endif; ?>
                <h2 class="fp-title"><?= htmlspecialchars($p['title']) ?></h2>
                <p class="fp-desc"><?= htmlspecialchars($p['description'] ?? '') ?></p>
                <?php if (!empty($p['stat1_val']) || !empty($p['stat2_val'])): ?>
                <div class="fp-stats-grid">
                    <?php if (!empty($p['stat1_val'])): ?>
                    <div class="fp-stat">
                        <div class="fp-stat-val"><?= htmlspecialchars($p['stat1_val']) ?></div>
                        <div class="fp-stat-label"><?= htmlspecialchars($p['stat1_label'] ?? '') ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($p['stat2_val'])): ?>
                    <div class="fp-stat">
                        <div class="fp-stat-val"><?= htmlspecialchars($p['stat2_val']) ?></div>
                        <div class="fp-stat-label"><?= htmlspecialchars($p['stat2_label'] ?? '') ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($p['live_url'])): ?>
                <a href="<?= htmlspecialchars($p['live_url']) ?>" target="_blank" rel="noopener" class="btn btn-primary">View Live Project</a>
                <?php else: ?>
                <a href="contact.php" class="btn btn-primary">Start Similar Project</a>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </section>

    <!-- Testimonial Ribbon (DB-driven) -->
    <?php
    $testimonial = null;
    try {
        $t = $pdo->query("SELECT * FROM testimonials WHERE is_active=1 ORDER BY sort_order ASC, id ASC LIMIT 1");
        $testimonial = $t->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    $testimonial = $testimonial ?: [
        'quote'   => 'Shanfix didn\'t just build our platform; they completely revolutionized our digital business model. Their premium aesthetic and engineering depth are unmatched.',
        'author'  => 'Sarah Jenkins',
        'company' => 'Global Retail Enterprises',
        'role'    => 'CTO',
        'rating'  => 5,
    ];
    $starSvg = '<svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg>';
    ?>
    <section class="testimonial-ribbon">
        <div class="container tr-content" data-aos="zoom-in">
            <div class="tr-stars">
                <?= str_repeat($starSvg, (int)($testimonial['rating'] ?? 5)) ?>
            </div>
            <blockquote class="tr-quote">"<?= htmlspecialchars($testimonial['quote']) ?>"</blockquote>
            <p class="tr-author"><?= htmlspecialchars($testimonial['author']) ?></p>
            <p class="tr-company"><?= htmlspecialchars(trim(($testimonial['role'] ?? '') . ', ' . ($testimonial['company'] ?? ''), ', ')) ?></p>
        </div>
    </section>

</main>

<?php include 'includes/footer.php'; ?>
