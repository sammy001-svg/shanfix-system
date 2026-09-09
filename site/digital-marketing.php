<?php
$pageSEO = [
    'title'       => 'Digital Marketing Services in Nairobi | SEO & Social Media - Shanfix Technology',
    'description' => 'Results-driven digital marketing in Nairobi — SEO, social media management, PPC advertising, and content marketing by Shanfix Technology.',
    'keywords'    => 'digital marketing Nairobi, SEO Kenya, social media marketing, PPC advertising',
    'canonical'   => '{{base}}/digital-marketing.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Digital Marketing","description":"Results-driven digital marketing in Nairobi — SEO, social media, PPC, and content marketing.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"{{base}}/"},"areaServed":{"@type":"City","name":"Nairobi"},"url":"{{base}}/digital-marketing.php"}',
];
include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= site_asset('marketing-modern.css') ?>">

<main class="marketing-modern-page">
    <!-- Hero Section -->
    <section class="mk-hero">
        <div class="container">
            <div class="mk-hero-content">
                <span class="section-tag" data-aos="fade-up">Precision Marketing</span>
                <h1 data-aos="fade-up" data-aos-delay="100">Data-Driven <span>Digital Growth</span></h1>
                <p data-aos="fade-up" data-aos-delay="200">We combine creative strategy with technical precision to amplify your brand's voice and drive measurable results in the digital landscape.</p>
                <div class="hero-btns" data-aos="fade-up" data-aos-delay="300">
                    <a href="contact.php" class="btn btn-primary">Scale Your Brand</a>
                    <a href="#services" class="btn btn-secondary">Our Services</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Marketing Services Grid -->
    <section id="services" class="mk-services-section">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Strategic Solutions</span>
                <h2 class="section-title-modern">Types of <span>Digital Marketing</span></h2>
                <p>Maximize your ROI with our specialized multi-channel marketing expertise.</p>
            </div>

            <div class="mk-grid">
                <!-- Search Engine Optimization (SEO) -->
                <div class="mk-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="mk-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h3>Search Engine Optimization</h3>
                    <p>Dominating search results through technical optimization, high-authority link building, and keyword excellence.</p>
                    <ul class="mk-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> On-Page & Off-Page SEO</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Technical Audit</li>
                    </ul>
                </div>

                <!-- Social Media Marketing (SMM) -->
                <div class="mk-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="mk-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>
                    </div>
                    <h3>Social Media Marketing</h3>
                    <p>Engage your audience where they live. We manage your presence across Facebook, Instagram, LinkedIn, and X.</p>
                    <ul class="mk-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Community Management</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Viral Content Strategy</li>
                    </ul>
                </div>

                <!-- Pay-Per-Click (PPC) -->
                <div class="mk-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="mk-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3>Paid Advertising (PPC)</h3>
                    <p>Instant traffic and leads through highly targeted Google Ads and Social Ads campaigns designed for conversion.</p>
                    <ul class="mk-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Search & Display Ads</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Retargeting Campaigns</li>
                    </ul>
                </div>

                <!-- Content Marketing -->
                <div class="mk-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="mk-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <h3>Content Marketing</h3>
                    <p>Establishing authority through high-value blogs, whitepapers, and video content that informs and converts.</p>
                    <ul class="mk-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Article Writing</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Video Scripts</li>
                    </ul>
                </div>

                <!-- Email Marketing -->
                <div class="mk-card" data-aos="fade-up" data-aos-delay="500">
                    <div class="mk-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </div>
                    <h3>Email Marketing</h3>
                    <p>Nurturing leads through automated, personalized email sequences that keep your brand top-of-mind.</p>
                    <ul class="mk-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Automation Flows</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Newsletter Design</li>
                    </ul>
                </div>

                <!-- Analytics & Reporting -->
                <div class="mk-card" data-aos="fade-up" data-aos-delay="600">
                    <div class="mk-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <h3>Analytics & Reporting</h3>
                    <p>Transparent data tracking and weekly reports to measure success and pivot strategies for maximum growth.</p>
                    <ul class="mk-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> KPI Tracking</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Monthly Audits</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Marketing Strategy -->
    <section class="mk-strategy">
        <div class="container">
            <div class="mk-strategy-row" data-aos="fade-right">
                <div class="mk-strategy-content">
                    <span class="section-tag" style="text-align: left;">Our Methodology</span>
                    <h2>The Science of <span>Digital Growth</span></h2>
                    <p>We don't just "post content." We engineer multi-channel ecosystems that capture attention and convert it into revenue.</p>
                    <ul class="feature-list-modern">
                        <li>Deep audience research and segmentation</li>
                        <li>Psychology-driven ad copywriting</li>
                        <li>A/B testing for continuous optimization</li>
                    </ul>
                </div>
                <div class="mk-strategy-visual">
                    <svg width="200" height="200" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="var(--mk-accent)" stroke-width="0.5" stroke-dasharray="4 4"/>
                        <path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke="var(--mk-accent)" stroke-width="0.5"/>
                        <circle cx="12" cy="12" r="4" fill="var(--mk-accent)" fill-opacity="0.2" stroke="var(--mk-accent)" stroke-width="0.5"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>

    <!-- Support/CTA Section -->
    <section class="hosting-support">
        <div class="container">
            <div class="support-box" data-aos="zoom-in">
                <div class="support-content">
                    <span class="section-tag" style="text-align: left;">Let's Scale</span>
                    <h2 style="color: #fff;">Ready to Outshine the <span>Competition?</span></h2>
                    <p>Your brand deserves to be heard. Let's create a digital marketing strategy that puts you at the top.</p>
                    <a href="contact.php" class="btn btn-primary">Get Your Free Strategy Call</a>
                </div>
                <div class="support-visual">
                    <svg width="200" height="200" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 3v18h18" stroke="var(--mk-accent)" stroke-width="0.5" stroke-linecap="round"/>
                        <path d="M18 9l-6 6-3-3-6 6" stroke="var(--mk-accent)" stroke-width="0.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>
</main>

<?php $ctaService = 'Digital Marketing'; include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
