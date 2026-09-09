<?php
require_once 'includes/db_connect.php';

// Load hero slides from DB; fall back to static defaults if table is empty
$heroSlides = [];
try {
    $s = $pdo->query("SELECT * FROM adverts WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $heroSlides = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

if (empty($heroSlides)) {
    $heroSlides = [
        ['headline' => 'Innovative Technology Solutions for <span class="highlight">Your Business</span>', 'subtitle' => 'From web development to event management, we provide comprehensive IT and business solutions in Nairobi, Kenya', 'btn1_text' => 'Explore Services', 'btn1_link' => '#services', 'btn2_text' => 'Get in Touch', 'btn2_link' => 'contact.php', 'bg_image' => null, '_css_class' => 'hero-bg-1'],
        ['headline' => 'Transform Your Digital Presence with <span class="highlight">Expert Solutions</span>', 'subtitle' => 'Professional web development, hosting, and digital marketing services tailored to your business needs', 'btn1_text' => 'Our Services', 'btn1_link' => '#services', 'btn2_text' => 'Learn More', 'btn2_link' => '#about', 'bg_image' => null, '_css_class' => 'hero-bg-2'],
        ['headline' => 'Empowering Businesses Through <span class="highlight">Technology</span>', 'subtitle' => 'Networking, software solutions, and IT infrastructure services to drive your business forward', 'btn1_text' => 'Contact Us', 'btn1_link' => 'contact.php', 'btn2_text' => 'View Services', 'btn2_link' => '#services', 'bg_image' => null, '_css_class' => 'hero-bg-3'],
    ];
}

// Load ad banners from DB; fall back to static defaults
$adBanners = [];
try {
    $b = $pdo->query("SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $adBanners = $b->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

$staticBanners = empty($adBanners);
if ($staticBanners) {
    $adBanners = [
        ['image_url' => 'assets/Banners-1.jpg', 'title' => 'Ring Back Tone Advertisement', 'link_url' => ''],
        ['image_url' => 'assets/Banners-2.jpg', 'title' => 'Bulk SMS Sender Advertisement', 'link_url' => ''],
    ];
}
?>
<?php
$pageSEO = [
    'title'       => 'Shanfix Technology | IT Solutions & Digital Services in Nairobi, Kenya',
    'description' => 'Shanfix Technology offers web development, software solutions, digital marketing, networking, printing & branding, and event management in Nairobi, Kenya.',
    'keywords'    => 'IT solutions Nairobi, web development Kenya, software solutions, digital marketing, networking Nairobi, Shanfix Technology',
    'canonical'   => 'https://shanfixtechnology.com/',
];
include 'includes/header.php'; ?>

    <!-- Hero Section with Carousel -->
    <section class="hero" id="home">
      <div class="hero-carousel">

        <?php foreach ($heroSlides as $i => $slide):
            $bgStyle  = !empty($slide['bg_image']) ? ' style="background-image:url(\'' . htmlspecialchars($slide['bg_image']) . '\')"' : '';
            $bgClass  = !empty($slide['bg_image']) ? 'hero-slide-bg' : ('hero-slide-bg ' . ($slide['_css_class'] ?? ('hero-bg-' . ($i + 1))));
        ?>
        <div class="hero-slide<?= $i === 0 ? ' active' : '' ?>">
          <div class="<?= $bgClass ?>"<?= $bgStyle ?>></div>
          <div class="hero-slide-overlay"></div>
          <div class="container hero-container">
            <div class="hero-content">
              <h1 class="hero-title"><?= $slide['headline'] ?></h1>
              <?php if (!empty($slide['subtitle'])): ?>
              <p class="hero-subtitle"><?= htmlspecialchars($slide['subtitle']) ?></p>
              <?php endif; ?>
              <div class="hero-buttons">
                <?php if (!empty($slide['btn1_text'])): ?>
                <a href="<?= htmlspecialchars($slide['btn1_link'] ?? '#') ?>" class="btn btn-primary"><?= htmlspecialchars($slide['btn1_text']) ?></a>
                <?php endif; ?>
                <?php if (!empty($slide['btn2_text'])): ?>
                <a href="<?= htmlspecialchars($slide['btn2_link'] ?? '#') ?>" class="btn btn-secondary"><?= htmlspecialchars($slide['btn2_text']) ?></a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Carousel Controls -->
        <button class="carousel-control prev" aria-label="Previous slide">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <button class="carousel-control next" aria-label="Next slide">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>

        <!-- Carousel Indicators (dynamic count) -->
        <div class="carousel-indicators">
          <?php foreach ($heroSlides as $i => $slide): ?>
          <button class="indicator<?= $i === 0 ? ' active' : '' ?>" data-slide="<?= $i ?>" aria-label="Go to slide <?= $i + 1 ?>"></button>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- Feature Cards Section -->
    <section class="feature-cards-section">
      <div class="container">
        <div class="feature-cards-grid">
          <!-- Web Development Card -->
          <div class="feature-card">
            <div class="feature-card-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="16 18 22 12 16 6"></polyline>
                <polyline points="8 6 2 12 8 18"></polyline>
              </svg>
            </div>
            <h3 class="feature-card-title">Web Development</h3>
            <p class="feature-card-description">
              Custom websites and web applications built with modern technologies to help your business thrive online.
            </p>
            <a href="web-development.php" class="feature-card-link">
              Learn More
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
            </a>
          </div>

          <!-- Graphics Design Card -->
          <div class="feature-card">
            <div class="feature-card-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 19l7-7 3 3-7 7-3-3z"></path>
                <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path>
                <path d="M2 2l7.586 7.586"></path>
                <circle cx="11" cy="11" r="2"></circle>
              </svg>
            </div>
            <h3 class="feature-card-title">Graphics Design</h3>
            <p class="feature-card-description">
              Creative and professional graphic design services to elevate your brand identity and visual communication.
            </p>
            <a href="printing-branding.php" class="feature-card-link">
              Learn More
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
            </a>
          </div>

          <!-- Printing & Branding Card -->
          <div class="feature-card">
            <div class="feature-card-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 6 2 18 2 18 9"></polyline>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                <rect x="6" y="14" width="12" height="8"></rect>
              </svg>
            </div>
            <h3 class="feature-card-title">Printing & Branding</h3>
            <p class="feature-card-description">
              High-quality printing services and comprehensive branding solutions to make your business stand out.
            </p>
            <a href="printing-branding.php" class="feature-card-link">
              Learn More
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
            </a>
          </div>
        </div>
      </div>
    </section>




    <!-- Advertisement Carousel Section -->
    <section class="advert-carousel-section">
      <div class="container">
        <div class="advert-carousel">

          <?php foreach ($adBanners as $i => $banner):
              $imgSrc = htmlspecialchars($banner['image_url']);
              $imgAlt = htmlspecialchars($banner['title'] ?? 'Advertisement');
              $wrap   = !empty($banner['link_url']);
          ?>
          <div class="advert-slide<?= $i === 0 ? ' active' : '' ?>">
            <?php if ($wrap): ?><a href="<?= htmlspecialchars($banner['link_url']) ?>"><?php endif; ?>
            <img src="<?= $imgSrc ?>" alt="<?= $imgAlt ?>" class="advert-image">
            <?php if ($wrap): ?></a><?php endif; ?>
          </div>
          <?php endforeach; ?>

          <button class="advert-control prev" aria-label="Previous advertisement">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
          <button class="advert-control next" aria-label="Next advertisement">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>

          <div class="advert-indicators">
            <?php foreach ($adBanners as $i => $banner): ?>
            <button class="advert-indicator<?= $i === 0 ? ' active' : '' ?>" data-slide="<?= $i ?>" aria-label="Go to advertisement <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- Services Section -->
    <section class="services" id="services">
      <div class="container">
        <div class="section-header">
          <h2 class="section-title">Our Services</h2>
          <p class="section-subtitle">
            Comprehensive solutions tailored to your business needs
          </p>
        </div>
        <div class="services-grid">
          <!-- App Development -->
          <div class="service-card" data-aos="fade-up">
            <div class="service-image-container">
              <img src="assets/App-development-jpeg.jpg" alt="App Development" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">App Development</h3>
              <p class="service-description">
                Custom mobile applications for iOS and Android
              </p>
              <a href="app-development.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Web Development -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="100">
            <div class="service-image-container">
              <img src="assets/web-development-jpeg.jpg" alt="Web Development" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Web Development</h3>
              <p class="service-description">
                Modern, responsive websites that drive results
              </p>
              <a href="web-development.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Web Hosting -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="200">
            <div class="service-image-container">
              <img src="assets/web-hosting-jpeg.jpg" alt="Web Hosting" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Web Hosting</h3>
              <p class="service-description">
                Reliable and secure hosting solutions for your business
              </p>
              <a href="web-hosting.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Software Solution -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="300">
            <div class="service-image-container">
              <img src="assets/Software-solution-jpeg.jpg" alt="Software Solution" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Software Solution</h3>
              <p class="service-description">
                Tailored software to streamline your operations
              </p>
              <a href="software-solution.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Networking Solution -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="400">
            <div class="service-image-container">
              <img src="assets/networking-solution-jpeg.jpg" alt="Networking Solution" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Networking Solution</h3>
              <p class="service-description">
                Robust network infrastructure setup and maintenance
              </p>
              <a href="networking-solution.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Digital Marketing -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="500">
            <div class="service-image-container">
              <img src="assets/digital-marketing-jpeg.jpg" alt="Digital Marketing" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Digital Marketing</h3>
              <p class="service-description">
                Strategies to grow your brand's online presence
              </p>
              <a href="digital-marketing.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Bulk SMS -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="600">
            <div class="service-image-container">
              <img src="assets/Bulk-sms-jpeg.jpg" alt="Bulk SMS" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Bulk SMS</h3>
              <p class="service-description">
                Effective mass communication tools for outreach
              </p>
              <a href="bulk-sms.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- SEO Boost -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="700">
            <div class="service-image-container">
              <img src="assets/SEO-boost-jpeg.jpg" alt="SEO Boost" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">SEO Boost</h3>
              <p class="service-description">
                Optimize your site to rank higher on search engines
              </p>
              <a href="seo-boost.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Event management -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="800">
            <div class="service-image-container">
              <img src="assets/event-management-jpeg.jpg" alt="Event Management" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Event Management</h3>
              <p class="service-description">
                Professional planning for memorable corporate events
              </p>
              <a href="event-management.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Event ticketing -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="900">
            <div class="service-image-container">
              <img src="assets/event-ticketing-jpeg.jpg" alt="Event Ticketing" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Event Ticketing</h3>
              <p class="service-description">
                Seamless ticketing solutions for your events
              </p>
              <a href="event-ticketing.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Printing & branding -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="1000">
            <div class="service-image-container">
              <img src="assets/printing-brabdibg-jpeg.jpg" alt="Printing & Branding" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Printing & Branding</h3>
              <p class="service-description">
                High-quality print materials and brand assets
              </p>
              <a href="printing-branding.php" class="service-btn">Get Started</a>
            </div>
          </div>

          <!-- Consultancy -->
          <div class="service-card" data-aos="fade-up" data-aos-delay="1100">
            <div class="service-image-container">
              <img src="assets/service-4.jpg" alt="Consultancy" class="service-image" />
            </div>
            <div class="service-content">
              <h3 class="service-title">Consultancy</h3>
              <p class="service-description">
                Expert advice to guide your technology decisions
              </p>
              <a href="consultancy.php" class="service-btn">Get Started</a>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── Latest Blog Posts ─────────────────────────────── -->
    <?php
    $latestPosts = [];
    try {
        $lp = $pdo->query("SELECT id, title, slug, excerpt, featured_image, category, author_name, published_at FROM blog_posts WHERE status='published' ORDER BY published_at DESC LIMIT 3");
        $latestPosts = $lp->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    ?>
    <?php if (!empty($latestPosts)): ?>
    <section style="padding: 80px 0; background: #f8fafc;">
        <div class="container">
            <div style="text-align:center; margin-bottom:48px;">
                <span style="display:inline-block; background:rgba(99,102,241,0.08); color:#6366f1; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:2px; padding:6px 16px; border-radius:20px; margin-bottom:16px;">Latest Insights</span>
                <h2 style="font-size:clamp(1.8rem,3.5vw,2.8rem); font-weight:800; color:#1e293b; margin:0 0 12px;">From the <span style="color:#6366f1;">Shanfix Blog</span></h2>
                <p style="color:#64748b; font-size:1rem; max-width:480px; margin:0 auto;">Technology tips, project stories, and industry news from our team.</p>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:28px; margin-bottom:48px;">
                <?php foreach ($latestPosts as $lpost):
                    $lDate = $lpost['published_at'] ? date('d M Y', strtotime($lpost['published_at'])) : '';
                ?>
                <a href="post.php?slug=<?= urlencode($lpost['slug']) ?>" style="background:white; border-radius:20px; overflow:hidden; border:1px solid #e2e8f0; text-decoration:none; display:flex; flex-direction:column; transition:transform 0.3s,box-shadow 0.3s;" onmouseover="this.style.transform='translateY(-6px)';this.style.boxShadow='0 20px 60px rgba(99,102,241,0.12)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                    <?php if (!empty($lpost['featured_image'])): ?>
                    <img src="<?= htmlspecialchars($lpost['featured_image']) ?>" alt="" style="width:100%; height:180px; object-fit:cover;" loading="lazy">
                    <?php else: ?>
                    <div style="width:100%; height:180px; background:linear-gradient(135deg,#e0e7ff,#ede9fe); display:flex; align-items:center; justify-content:center; color:#a5b4fc; font-size:2.5rem;"><i class="fas fa-newspaper"></i></div>
                    <?php endif; ?>
                    <div style="padding:24px; flex:1; display:flex; flex-direction:column;">
                        <span style="display:inline-block; background:rgba(99,102,241,0.08); color:#6366f1; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; padding:3px 10px; border-radius:8px; margin-bottom:10px;"><?= htmlspecialchars($lpost['category'] ?? 'News') ?></span>
                        <h3 style="font-size:1rem; font-weight:800; color:#1e293b; margin:0 0 8px; line-height:1.4;"><?= htmlspecialchars($lpost['title']) ?></h3>
                        <?php if (!empty($lpost['excerpt'])): ?>
                        <p style="color:#64748b; font-size:0.85rem; line-height:1.6; flex:1; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin:0 0 16px;"><?= htmlspecialchars($lpost['excerpt']) ?></p>
                        <?php endif; ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:#94a3b8; border-top:1px solid #f1f5f9; padding-top:14px; margin-top:auto;">
                            <span><?= htmlspecialchars($lpost['author_name'] ?? 'Shanfix Team') ?></span>
                            <span style="display:flex; align-items:center; gap:5px; color:#6366f1; font-weight:700;"><?= $lDate ?> <i class="fas fa-arrow-right" style="font-size:0.6rem;"></i></span>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <div style="text-align:center;">
                <a href="blog.php" style="display:inline-flex; align-items:center; gap:10px; background:linear-gradient(135deg,#6366f1,#4f46e5); color:white; text-decoration:none; font-weight:700; padding:14px 36px; border-radius:50px; box-shadow:0 8px 30px rgba(99,102,241,0.35); transition:all 0.3s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform=''">
                    View All Articles <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($testimonials)): ?>
    <!-- Testimonials Section -->
    <section class="testimonials-section" style="padding:80px 0; background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%); overflow:hidden;">
        <div class="container">
            <div class="section-header" data-aos="fade-up" style="margin-bottom:48px;">
                <h2 class="section-title" style="color:#fff;">What Our <span class="highlight">Clients Say</span></h2>
                <p class="section-subtitle" style="color:rgba(255,255,255,0.65);">Trusted by businesses and organisations across Kenya</p>
            </div>
            <div class="testimonials-track-wrapper" style="position:relative; overflow:hidden;">
                <div class="testimonials-track" id="testimonialsTrack" style="display:flex; gap:28px; transition:transform 0.5s cubic-bezier(0.4,0,0.2,1); will-change:transform;">
                    <?php foreach ($testimonials as $t): ?>
                    <div class="testimonial-card" style="flex:0 0 calc(33.333% - 19px); min-width:0; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:20px; padding:32px; backdrop-filter:blur(12px); display:flex; flex-direction:column; gap:16px;">
                        <!-- Stars -->
                        <div style="display:flex; gap:4px;">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="<?= $s <= (int)($t['rating'] ?? 5) ? '#f59e0b' : 'rgba(255,255,255,0.2)' ?>"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <?php endfor; ?>
                        </div>
                        <!-- Quote -->
                        <p style="color:rgba(255,255,255,0.85); line-height:1.75; font-size:0.95rem; flex:1; margin:0;">"<?= htmlspecialchars($t['quote']) ?>"</p>
                        <!-- Author -->
                        <div style="display:flex; align-items:center; gap:14px; border-top:1px solid rgba(255,255,255,0.08); padding-top:16px;">
                            <div style="width:44px; height:44px; border-radius:50%; background:linear-gradient(135deg,#22c55e,#16a34a); display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; font-size:1rem; flex-shrink:0;">
                                <?= strtoupper(substr($t['author'], 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-weight:700; color:#fff; font-size:0.9rem;"><?= htmlspecialchars($t['author']) ?></div>
                                <div style="font-size:0.78rem; color:rgba(255,255,255,0.5);"><?= htmlspecialchars(trim(($t['role'] ?? '') . ($t['company'] ? ' · ' . $t['company'] : ''), ' ·')) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (count($testimonials) > 3): ?>
            <div style="display:flex; justify-content:center; gap:12px; margin-top:32px;" id="testimDots">
                <?php $pages = ceil(count($testimonials) / 3); for ($p = 0; $p < $pages; $p++): ?>
                <button onclick="testimGoTo(<?= $p ?>)" style="width:<?= $p === 0 ? '28px' : '8px' ?>; height:8px; border-radius:4px; border:none; background:<?= $p === 0 ? '#22c55e' : 'rgba(255,255,255,0.2)' ?>; cursor:pointer; transition:all 0.3s; padding:0;" id="testimDot<?= $p ?>"></button>
                <?php endfor; ?>
            </div>
            <script>
            var _tIdx=0, _tCards=<?= count($testimonials) ?>, _tPerPage=window.innerWidth<768?1:window.innerWidth<1024?2:3;
            function testimGoTo(i){
                _tIdx=i;
                var w=document.querySelector('.testimonial-card')?.offsetWidth||300;
                var gap=28;
                document.getElementById('testimonialsTrack').style.transform='translateX(-'+(i*_tPerPage*(w+gap))+'px)';
                document.querySelectorAll('[id^=testimDot]').forEach(function(d,j){
                    d.style.width=j===i?'28px':'8px';
                    d.style.background=j===i?'#22c55e':'rgba(255,255,255,0.2)';
                });
            }
            setInterval(function(){testimGoTo((_tIdx+1)%Math.ceil(_tCards/_tPerPage));},5000);
            window.addEventListener('resize',function(){_tPerPage=window.innerWidth<768?1:window.innerWidth<1024?2:3;});
            </script>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>



