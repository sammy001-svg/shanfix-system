<?php
$pageSEO = [
    'title'       => 'Graphics Design Services in Nairobi | Brand Identity - Shanfix Technology',
    'description' => 'Creative graphics design services in Nairobi — logos, brand identity, social media graphics, and print design to make your business stand out.',
    'keywords'    => 'graphics design Nairobi, logo design Kenya, brand identity, creative design',
    'canonical'   => '{{base}}/graphics-design.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Graphics Design","description":"Creative logos, brand identity, social media graphics, and print design services in Nairobi.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"{{base}}/"},"areaServed":{"@type":"City","name":"Nairobi"},"url":"{{base}}/graphics-design.php"}',
];
include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= site_asset('graphics-modern.css') ?>">

<main class="graphics-modern-page">
    <!-- Hero Section -->
    <section class="gr-hero">
        <div class="container">
            <div class="gr-hero-content">
                <span class="section-tag" data-aos="fade-up">Creative Visionaries</span>
                <h1 data-aos="fade-up" data-aos-delay="100">Elevate Your <span>Brand Identity</span></h1>
                <p data-aos="fade-up" data-aos-delay="200">We blend artistic creativity with strategic thinking to craft visual identities that resonate with your audience and define your brand's future.</p>
                <div class="hero-btns" data-aos="fade-up" data-aos-delay="300">
                    <a href="contact.php" class="btn btn-primary">Start Your Design Project</a>
                    <a href="#services" class="btn btn-secondary">Explore Services</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Grid (Showcase style) -->
    <section id="services" class="gr-services-section" style="padding: 100px 0;">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">What We Do</span>
                <h2 class="section-title-modern">Design <span>Solutions</span></h2>
            </div>
            
            <div class="gr-showcase">
                <div class="showcase-card" data-aos="fade-up" data-aos-delay="100" style="background: url('assets/design-logo.png') no-repeat center center; background-size: cover;">
                    <div class="showcase-overlay">
                        <h4>Logo & Identity</h4>
                        <p>Core brand symbols & guidelines</p>
                    </div>
                </div>
                <div class="showcase-card" data-aos="fade-up" data-aos-delay="200" style="background: url('assets/design-marketing.png') no-repeat center center; background-size: cover;">
                    <div class="showcase-overlay">
                        <h4>Marketing Material</h4>
                        <p>Flyers, brochures & posters</p>
                    </div>
                </div>
                <div class="showcase-card" data-aos="fade-up" data-aos-delay="300" style="background: url('assets/design-social.png') no-repeat center center; background-size: cover;">
                    <div class="showcase-overlay">
                        <h4>Social Media Kit</h4>
                        <p>Banners, posts & story designs</p>
                    </div>
                </div>
                <div class="showcase-card" data-aos="fade-up" data-aos-delay="400" style="background: url('assets/design-uiux.png') no-repeat center center; background-size: cover;">
                    <div class="showcase-overlay">
                        <h4>UI/UX Design</h4>
                        <p>Web & Mobile app interfaces</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Grid -->
    <section id="pricing" class="pricing-section" style="background: rgba(255,255,255,0.01);">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Value Based Pricing</span>
                <h2 class="section-title-modern">Design <span>Packages</span></h2>
            </div>

            <div class="pricing-grid">
                <!-- Standard Logo -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="price-header">
                        <h3>Logo Essential</h3>
                        <div class="price-amount">Ksh 5k<span>/Starting</span></div>
                        <p class="price-desc">Perfect for startups needing a professional visual foundation.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 2 Initial Concepts</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 3 Revisions</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> High-Res JPG/PNG</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Color Variations</li>
                    </ul>
                    <a href="contact.php" class="btn btn-secondary">Order Essential</a>
                </div>

                <!-- Professional Branding -->
                <div class="price-card featured" data-aos="fade-up" data-aos-delay="200" style="border-color: var(--gr-accent); transform: scale(1.05);">
                    <div class="price-header">
                        <h3>Corporate Pro</h3>
                        <div class="price-amount">Ksh 18k<span>/Starting</span></div>
                        <p class="price-desc">Complete brand kit for businesses scaling their professional presence.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Premium Logo Design</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Business Card & Letterhead</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Social Media Branding</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Full Source Files (AI/EPS)</li>
                    </ul>
                    <a href="contact.php" class="btn btn-primary" style="background: var(--gr-accent); color: #000;">Select Corporate</a>
                </div>

                <!-- Elite Custom -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="price-header">
                        <h3>Creative Elite</h3>
                        <div class="price-amount">Custom<span>/Quote</span></div>
                        <p class="price-desc">Unlimited creative support for campaigns and high-end artistic projects.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Full Brand Strategy</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Custom Illustrations</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Motion Graphics (Animation)</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Priority Studio Support</li>
                    </ul>
                    <a href="contact.php" class="btn btn-secondary">Custom Project</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Support Section -->
    <section class="hosting-support" style="padding-bottom: 100px;">
        <div class="container">
            <div class="support-box" data-aos="zoom-in">
                <div class="support-content">
                    <span class="section-tag" style="text-align: left;">Free Consultation</span>
                    <h2 style="color: #fff;">Need a Custom <span>Design Quote?</span></h2>
                    <p>Tell us about your project. Whether it's a simple logo or a full brand overhaul, we have the creative talent to bring it to life.</p>
                    <a href="contact.php" class="btn btn-primary" style="background: var(--gr-accent); color: #000;">Consult Our Designers</a>
                </div>
                <div class="support-visual">
                    <svg width="200" height="200" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 19l7-7 3 3-7 7-3-3z" stroke="var(--gr-accent)" stroke-width="0.5"/>
                        <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z" stroke="var(--gr-accent)" stroke-width="0.5"/>
                        <path d="M2 2l7.5 1.5" stroke="var(--gr-accent)" stroke-width="0.5"/>
                        <path d="M2 2l1.5 7.5" stroke="var(--gr-accent)" stroke-width="0.5"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>
</main>

<?php $ctaService = 'Graphics Design'; include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
