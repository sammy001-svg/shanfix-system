<?php
$pageSEO = [
    'title'       => 'Web Hosting Services in Kenya | Reliable & Affordable - Shanfix Technology',
    'description' => 'Reliable and affordable web hosting in Kenya. Shared, VPS, and dedicated hosting with 99.9% uptime guarantee from Shanfix Technology Nairobi.',
    'keywords'    => 'web hosting Kenya, domain registration, VPS hosting Nairobi, cloud hosting',
    'canonical'   => '{{base}}/web-hosting.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Web Hosting","description":"Reliable and affordable web hosting in Kenya. Shared, VPS, and dedicated servers with 99.9% uptime.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"{{base}}/"},"areaServed":{"@type":"Country","name":"Kenya"},"url":"{{base}}/web-hosting.php"}',
];
include 'includes/header.php'; ?>

<link rel="stylesheet" href="<?= site_asset('hosting-modern.css') ?>">

<main class="hosting-modern-page">
    <!-- Hero Section -->
    <section class="hosting-hero">
        <div class="container hero-inner">
            <div class="hosting-hero-content">
                <span class="section-tag" data-aos="fade-right">Infrastructure for Innovators</span>
                <h1 data-aos="fade-up">Elite Web <span>Hosting Solutions</span></h1>
                <p data-aos="fade-up" data-aos-delay="100">
                    Experience lightning-fast speeds, military-grade security, and 24/7 expert support. We build the foundation, you build the future.
                </p>
                
                <div class="hosting-metrics" data-aos="fade-up" data-aos-delay="200">
                    <div class="metric-card">
                        <span class="metric-value">99.9%</span>
                        <span class="metric-label">Uptime SaaS</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-value">< 12ms</span>
                        <span class="metric-label">Latency</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-value">24/7/365</span>
                        <span class="metric-label">Expert Support</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Section Navigation -->
    <nav class="hosting-subnav" data-aos="fade-down">
        <div class="container subnav-container">
            <a href="#shared" class="subnav-link">Shared Hosting</a>
            <a href="#wordpress" class="subnav-link">WordPress Hosting</a>
            <a href="#dedicated" class="subnav-link">Dedicated Servers</a>
            <a href="#email" class="subnav-link">Email Hosting</a>
        </div>
    </nav>

    <!-- Shared Hosting Section -->
    <section id="shared" class="hosting-section shared-hosting-bg">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Reliable & Affordable</span>
                <h2 class="section-title-modern">Premium <span>Shared Hosting</span></h2>
                <p>Perfect for startups, bloggers, and small businesses looking for a robust foundation.</p>
            </div>

            <div class="pricing-grid">
                <!-- Shared: Starter -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="price-header">
                        <h3>Starter</h3>
                        <div class="price-amount">KES 5,500<span>/yr</span></div>
                        <p class="price-desc">Great for personal sites and basic blogs.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Free .CO.KE Domain</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 50GB SSD Storage</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 100GB Bandwidth</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 5 Email Accounts</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Free SSL Certificate</li>
                    </ul>
                    <button type="button" class="btn btn-primary open-checkout-modal" data-package-name="Starter Shared Hosting" data-package-price="KES 5,500/yr">Order Now</button>
                </div>

                <!-- Shared: Pro -->
                <div class="price-card featured" data-aos="fade-up" data-aos-delay="200">
                    <div class="price-header">
                        <h3>Professional</h3>
                        <div class="price-amount">KES 8,500<span>/yr</span></div>
                        <p class="price-desc">Optimized for growing business websites.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Free .COM/.CO.KE Domain</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 100GB SSD Storage</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Unlimited Bandwidth</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 50 Email Accounts</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Daily Off-site Backups</li>
                    </ul>
                    <button type="button" class="btn btn-primary accent open-checkout-modal" data-package-name="Professional Shared Hosting" data-package-price="KES 8,500/yr">Go Pro</button>
                </div>

                <!-- Shared: Business -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="price-header">
                        <h3>Enterprise</h3>
                        <div class="price-amount">KES 10,000<span>/yr</span></div>
                        <p class="price-desc">The ultimate shared hosting experience.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Multiple Free Domains</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Unlimited SSD Storage</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Unlimited Bandwidth</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Priority 24/7 Support</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Free Site Migration</li>
                    </ul>
                    <button type="button" class="btn btn-primary open-checkout-modal" data-package-name="Enterprise Shared Hosting" data-package-price="KES 10,000/yr">Scale Up</button>
                </div>
            </div>
        </div>
    </section>

    <!-- WordPress Hosting Section -->
    <section id="wordpress" class="hosting-section wordpress-hosting-bg">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Optimized Performance</span>
                <h2 class="section-title-modern">Managed <span>WordPress Hosting</span></h2>
                <p>Lightning-fast speeds and bulletproof security specifically tuned for WordPress.</p>
            </div>

            <div class="pricing-grid">
                <!-- WP: Starter -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="price-header">
                        <h3>WP Lite</h3>
                        <div class="price-amount">KES 4,300<span>/yr</span></div>
                        <p class="price-desc">Perfect for blogs and portfolio sites.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> LSCache Optimized</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 20GB NVMe SSD</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Automatic WP Updates</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> One-Click Staging</li>
                    </ul>
                    <button type="button" class="btn btn-primary open-checkout-modal" data-package-name="WP Lite" data-package-price="KES 4,300/yr">Get Started</button>
                </div>

                <!-- WP: Pro -->
                <div class="price-card featured" data-aos="fade-up" data-aos-delay="200">
                    <div class="price-header">
                        <h3>WP Professional</h3>
                        <div class="price-amount">KES 6,800<span>/yr</span></div>
                        <p class="price-desc">Best for high-traffic blogs & business sites.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Object Cache (Redis)</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 50GB NVMe SSD</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Advanced Security Shield</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> WP Malware Cleanup</li>
                    </ul>
                    <button type="button" class="btn btn-primary accent open-checkout-modal" data-package-name="WP Professional" data-package-price="KES 6,800/yr">Select WP Pro</button>
                </div>

                <!-- WP: VIP -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="price-header">
                        <h3>WP Enterprise</h3>
                        <div class="price-amount">KES 9,000<span>/yr</span></div>
                        <p class="price-desc">Unlimited power for e-commerce stores.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> WooCommerce Optimized</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Unlimited NVMe SSD</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> VIP Support Priority</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Custom CDN Setup</li>
                    </ul>
                    <button type="button" class="btn btn-primary open-checkout-modal" data-package-name="WP Enterprise" data-package-price="KES 9,000/yr">Inquire VIP</button>
                </div>
            </div>
        </div>
    </section>

    <!-- Dedicated Hosting Section -->
    <section id="dedicated" class="hosting-section dedicated-hosting-bg">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Total Control</span>
                <h2 class="section-title-modern">Bare Metal <span>Dedicated Servers</span></h2>
                <p>Enterprise-grade hardware with 100% dedicated resources and total root access.</p>
            </div>

            <div class="pricing-grid">
                <!-- Dedicated: Standard -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="price-header">
                        <h3>Standard Node</h3>
                        <div class="price-amount">KES 8,000<span>/yr</span></div>
                        <p class="price-desc">Perfect for high-traffic apps & small DBs.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 4 Cores / 8 Threads</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 16GB DDR4 RAM</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 500GB SSD RAID 1</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 1Gbps Uplink</li>
                    </ul>
                    <button type="button" class="btn btn-primary open-checkout-modal" data-package-name="Standard Node" data-package-price="KES 8,000/yr">Deploy Now</button>
                </div>

                <!-- Dedicated: Advanced -->
                <div class="price-card featured" data-aos="fade-up" data-aos-delay="200">
                    <div class="price-header">
                        <h3>Advanced Power</h3>
                        <div class="price-amount">KES 13,000<span>/yr</span></div>
                        <p class="price-desc">Ideal for corporate systems & large DBs.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 8 Cores / 16 Threads</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 32GB DDR4 RAM</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 1TB NVMe SSD</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 10TB Bandwidth</li>
                    </ul>
                    <button type="button" class="btn btn-primary accent open-checkout-modal" data-package-name="Advanced Power" data-package-price="KES 13,000/yr">Go Enterprise</button>
                </div>

                <!-- Dedicated: Elite -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="price-header">
                        <h3>Elite Infrastructure</h3>
                        <div class="price-amount">KES 20,000<span>/yr</span></div>
                        <p class="price-desc">Unrivaled power for mission-critical apps.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 16 Cores / 32 Threads</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 64GB DDR4 RAM</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 2TB NVMe RAID 10</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Dedicated Support Eng.</li>
                    </ul>
                    <button type="button" class="btn btn-primary open-checkout-modal" data-package-name="Elite Infrastructure" data-package-price="KES 20,000/yr">Contact Architects</button>
                </div>
            </div>
        </div>
    </section>

    <!-- Email Hosting Section -->
    <section id="email" class="hosting-section email-hosting-bg">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Professional Communication</span>
                <h2 class="section-title-modern">Enterprise <span>Email Hosting</span></h2>
                <p>Build trust with custom @yourcompany.com addresses. High-security mailboxes with seamless sync across all devices.</p>
            </div>

            <div class="pricing-grid">
                <!-- Email: Starter -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="price-header">
                        <h3>Mail Starter</h3>
                        <div class="price-amount">KES 2,500<span>/yr</span></div>
                        <p class="price-desc">Great for small teams and startups.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 5 Professional Accounts</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 10GB Storage</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Webmail & Mobile Sync</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Anti-Spam Protection</li>
                    </ul>
                    <button type="button" class="btn btn-primary open-checkout-modal" data-package-name="Mail Starter" data-package-price="KES 2,500/yr">Get Started</button>
                </div>

                <!-- Email: Business -->
                <div class="price-card featured" data-aos="fade-up" data-aos-delay="200">
                    <div class="price-header">
                        <h3>Mail Business</h3>
                        <div class="price-amount">KES 5,500<span>/yr</span></div>
                        <p class="price-desc">Optimal for growing businesses.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Unlimited Accounts</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 50GB Cloud Storage</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Advanced Security</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 24/7 Priority Support</li>
                    </ul>
                    <button type="button" class="btn btn-primary accent open-checkout-modal" data-package-name="Mail Business" data-package-price="KES 5,500/yr">Go Business</button>
                </div>

                <!-- Email: Enterprise -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="price-header">
                        <h3>Mail Enterprise</h3>
                        <div class="price-amount">KES 8,600<span>/yr</span></div>
                        <p class="price-desc">Custom infrastructure for large orgs.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Dedicated Mail Server</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Unlimited Storage</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Custom Archiving</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> API Integration</li>
                    </ul>
                    <button type="button" class="btn btn-primary open-checkout-modal" data-package-name="Mail Enterprise" data-package-price="KES 8,600/yr">Contact Us</button>
                </div>
            </div>
        </div>
    </section>

    <!-- Support Section -->
    <section class="hosting-support">
        <div class="container">
            <div class="support-box">
                <div class="support-content">
                    <h2>Got Questions? <br><span>We're Here to Help.</span></h2>
                    <p>Our infrastructure experts are standing by 24/7/365 to help you find the perfect hosting environment for your project.</p>
                    <div class="hero-buttons">
                        <a href="contact.php" class="btn btn-primary">Chit-Chat with Experts</a>
                        <a href="tel:<?= htmlspecialchars($_brand['phone_tel']) ?>" class="btn btn-secondary">Call Us Directly</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
