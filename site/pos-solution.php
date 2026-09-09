<?php
$pageSEO = [
    'title'       => 'Point of Sale System in Kenya | POS Software - Shanfix Technology',
    'description' => 'Modern POS systems for retail and hospitality in Kenya. Track sales, inventory, and staff in real time with Shanfix Technology\'s POS software.',
    'keywords'    => 'POS system Kenya, point of sale Nairobi, retail POS, restaurant POS',
    'canonical'   => 'https://shanfixtechnology.com/pos-solution.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Point of Sale System","description":"Modern POS software for retail and hospitality in Kenya — real-time sales, inventory, and staff tracking.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"https://shanfixtechnology.com/"},"areaServed":{"@type":"Country","name":"Kenya"},"url":"https://shanfixtechnology.com/pos-solution.php"}',
];
include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= site_asset('pos-modern.css') ?>">

<main class="pos-modern-page">
    <!-- Hero Section -->
    <section class="pos-hero">
        <div class="container">
            <div class="pos-hero-content">
                <span class="section-tag" data-aos="fade-up">Enterprise Commerce</span>
                <h1 data-aos="fade-up" data-aos-delay="100">The Next Generation of <span>Point of Sales</span></h1>
                <p data-aos="fade-up" data-aos-delay="200">Empower your business with a unified POS system that synchronizes sales, inventory, and customer data in real-time across all your locations.</p>
                <div class="hero-btns" data-aos="fade-up" data-aos-delay="300">
                    <a href="https://pos.shanfixtechnology.com/" class="btn btn-primary">Login to POS</a>
                    <a href="https://pos.shanfixtechnology.com/register.php" class="btn btn-secondary">Register Today</a>
                </div>
                
                <div class="pos-stats" data-aos="fade-up" data-aos-delay="400">
                    <div class="stat-item">
                        <h2>99.9%</h2>
                        <p>Uptime Guaranteed</p>
                    </div>
                    <div class="stat-item">
                        <h2>10ms</h2>
                        <p>Processing Speed</p>
                    </div>
                    <div class="stat-item">
                        <h2>24/7</h2>
                        <p>Priority Support</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Core Features Grid -->
    <section id="features" class="pos-features-section">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Powerful Capabilities</span>
                <h2 class="section-title-modern">Everything you need to <span>Scale Fast</span></h2>
            </div>

            <div class="pos-features-grid">
                <!-- Multi-Store Sync -->
                <div class="pos-feature-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="pos-icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                    </div>
                    <h3>Multi-Store Sync</h3>
                    <p>Manage multiple branches from a single dashboard. Synchronize inventory, prices, and promotions across all outlets instantly.</p>
                </div>

                <!-- Advanced Inventory -->
                <div class="pos-feature-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="pos-icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"></path><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>
                    </div>
                    <h3>Smart Inventory</h3>
                    <p>Automatic stock tracking with low-stock alerts. Track batch numbers, expiry dates, and perform bulk imports with ease.</p>
                </div>

                <!-- Offline Mode -->
                <div class="pos-feature-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="pos-icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </div>
                    <h3>Cloud-Hybrid Offline</h3>
                    <p>Keep selling even when the internet goes down. Our system automatically syncs all offline transactions once connection is restored.</p>
                </div>

                <!-- Real-time Analytics -->
                <div class="pos-feature-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="pos-icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <h3>Dynamic Analytics</h3>
                    <p>Get instant insights into your best-selling products, peak hours, and staff performance through beautiful interactive charts.</p>
                </div>

                <!-- Multi-Payment Support -->
                <div class="pos-feature-card" data-aos="fade-up" data-aos-delay="500">
                    <div class="pos-icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                    </div>
                    <h3>Omni-Channel Pay</h3>
                    <p>Support Cash, M-Pesa, Credit Cards, and Loyalty Points seamlessly. Integration with major local payment gateways.</p>
                </div>

                <!-- Customer Loyalty -->
                <div class="pos-feature-card" data-aos="fade-up" data-aos-delay="600">
                    <div class="pos-icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                    </div>
                    <h3>Loyalty & CRM</h3>
                    <p>Build customer profiles, track purchase history, and run targeted SMS/Email marketing campaigns directly from the POS.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Industry Specific Section -->
    <section class="pos-industries">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Tailored Excellence</span>
                <h2 class="section-title-modern">Solutions for <span>Every Industry</span></h2>
            </div>

            <div class="industry-grid">
                <div class="industry-item" data-aos="zoom-in" data-aos-delay="100">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"></path><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"></path><path d="M2 7h20"></path><path d="M22 7v3a2 2 0 0 1-2 2v0a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 10V7"></path></svg>
                    <h4>Retail & Supermarkets</h4>
                    <p>Fast scanning and high-volume transaction management.</p>
                </div>
                <div class="industry-item" data-aos="zoom-in" data-aos-delay="200">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path><line x1="6" y1="1" x2="6" y2="4"></line><line x1="10" y1="1" x2="10" y2="4"></line><line x1="14" y1="1" x2="14" y2="4"></line></svg>
                    <h4>Restaurants & Cafes</h4>
                    <p>Table management, kitchen display systems, and split billing.</p>
                </div>
                <div class="industry-item" data-aos="zoom-in" data-aos-delay="300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                    <h4>Hardware & Spares</h4>
                    <p>Serial number tracking and advanced unit conversions.</p>
                </div>
                <div class="industry-item" data-aos="zoom-in" data-aos-delay="400">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 14 4-4"></path><path d="m3.34 19 9.39-9.39a2.5 2.5 0 0 1 3.54 3.54L6.88 22.54a2.5 2.5 0 0 1-3.54 0l-.13-.13a2.5 2.5 0 0 1 0-3.54Z"></path><path d="m14.5 2 6.5 6.5"></path><path d="m9 16 3-3"></path><path d="M21 3.5a2.5 2.5 0 0 1 0 3.5L14 14l-3.5-3.5 7-7a2.5 2.5 0 0 1 3.5 0Z"></path></svg>
                    <h4>Pharmacies</h4>
                    <p>Expiry date management and specialized medical inventory.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Hardware Section -->
    <section class="sw-features-section">
        <div class="container">
            <div class="sw-features-row" data-aos="fade-right">
                <div class="sw-feature-content">
                    <span class="section-tag" style="text-align: left;">Seamless Integration</span>
                    <h2>Premium <span>Hardware Support</span></h2>
                    <p>Our software is designed to work with all major point-of-sale hardware peripherals, ensuring a smooth checkout experience.</p>
                    <ul class="feature-list-modern">
                        <li>Thermal Receipt Printers (USB, Bluetooth, LAN)</li>
                        <li>High-speed 1D/2D Barcode Scanners</li>
                        <li>Electronic Cash Drawers</li>
                        <li>Customer Facing Displays & Pole Displays</li>
                    </ul>
                </div>
                <div class="sw-feature-image">
                    <svg width="240" height="240" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="2" y="3" width="20" height="14" rx="2" stroke="var(--pos-accent)" stroke-width="0.5" opacity="0.3"/>
                        <path d="M6 21h12M12 17v4" stroke="var(--pos-accent)" stroke-width="0.5" stroke-linecap="round"/>
                        <path d="M4 7h16" stroke="var(--pos-accent)" stroke-width="0.2"/>
                        <circle cx="12" cy="10" r="3" stroke="var(--pos-accent)" stroke-width="0.5"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="pos-cta">
        <div class="container">
            <div class="pos-cta-card" data-aos="zoom-in">
                <span class="section-tag">Take Control</span>
                <h2>Ready to Modernize Your <span>Business Operations?</span></h2>
                <p>Join hundreds of businesses in Nairobi that trust Shanfix POS to drive their growth.</p>
                <div class="hero-btns" style="margin-top: 3rem;">
                    <a href="contact.php" class="btn btn-primary">Request a Custom Quote</a>
                    <a href="tel:+254751869165" class="btn btn-secondary">Talk to an Expert</a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
