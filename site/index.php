<?php
/**
 * The homepage.
 *
 * Rebuilt as a corporate page: it makes one argument, in order, and asks
 * for one thing at the end. The order is deliberate — what we do, proof
 * that we have done it, the detail for whoever wants it, why us, what
 * customers say, and then the ask.
 *
 * Everything that was database-driven still is. The carousel comes from
 * `adverts`, the banners from `banners`, the testimonials from
 * `testimonials` and the articles from `blog_posts`, each with a
 * sensible fallback so the page is never empty on a fresh install.
 */
require_once 'includes/db_connect.php';

// ── The hero carousel ────────────────────────────────────────────────
$heroSlides = [];
try {
    $s = $pdo->query("SELECT * FROM adverts WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $heroSlides = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

if (empty($heroSlides)) {
    $heroSlides = [
        [
            'headline'  => 'Technology that earns its keep',
            'subtitle'  => 'Software, systems and branding for businesses across Kenya — built properly, delivered on time, and supported by people you can reach.',
            'btn1_text' => 'Talk to us', 'btn1_link' => 'contact.php',
            'btn2_text' => 'What we do', 'btn2_link' => '#services',
            'bg_image'  => 'assets/hero-1.jpg', '_css_class' => 'hero-bg-1',
        ],
        [
            'headline'  => 'Systems your team will actually use',
            'subtitle'  => 'Point of sale, school management, ERP and accounting — built around how your business already works rather than the other way round.',
            'btn1_text' => 'See our systems', 'btn1_link' => 'software-solution.php',
            'btn2_text' => 'Talk to us',      'btn2_link' => 'contact.php',
            'bg_image'  => 'assets/hero-2.jpg', '_css_class' => 'hero-bg-2',
        ],
        [
            'headline'  => 'Printing and branding, done right the first time',
            'subtitle'  => 'Banners, signage, corporate profiles and apparel — produced in Nairobi, proofed before we print, delivered when we said.',
            'btn1_text' => 'Printing &amp; branding', 'btn1_link' => 'printing-branding.php',
            'btn2_text' => 'Request a quote',         'btn2_link' => 'contact.php',
            'bg_image'  => 'assets/hero-3.jpg', '_css_class' => 'hero-bg-3',
        ],
    ];
}

// ── Advertising banners ──────────────────────────────────────────────
$adBanners = [];
try {
    $b = $pdo->query("SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $adBanners = $b->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

if (empty($adBanners)) {
    $adBanners = [
        ['image_url' => 'assets/Banners-1.jpg', 'title' => 'Ring Back Tone Advertisement', 'link_url' => ''],
        ['image_url' => 'assets/Banners-2.jpg', 'title' => 'Bulk SMS Sender Advertisement', 'link_url' => ''],
    ];
}

// ── What customers say ───────────────────────────────────────────────
// This was never loaded on the homepage, so the testimonials section it
// already had could not have rendered once. Same query as who-we-are.php.
$testimonials = [];
try {
    $t = $pdo->query("SELECT * FROM testimonials WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 6");
    $testimonials = $t->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

// ── Latest articles ──────────────────────────────────────────────────
$latestPosts = [];
try {
    $lp = $pdo->query("SELECT id, title, slug, excerpt, featured_image, category, author_name, published_at
                         FROM blog_posts WHERE status='published'
                        ORDER BY published_at DESC LIMIT 3");
    $latestPosts = $lp->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* table may not exist yet */ }

/**
 * The four things we are actually hired for.
 *
 * Twelve services is the truth but it is not an answer — somebody
 * landing here wants to know in one glance whether we do their kind of
 * work. The full list is further down for whoever wants it.
 */
$pillars = [
    [
        'title' => 'Software &amp; systems',
        'text'  => 'Point of sale, ERP, school and hospital management, accounting — built for how your business runs.',
        'link'  => 'software-solution.php',
        'icon'  => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    ],
    [
        'title' => 'Web &amp; mobile',
        'text'  => 'Websites, web applications and mobile apps, with the hosting and the maintenance behind them.',
        'link'  => 'web-development.php',
        'icon'  => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
    ],
    [
        'title' => 'Printing &amp; branding',
        'text'  => 'Banners, signage, corporate profiles, apparel and vehicle branding, produced here in Nairobi.',
        'link'  => 'printing-branding.php',
        'icon'  => '<path d="M6 9V2h12v7"/><rect x="6" y="14" width="12" height="8"/><path d="M6 18H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"/>',
    ],
    [
        'title' => 'Marketing &amp; reach',
        'text'  => 'Digital marketing, SEO and bulk SMS — getting you in front of the people who buy from you.',
        'link'  => 'digital-marketing.php',
        'icon'  => '<path d="M3 11l18-8v18l-18-8v-2z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    ],
];

/** The full list, for whoever wants the detail. */
$services = [
    ['App Development',     'iOS, Android and cross-platform',      'app-development.php',     '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>'],
    ['Web Development',     'Sites and web applications',           'web-development.php',     '<rect x="2" y="4" width="20" height="14" rx="2"/><path d="M2 8h20"/>'],
    ['Web Hosting',         'Fast, monitored, supported',           'web-hosting.php',         '<rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 6.5h.01M6 17.5h.01"/>'],
    ['Software Solutions',  'POS, ERP, accounting and more',        'software-solution.php',   '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>'],
    ['Networking',          'Structured cabling and Wi-Fi',         'networking-solution.php', '<rect x="9" y="2" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="16" y="16" width="6" height="6" rx="1"/><path d="M12 8v4M5 16v-2h14v2"/>'],
    ['Digital Marketing',   'Campaigns that are measured',          'digital-marketing.php',   '<path d="M3 11l18-8v18l-18-8v-2z"/>'],
    ['Bulk SMS',            'Reach your customers directly',        'bulk-sms.php',            '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
    ['SEO Boost',           'Be found when people search',          'seo-boost.php',           '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>'],
    ['Event Management',    'Planned, staffed and run',             'event-management.php',    '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>'],
    ['Event Ticketing',     'Sell and scan at the door',            'event-ticketing.php',     '<path d="M3 9a3 3 0 0 1 0 6v3a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-3a3 3 0 0 1 0-6V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1z"/>'],
    ['Printing &amp; Branding', 'Banners, signage and apparel',     'printing-branding.php',   '<path d="M6 9V2h12v7"/><rect x="6" y="14" width="12" height="8"/>'],
    ['Consultancy',         'A second opinion worth having',        'consultancy.php',         '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>'],
];

$pageSEO = [
    'title'       => 'Shanfix Technology | IT Solutions & Digital Services in Nairobi, Kenya',
    'description' => 'Shanfix Technology offers web development, software solutions, digital marketing, networking, printing & branding, and event management in Nairobi, Kenya.',
    'keywords'    => 'IT solutions Nairobi, web development Kenya, software solutions, digital marketing, networking Nairobi, Shanfix Technology',
    'canonical'   => '{{base}}/',
];

include 'includes/header.php';

/** A stroked icon, at the size the caller asks for. */
function sx_icon(string $paths, int $size = 24): string
{
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" '
         . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true">' . $paths . '</svg>';
}

$arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
       . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
       . '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
?>

    <?php // ── Hero ───────────────────────────────────────────────────
          // The class hooks are the ones main.js drives: .hero-carousel,
          // .hero-slide, .carousel-control, .carousel-indicators. Kept
          // exactly, so autoplay and the arrows keep working. ?>
    <section class="hero" id="home">
      <div class="hero-carousel">

        <?php foreach ($heroSlides as $i => $slide):
            $bgStyle = !empty($slide['bg_image'])
                ? ' style="background-image:url(\'' . htmlspecialchars($slide['bg_image']) . '\')"' : '';
            $bgClass = !empty($slide['bg_image'])
                ? 'hero-slide-bg'
                : ('hero-slide-bg ' . ($slide['_css_class'] ?? ('hero-bg-' . ($i + 1))));
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
                  <a href="<?= htmlspecialchars($slide['btn1_link'] ?? '#') ?>" class="btn btn-primary">
                    <?= htmlspecialchars_decode(htmlspecialchars($slide['btn1_text'])) ?>
                  </a>
                <?php endif; ?>
                <?php if (!empty($slide['btn2_text'])): ?>
                  <a href="<?= htmlspecialchars($slide['btn2_link'] ?? '#') ?>" class="btn btn-secondary">
                    <?= htmlspecialchars_decode(htmlspecialchars($slide['btn2_text'])) ?>
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

        <button class="carousel-control prev" aria-label="Previous slide">
          <svg viewBox="0 0 24 24" fill="none"><path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button class="carousel-control next" aria-label="Next slide">
          <svg viewBox="0 0 24 24" fill="none"><path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>

        <div class="carousel-indicators">
          <?php foreach ($heroSlides as $i => $slide): ?>
            <button class="indicator<?= $i === 0 ? ' active' : '' ?>" data-slide="<?= $i ?>"
                    aria-label="Go to slide <?= $i + 1 ?>"></button>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <?php // ── Proof, immediately ─────────────────────────────────────
          // Directly under the hero because this is where a visitor
          // decides whether to keep reading. Facts, not adjectives. ?>
    <section class="sx-proof">
      <div class="sx-wrap">
        <div class="sx-proof-grid">
          <div class="sx-proof-item">
            <div class="sx-proof-icon"><?= sx_icon('<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>', 20) ?></div>
            <div>
              <div class="sx-proof-n">One partner</div>
              <div class="sx-proof-l">Software, print and marketing under one roof</div>
            </div>
          </div>
          <div class="sx-proof-item">
            <div class="sx-proof-icon"><?= sx_icon('<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>', 20) ?></div>
            <div>
              <div class="sx-proof-n">Nairobi</div>
              <div class="sx-proof-l">Work produced and supported locally</div>
            </div>
          </div>
          <div class="sx-proof-item">
            <div class="sx-proof-icon"><?= sx_icon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', 20) ?></div>
            <div>
              <div class="sx-proof-n">Same-day reply</div>
              <div class="sx-proof-l">To every enquiry, in working hours</div>
            </div>
          </div>
          <div class="sx-proof-item">
            <div class="sx-proof-icon"><?= sx_icon('<path d="M20 6 9 17l-5-5"/>', 20) ?></div>
            <div>
              <div class="sx-proof-n">Your own portal</div>
              <div class="sx-proof-l">Quotations, invoices and job status</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <?php // ── What we do, in four answers ────────────────────────────── ?>
    <section class="sx">
      <div class="sx-wrap">
        <div class="sx-head">
          <p class="sx-eyebrow">What we do</p>
          <h2 class="sx-h2">Four kinds of work, one company to call</h2>
          <p class="sx-lede">
            Most of our clients came for one of these and stayed for the
            rest. Everything is built and supported by the same team, so
            nobody is passed between suppliers when something needs fixing.
          </p>
        </div>

        <div class="sx-pillars">
          <?php foreach ($pillars as $p): ?>
            <a class="sx-pillar" href="<?= $p['link'] ?>">
              <div class="sx-pillar-icon"><?= sx_icon($p['icon']) ?></div>
              <h3><?= $p['title'] ?></h3>
              <p><?= $p['text'] ?></p>
              <span class="sx-pillar-more">Read more <?= $arrow ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <?php // ── The full list ──────────────────────────────────────────── ?>
    <section class="sx sx--tint" id="services">
      <div class="sx-wrap">
        <div class="sx-head sx-head--center">
          <p class="sx-eyebrow">Services</p>
          <h2 class="sx-h2">Everything we offer</h2>
          <p class="sx-lede">
            Twelve services, all delivered in-house. Pick the one you need
            and we will tell you honestly whether it is the right one.
          </p>
        </div>

        <div class="sx-services">
          <?php foreach ($services as [$name, $blurb, $link, $icon]): ?>
            <a class="sx-service" href="<?= $link ?>">
              <div class="sx-service-icon"><?= sx_icon($icon, 18) ?></div>
              <div>
                <h3><?= $name ?></h3>
                <p><?= $blurb ?></p>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <?php // ── Our own advertising ─────────────────────────────────────
          // Data-driven from `banners` and managed from the site's admin.
          // .advert-* hooks are kept: main.js runs this carousel too. ?>
    <?php if (!empty($adBanners)): ?>
    <section class="advert-carousel-section">
      <div class="container">
        <div class="advert-carousel">
          <?php foreach ($adBanners as $i => $banner):
              $wrap = !empty($banner['link_url']);
          ?>
          <div class="advert-slide<?= $i === 0 ? ' active' : '' ?>">
            <?php if ($wrap): ?><a href="<?= htmlspecialchars($banner['link_url']) ?>"><?php endif; ?>
              <img src="<?= htmlspecialchars($banner['image_url']) ?>"
                   alt="<?= htmlspecialchars($banner['title'] ?? 'Advertisement') ?>"
                   class="advert-image" loading="lazy">
            <?php if ($wrap): ?></a><?php endif; ?>
          </div>
          <?php endforeach; ?>

          <button class="advert-control prev" aria-label="Previous advertisement">
            <svg viewBox="0 0 24 24" fill="none"><path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <button class="advert-control next" aria-label="Next advertisement">
            <svg viewBox="0 0 24 24" fill="none"><path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>

          <div class="advert-indicators">
            <?php foreach ($adBanners as $i => $banner): ?>
              <button class="advert-indicator<?= $i === 0 ? ' active' : '' ?>" data-slide="<?= $i ?>"
                      aria-label="Go to advertisement <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php // ── Why us ─────────────────────────────────────────────────── ?>
    <section class="sx">
      <div class="sx-wrap">
        <div class="sx-split">
          <div>
            <p class="sx-eyebrow">Why Shanfix</p>
            <h2 class="sx-h2">The difference is in what happens after you pay</h2>
            <p class="sx-lede">
              Anybody can win the job. What matters is whether the work is
              finished, whether it keeps running, and whether somebody
              answers when you call about it a year later.
            </p>

            <ul class="sx-reasons">
              <li class="sx-reason">
                <span class="sx-reason-tick"><?= sx_icon('<path d="M20 6 9 17l-5-5"/>', 14) ?></span>
                <div>
                  <h3>One team, start to finish</h3>
                  <p>The people who build it are the people who support it. Nothing is subcontracted out and then disowned.</p>
                </div>
              </li>
              <li class="sx-reason">
                <span class="sx-reason-tick"><?= sx_icon('<path d="M20 6 9 17l-5-5"/>', 14) ?></span>
                <div>
                  <h3>You can see your own account</h3>
                  <p>Every client gets a portal with their quotations, invoices, statement and job status. No ringing to ask where something is.</p>
                </div>
              </li>
              <li class="sx-reason">
                <span class="sx-reason-tick"><?= sx_icon('<path d="M20 6 9 17l-5-5"/>', 14) ?></span>
                <div>
                  <h3>Quoted before we start</h3>
                  <p>A written quotation you approve, and a price that does not move once work begins unless you change the brief.</p>
                </div>
              </li>
              <li class="sx-reason">
                <span class="sx-reason-tick"><?= sx_icon('<path d="M20 6 9 17l-5-5"/>', 14) ?></span>
                <div>
                  <h3>We will say no</h3>
                  <p>If what you are asking for will not do what you want, we will tell you before you spend the money, not after.</p>
                </div>
              </li>
            </ul>
          </div>

          <div class="sx-shot">
            <img src="assets/team_collaboration.png" alt="The Shanfix Technology team at work" loading="lazy">
          </div>
        </div>
      </div>
    </section>

    <?php // ── What clients say ───────────────────────────────────────── ?>
    <?php if (!empty($testimonials)): ?>
    <section class="sx sx--tint">
      <div class="sx-wrap">
        <div class="sx-head sx-head--center">
          <p class="sx-eyebrow">Clients</p>
          <h2 class="sx-h2">What they say afterwards</h2>
        </div>

        <div class="sx-quotes">
          <?php foreach (array_slice($testimonials, 0, 3) as $t): ?>
            <figure class="sx-quote">
              <div class="sx-stars" aria-label="<?= (int) ($t['rating'] ?? 5) ?> out of 5">
                <?= str_repeat('&#9733;', max(1, min(5, (int) ($t['rating'] ?? 5)))) ?>
              </div>
              <blockquote><?= htmlspecialchars($t['quote'] ?? '') ?></blockquote>
              <figcaption>
                <span class="sx-quote-who"><?= htmlspecialchars($t['author'] ?? '') ?></span>
                <span class="sx-quote-where">
                  <?= htmlspecialchars(trim(($t['role'] ?? '') . (!empty($t['role']) && !empty($t['company']) ? ', ' : '') . ($t['company'] ?? ''))) ?>
                </span>
              </figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php // ── Latest articles ────────────────────────────────────────── ?>
    <?php if (!empty($latestPosts)): ?>
    <section class="sx">
      <div class="sx-wrap">
        <div class="sx-head sx-head--center">
          <p class="sx-eyebrow">Insights</p>
          <h2 class="sx-h2">From our team</h2>
        </div>

        <div class="sx-posts">
          <?php foreach ($latestPosts as $post): ?>
            <a class="sx-post" href="post.php?slug=<?= urlencode($post['slug']) ?>">
              <?php if (!empty($post['featured_image'])): ?>
                <img class="sx-post-img" src="<?= htmlspecialchars($post['featured_image']) ?>" alt="" loading="lazy">
              <?php else: ?>
                <div class="sx-post-img sx-post-img--none"></div>
              <?php endif; ?>

              <div class="sx-post-body">
                <span class="sx-post-cat"><?= htmlspecialchars($post['category'] ?? 'News') ?></span>
                <h3><?= htmlspecialchars($post['title']) ?></h3>
                <?php if (!empty($post['excerpt'])): ?>
                  <p><?= htmlspecialchars($post['excerpt']) ?></p>
                <?php endif; ?>
                <div class="sx-post-foot">
                  <span><?= htmlspecialchars($post['author_name'] ?? 'Shanfix Team') ?></span>
                  <span><?= $post['published_at'] ? date('d M Y', strtotime($post['published_at'])) : '' ?></span>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <div style="text-align:center; margin-top:38px;">
          <a class="sx-btn sx-btn--outline" href="blog.php">All articles <?= $arrow ?></a>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <?php // ── The ask ────────────────────────────────────────────────── ?>
    <section class="sx sx--navy">
      <div class="sx-wrap sx-cta">
        <p class="sx-eyebrow">Get started</p>
        <h2 class="sx-h2">Tell us what you are trying to do</h2>
        <p class="sx-lede" style="margin-left:auto; margin-right:auto;">
          Not what you think you need to buy. Describe the problem and we
          will tell you what it would take to solve it, and what it would
          cost, before you commit to anything.
        </p>

        <div class="sx-cta-row">
          <a class="sx-btn" href="contact.php">Talk to us <?= $arrow ?></a>
          <a class="sx-btn sx-btn--ghost" href="tel:<?= htmlspecialchars($_brand['phone_tel']) ?>">
            Call <?= htmlspecialchars($_brand['phone']) ?>
          </a>
        </div>

        <p class="sx-cta-note">
          Or use the chat in the corner — somebody here will answer it.
        </p>
      </div>
    </section>

<?php include 'includes/footer.php'; ?>
