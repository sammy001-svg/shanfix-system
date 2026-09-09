<?php
require_once 'includes/db_connect.php';

// Pull live testimonials from DB; fall back to one static entry
$testimonials = [];
try {
    $t = $pdo->query("SELECT * FROM testimonials WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 6");
    $testimonials = $t->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (empty($testimonials)) {
    $testimonials = [
        ['quote' => 'Shanfix didn\'t just build our platform — they completely revolutionised our digital business model. Their engineering depth and premium aesthetic are unmatched.', 'author' => 'Sarah Jenkins', 'company' => 'Global Retail Enterprises', 'role' => 'CTO', 'rating' => 5],
        ['quote' => 'Professional, fast, and highly responsive. The team delivered our school management system on time and within budget. Highly recommend.', 'author' => 'David Omondi', 'company' => 'Nairobi Academy', 'role' => 'Principal', 'rating' => 5],
        ['quote' => 'Our POS system from Shanfix has transformed how we run all our branches. Real-time sync across locations is a game changer.', 'author' => 'Alice Wambui', 'company' => 'Wambui Supermarkets', 'role' => 'Operations Manager', 'rating' => 5],
    ];
}

// Team members
$team = [
    ['name' => 'Samuel Opiyo',    'role' => 'Founder & CEO',              'bio' => 'Visionary technologist with 7+ years building digital solutions for businesses across East Africa.', 'initials' => 'SO', 'color' => '#22c55e'],
    ['name' => 'Faith Njeri',     'role' => 'Head of Web Development',    'bio' => 'Full-stack developer specialising in React, PHP and cloud infrastructure. Passionate about clean code.', 'initials' => 'FN', 'color' => '#16a34a'],
    ['name' => 'Kevin Mutua',     'role' => 'Lead Software Engineer',     'bio' => 'Architect of our flagship POS and ERP products. Loves solving complex business logic challenges.', 'initials' => 'KM', 'color' => '#15803d'],
    ['name' => 'Brenda Achieng',  'role' => 'UI/UX & Graphics Designer',  'bio' => 'Creates immersive brand identities and user experiences that turn visitors into loyal customers.', 'initials' => 'BA', 'color' => '#ef4444'],
    ['name' => 'James Kariuki',   'role' => 'Digital Marketing Manager',  'bio' => 'Data-driven marketer driving measurable growth through SEO, social, and targeted campaigns.', 'initials' => 'JK', 'color' => '#dc2626'],
    ['name' => 'Grace Otieno',    'role' => 'Client Success Manager',     'bio' => 'Dedicated to ensuring every client gets maximum value from their Shanfix solutions, 24/7.', 'initials' => 'GO', 'color' => '#b91c1c'],
];

// Company milestones
$milestones = [
    ['year' => '2019', 'title' => 'Founded',                  'desc' => 'Shanfix Technology was founded in Nairobi with a mission to make enterprise-grade technology accessible to every business.'],
    ['year' => '2020', 'title' => 'Digital Marketing Launch', 'desc' => 'Expanded service portfolio to include SEO, social media, and bulk SMS marketing, growing the client base across Kenya.'],
    ['year' => '2021', 'title' => '200+ Clients Milestone',   'desc' => 'Crossed 200 active clients and launched structured hosting and domain services, becoming a one-stop digital shop.'],
    ['year' => '2022', 'title' => 'POS & ERP Products',       'desc' => 'Released proprietary Point of Sale and ERP systems, now powering retail, hospitality, and manufacturing businesses.'],
    ['year' => '2023', 'title' => 'Education Technology',     'desc' => 'Launched the School Management System — an end-to-end academic administration platform adopted by dozens of institutions.'],
    ['year' => '2024', 'title' => 'Event Ticketing Platform', 'desc' => 'Introduced a seamless digital ticketing solution for concerts, conferences, and corporate events across East Africa.'],
    ['year' => '2025', 'title' => 'AI & Automation',          'desc' => 'Integrated AI coding assistants and workflow automation tools, helping clients reduce manual processes by up to 60%.'],
    ['year' => '2026', 'title' => 'Growing Stronger',         'desc' => 'Over 1,000 projects completed. Expanding into West Africa and building the next generation of cloud-based enterprise tools.'],
];

$pageSEO = [
    'title'       => 'About Us | Shanfix Technology - IT Company in Nairobi, Kenya',
    'description' => 'Learn about Shanfix Technology — a premier IT company in Nairobi, Kenya, dedicated to delivering innovative technology solutions and digital services.',
    'keywords'    => 'about Shanfix Technology, IT company Nairobi, technology company Kenya',
    'canonical'   => '{{base}}/who-we-are.php',
];
include 'includes/header.php';
?>
<link rel="stylesheet" href="<?= site_asset('who-we-are-modern.css') ?>">

<main class="page-modern-who">

    <!-- ── Hero ──────────────────────────────────────────────────────── -->
    <section class="modern-who-hero">
        <div class="modern-who-hero-content">
            <span class="who-hero-tag" data-aos="fade-down">Est. 2019 · Nairobi, Kenya</span>
            <h1 class="modern-who-hero-title" data-aos="fade-up">Our Story</h1>
            <p class="modern-who-hero-subtitle" data-aos="fade-up" data-aos-delay="100">
                Pioneering digital transformation and crafting innovative technology solutions since 2019.
            </p>
            <div class="who-hero-actions" data-aos="fade-up" data-aos-delay="200">
                <a href="#team" class="btn btn-primary">Meet the Team</a>
                <a href="contact.php" class="btn btn-secondary">Work With Us</a>
            </div>
        </div>
        <!-- Scroll hint -->
        <div class="who-scroll-hint">
            <div class="who-scroll-line"></div>
        </div>
    </section>

    <!-- ── Story ─────────────────────────────────────────────────────── -->
    <section class="story-section">
        <div class="container">
            <div class="story-grid">
                <div class="story-text-content" data-aos="fade-right">
                    <span class="section-eyebrow">About Shanfix</span>
                    <h2>Driven by <span class="highlight">Innovation</span></h2>
                    <p>
                        Our solutions reflect a deep understanding of real-world business challenges, thanks to our relentless utilisation of cutting-edge technology. What started as a vision to nurture digital companies has grown into a powerhouse IT agency trusted across East Africa.
                    </p>
                    <p>
                        We have successfully completed over 1,000 projects across the Kenyan market and globally. At Shanfix, we are built on core values that continuously drive us towards excellence — resulting in uncompromising quality and outstanding performance.
                    </p>
                    <div class="story-badges">
                        <div class="story-badge"><i class="fas fa-check-circle"></i> ISO-Quality Processes</div>
                        <div class="story-badge"><i class="fas fa-check-circle"></i> On-Time Delivery</div>
                        <div class="story-badge"><i class="fas fa-check-circle"></i> 24/7 Support</div>
                    </div>
                </div>
                <div class="story-image-wrapper" data-aos="fade-left">
                    <img src="assets/team_collaboration.png" alt="Team Collaboration at Shanfix">
                </div>
            </div>
        </div>
    </section>

    <!-- ── Mission & Vision ──────────────────────────────────────────── -->
    <section class="mv-section">
        <div class="container">
            <div class="mv-grid">
                <div class="mv-card mv-mission" data-aos="fade-right">
                    <div class="mv-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="28" height="28">
                            <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>
                        </svg>
                    </div>
                    <h3>Our Mission</h3>
                    <p>To deliver innovative, accessible technology solutions that empower businesses — from startups to enterprises — to thrive, compete, and grow in the digital age.</p>
                </div>
                <div class="mv-card mv-vision" data-aos="fade-left">
                    <div class="mv-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="28" height="28">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </div>
                    <h3>Our Vision</h3>
                    <p>To become East Africa's most trusted technology partner — transforming businesses one digital solution at a time, and building a continent powered by homegrown innovation.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Impact Metrics ────────────────────────────────────────────── -->
    <section class="impact-section">
        <div class="container">
            <div class="impact-grid">
                <div class="impact-card" data-aos="fade-up">
                    <div class="impact-value">1k+</div>
                    <div class="impact-label">Projects Completed</div>
                </div>
                <div class="impact-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="impact-value">5+</div>
                    <div class="impact-label">Years Experience</div>
                </div>
                <div class="impact-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="impact-value">98%</div>
                    <div class="impact-label">Client Satisfaction</div>
                </div>
                <div class="impact-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="impact-value">24/7</div>
                    <div class="impact-label">Support</div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Company Timeline ───────────────────────────────────────────── -->
    <section class="timeline-section">
        <div class="container">
            <div class="section-header-centered" data-aos="fade-up">
                <span class="section-eyebrow">Our Journey</span>
                <h2>From Idea to <span class="highlight">Impact</span></h2>
                <p>Every milestone on our road to becoming East Africa's premier digital agency.</p>
            </div>
            <div class="timeline">
                <?php foreach ($milestones as $i => $m): ?>
                <div class="timeline-item <?= $i % 2 === 0 ? 'timeline-left' : 'timeline-right' ?>" data-aos="<?= $i % 2 === 0 ? 'fade-right' : 'fade-left' ?>">
                    <div class="timeline-card">
                        <div class="timeline-year"><?= $m['year'] ?></div>
                        <h4 class="timeline-title"><?= htmlspecialchars($m['title']) ?></h4>
                        <p class="timeline-desc"><?= htmlspecialchars($m['desc']) ?></p>
                    </div>
                    <div class="timeline-node"></div>
                </div>
                <?php endforeach; ?>
                <div class="timeline-line"></div>
            </div>
        </div>
    </section>

    <!-- ── Philosophy & Core Values ──────────────────────────────────── -->
    <section class="bento-section">
        <div class="container">
            <div class="bento-header" data-aos="fade-up">
                <span class="section-eyebrow">What We Stand For</span>
                <h2>Our Philosophy & Core Values</h2>
                <p>What makes Shanfix Technology your premier digital partner.</p>
            </div>
            <div class="bento-grid">
                <div class="bento-card bento-item-large" data-aos="fade-up">
                    <div class="bento-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12,15A3,3 0 0,1 9,12A3,3 0 0,1 12,9A3,3 0 0,1 15,12A3,3 0 0,1 12,15M12,2C6.47,2 2,6.47 2,12C2,17.53 6.47,22 12,22C17.53,22 22,17.53 22,12C22,6.47 17.53,2 12,2M12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20Z"/></svg>
                    </div>
                    <h3 class="bento-title">Digital Assurance</h3>
                    <p class="bento-text">We deliver award-winning digital solutions on time and within budget — robust applications, exemplary websites, and results-driven strategies that consistently exceed expectations.</p>
                </div>
                <div class="bento-card bento-item-tall" data-aos="fade-up" data-aos-delay="100">
                    <div class="bento-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19,22H5V20H19V22M17,10C17,13.27 15.34,16.14 12.83,17.8L12,18.33L11.17,17.8C8.66,16.14 7,13.27 7,10A5,5 0 0,1 12,5A5,5 0 0,1 17,10M15,10A3,3 0 0,0 12,7A3,3 0 0,0 9,10A3,3 0 0,0 12,13A3,3 0 0,0 15,10Z"/></svg>
                    </div>
                    <h3 class="bento-title">Our Belief</h3>
                    <p class="bento-text">Individual commitment fuels teamwork, which fuels company growth. We use the latest, high-quality technologies to deliver unique and mesmerising digital experiences that set our clients apart.</p>
                </div>
                <div class="bento-card bento-item-standard" data-aos="fade-up" data-aos-delay="200">
                    <div class="bento-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,11A1,1 0 0,1 13,12A1,1 0 0,1 12,13A1,1 0 0,1 11,12A1,1 0 0,1 12,11M11,18H13V16H11V18M11,10H13V6H11V10Z"/></svg>
                    </div>
                    <h3 class="bento-title">Quality Engineering</h3>
                    <p class="bento-text">Our experience in quality engineering enables us to impact clients' businesses remarkably — blending precise planning with deep market research.</p>
                </div>
                <div class="bento-card bento-item-standard" data-aos="fade-up" data-aos-delay="300">
                    <div class="bento-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm4.59-12.42L10 14.17l-2.59-2.58L6 13l4 4 8-8z"/></svg>
                    </div>
                    <h3 class="bento-title">Phenomenal Relationships</h3>
                    <p class="bento-text">We preserve strict confidentiality and build enduring, trust-based partnerships with every client — their success is always our success.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Why Choose Us ─────────────────────────────────────────────── -->
    <section class="why-choose-us" id="why-us">
        <div class="why-grid container">
            <div class="why-image-wrapper" data-aos="fade-right">
                <img src="assets/why_choose_us_woman_working.png" alt="Professional working at Shanfix" class="why-image">
            </div>
            <div class="why-content" data-aos="fade-left">
                <span class="section-eyebrow">Why Shanfix</span>
                <h2 class="why-title">Your Success is <span class="highlight">Our Priority</span></h2>
                <p class="why-text">We work with change-oriented executives to help them make better decisions, convert those decisions into actions, and deliver the sustainable success they deserve.</p>
                <p class="why-text">Our technology consulting focuses on our clients' most critical issues — strategy, operations, technology, and digital transformation.</p>
                <div class="why-skills">
                    <div class="skill-item">
                        <div class="skill-meta"><span class="skill-name">Web Development</span><span class="skill-pct">90%</span></div>
                        <div class="skill-bar"><div class="skill-progress" style="width:90%"></div></div>
                    </div>
                    <div class="skill-item">
                        <div class="skill-meta"><span class="skill-name">Digital Marketing</span><span class="skill-pct">80%</span></div>
                        <div class="skill-bar"><div class="skill-progress" style="width:80%"></div></div>
                    </div>
                    <div class="skill-item">
                        <div class="skill-meta"><span class="skill-name">System Development</span><span class="skill-pct">95%</span></div>
                        <div class="skill-bar"><div class="skill-progress" style="width:95%"></div></div>
                    </div>
                    <div class="skill-item">
                        <div class="skill-meta"><span class="skill-name">IT Infrastructure</span><span class="skill-pct">85%</span></div>
                        <div class="skill-bar"><div class="skill-progress" style="width:85%"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Team Section ───────────────────────────────────────────────── -->
    <section class="team-section" id="team">
        <div class="container">
            <div class="section-header-centered" data-aos="fade-up">
                <span class="section-eyebrow">The People</span>
                <h2>Meet Our <span class="highlight">Team</span></h2>
                <p>The talented individuals who turn bold ideas into remarkable digital realities every single day.</p>
            </div>
            <div class="team-grid">
                <?php foreach ($team as $i => $member): ?>
                <div class="team-card" data-aos="fade-up" data-aos-delay="<?= ($i % 3) * 100 ?>">
                    <div class="team-avatar" style="background: linear-gradient(135deg, <?= $member['color'] ?>, <?= $member['color'] ?>bb);">
                        <span><?= htmlspecialchars($member['initials']) ?></span>
                    </div>
                    <div class="team-info">
                        <h4 class="team-name"><?= htmlspecialchars($member['name']) ?></h4>
                        <span class="team-role"><?= htmlspecialchars($member['role']) ?></span>
                        <p class="team-bio"><?= htmlspecialchars($member['bio']) ?></p>
                        <div class="team-socials">
                            <a href="#" class="team-social-link" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                            <a href="#" class="team-social-link" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ── Testimonials ───────────────────────────────────────────────── -->
    <?php if (!empty($testimonials)): ?>
    <section class="who-testimonials-section">
        <div class="container">
            <div class="section-header-centered" data-aos="fade-up">
                <span class="section-eyebrow">Client Voices</span>
                <h2>What Our Clients <span class="highlight">Say</span></h2>
                <p>Real words from real businesses we've had the privilege to serve.</p>
            </div>
            <div class="who-testimonials-grid">
                <?php foreach ($testimonials as $i => $t):
                    $stars = str_repeat('<i class="fas fa-star"></i>', (int)($t['rating'] ?? 5));
                    $initial = strtoupper(substr($t['author'], 0, 1));
                ?>
                <div class="who-tcard" data-aos="fade-up" data-aos-delay="<?= ($i % 3) * 100 ?>">
                    <div class="who-tcard-stars"><?= $stars ?></div>
                    <p class="who-tcard-quote">"<?= htmlspecialchars($t['quote']) ?>"</p>
                    <div class="who-tcard-author">
                        <div class="who-tcard-avatar"><?= $initial ?></div>
                        <div>
                            <strong><?= htmlspecialchars($t['author']) ?></strong>
                            <span><?= htmlspecialchars(trim(($t['role'] ?? '') . ', ' . ($t['company'] ?? ''), ', ')) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── CTA Banner ────────────────────────────────────────────────── -->
    <section class="modern-cta-banner">
        <div class="container cta-banner-content" data-aos="zoom-in">
            <h2 class="cta-banner-title">Ready to transform your business?</h2>
            <p class="cta-banner-text">Let's build something extraordinary together.</p>
            <div style="display:flex; gap:16px; justify-content:center; flex-wrap:wrap;">
                <a href="contact.php" class="btn btn-primary">Start a Project</a>
                <a href="portfolio.php" class="btn btn-secondary" style="background:transparent; color:white; border-color:rgba(255,255,255,0.5);">View Our Work</a>
            </div>
        </div>
    </section>

</main>

<?php include 'includes/footer.php'; ?>
