<?php
$pageSEO = [
    'title'       => '3D & 2D Signage Solutions in Nairobi | Shanfix Technology',
    'description' => 'Eye-catching 3D and 2D signage solutions in Nairobi. Shop signs, billboards, illuminated signs, and vehicle branding by Shanfix Technology Kenya.',
    'keywords'    => 'signage Nairobi, 3D signs Kenya, billboard design, shop signage, vehicle branding',
    'canonical'   => 'https://shanfixtechnology.com/signage-solution.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Signage Solutions","description":"3D and 2D signage — shop signs, billboards, illuminated signs, and vehicle branding in Nairobi.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"https://shanfixtechnology.com/"},"areaServed":{"@type":"City","name":"Nairobi"},"url":"https://shanfixtechnology.com/signage-solution.php"}',
];
include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= site_asset('signage-modern.css') ?>">

<main class="signage-modern-page">
    <!-- Hero Section -->
    <section class="sig-hero">
        <div class="container">
            <div class="sig-hero-content">
                <span class="section-tag" data-aos="fade-up">Architectural Visibility</span>
                <h1 data-aos="fade-up" data-aos-delay="100">Dimensional <span>Signage Solutions</span></h1>
                <p data-aos="fade-up" data-aos-delay="200">Make your brand stand out with high-impact 3D and 2D signage. From glowing LED channel letters to professional architectural plaques, we design for maximum visibility.</p>
                <div class="hero-btns" data-aos="fade-up" data-aos-delay="300">
                    <a href="contact.php" class="btn btn-primary">Get a Signage Quote</a>
                    <a href="#types" class="btn btn-secondary">Explore Types</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Signage Types -->
    <section id="types" class="sig-types-section" style="padding: 100px 0;">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Our Expertise</span>
                <h2 class="section-title-modern">Types of <span>Signage</span></h2>
            </div>

            <div class="sig-grid">
                <!-- 3D LED Illuminated -->
                <div class="sig-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="sig-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg>
                    </div>
                    <h3>3D LED Illuminated</h3>
                    <p>Premium channel letters and backlit signs that glow at night, providing 24/7 visibility for your storefront or office.</p>
                    <ul class="sig-list">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Front-lit & Back-lit options</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Energy Efficient LEDs</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Weatherproof Materials</li>
                    </ul>
                </div>

                <!-- Acrylic & Perspex -->
                <div class="sig-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="sig-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><path d="M3 9h18"></path><path d="M9 21V9"></path></svg>
                    </div>
                    <h3>Acrylic & Perspex</h3>
                    <p>Sleek, modern signs perfect for reception areas, corporate boardrooms, and high-end retail displays.</p>
                    <ul class="sig-list">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Crystal Clear Finishes</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Laser-Cut Precision</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Standoff Mounting</li>
                    </ul>
                </div>

                <!-- 2D Panels & Directional -->
                <div class="sig-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="sig-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                    </div>
                    <h3>2D & Directional</h3>
                    <p>Informational and wayfinding signage for large complexes, parking lots, and industrial environments.</p>
                    <ul class="sig-list">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> PVC & Aluminum Boards</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Reflective Vinyl Options</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Safety Compliance Signs</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Core Features -->
    <section class="sig-features">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Built to Last</span>
                <h2 class="section-title-modern">Quality <span>Standards</span></h2>
            </div>
            
            <div class="feat-grid">
                <div class="feat-item" data-aos="fade-up" data-aos-delay="100">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--sig-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <h4>Weather Resistance</h4>
                    <p>Designed to withstand harsh outdoor elements without fading or corroding.</p>
                </div>
                <div class="feat-item" data-aos="fade-up" data-aos-delay="200">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--sig-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <h4>24/7 Visibility</h4>
                    <p>Strategic lighting and high-contrast materials ensure you're seen day and night.</p>
                </div>
                <div class="feat-item" data-aos="fade-up" data-aos-delay="300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--sig-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>
                    <h4>Expert Installation</h4>
                    <p>Our professional team ensures safe and secure mounting on any surface.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Support Section -->
    <section class="hosting-support" style="padding-bottom: 100px;">
        <div class="container">
            <div class="support-box" data-aos="zoom-in">
                <div class="support-content">
                    <span class="section-tag" style="text-align: left;">Site Survey Available</span>
                    <h2 style="color: #fff;">Get Your Custom <span>Signage Quote</span></h2>
                    <p>Need a professional site assessment? We provide expert advice on the best signage type for your location and brand requirements.</p>
                    <a href="contact.php" class="btn btn-primary" style="background: var(--sig-accent); color: #000;">Book a Site Survey</a>
                </div>
                <div class="support-visual">
                    <svg width="200" height="200" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="3" y="3" width="18" height="18" rx="2" stroke="var(--sig-accent)" stroke-width="0.5"/>
                        <path d="M3 9h18M9 21V9" stroke="var(--sig-accent)" stroke-width="0.5"/>
                        <circle cx="15" cy="15" r="3" stroke="var(--sig-accent)" stroke-width="1"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>
</main>

<?php $ctaService = '3D & 2D Signage'; include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
