<?php
$pageSEO = [
    'title'       => 'Business ERP Solutions in Kenya | Enterprise Software - Shanfix Technology',
    'description' => 'Comprehensive business ERP in Kenya. Manage finance, inventory, HR, procurement, and operations in one integrated system by Shanfix Technology.',
    'keywords'    => 'ERP solutions Kenya, business ERP Nairobi, enterprise software, inventory management',
    'canonical'   => '{{base}}/erp-solution.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Business ERP","description":"Comprehensive ERP system in Kenya — finance, inventory, HR, and procurement in one platform.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"{{base}}/"},"areaServed":{"@type":"Country","name":"Kenya"},"url":"{{base}}/erp-solution.php"}',
];
include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= site_asset('erp-modern.css') ?>">

<main class="erp-modern-page">
    <!-- Hero Section -->
    <section class="erp-hero">
        <div class="container">
            <div class="erp-hero-content">
                <span class="section-tag" data-aos="fade-up">Enterprise Intelligence</span>
                <h1 data-aos="fade-up" data-aos-delay="100">Unified <span>Business ERP</span> Solutions</h1>
                <p data-aos="fade-up" data-aos-delay="200">Streamline your entire organization with a single, powerful platform. From financial management to supply chain optimization, we provide the tools to lead your industry.</p>
                <div class="hero-btns" data-aos="fade-up" data-aos-delay="300">
                    <a href="contact.php" class="btn btn-primary">Request Implementation</a>
                    <a href="#modules" class="btn btn-secondary">Explore Modules</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Core ERP Modules -->
    <section id="modules" class="erp-modules">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Core Ecosystem</span>
                <h2 class="section-title-modern">Integrated <span>Enterprise Modules</span></h2>
            </div>

            <div class="erp-modules-grid">
                <!-- Financial Management -->
                <div class="erp-module-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="erp-module-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <h3>Finance & Accounting</h3>
                    <p>Automate financial reporting, tax compliance, and multi-currency transactions with precision and speed.</p>
                </div>

                <!-- Supply Chain -->
                <div class="erp-module-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="erp-module-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polyline points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    </div>
                    <h3>Supply Chain (SCM)</h3>
                    <p>Optimize procurement, logistics, and supplier management to ensure zero delays and maximum efficiency.</p>
                </div>

                <!-- HRM & Payroll -->
                <div class="erp-module-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="erp-module-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                    </div>
                    <h3>HRM & Global Payroll</h3>
                    <p>Manage employee lifecycles, performance tracking, and automated payroll processing in one secure place.</p>
                </div>

                <!-- Inventory & Warehouse -->
                <div class="erp-module-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="erp-module-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                    </div>
                    <h3>Inventory & WMS</h3>
                    <p>Real-time stock visibility across global warehouses with intelligent demand forecasting and automated replenishment.</p>
                </div>

                <!-- CRM & Sales -->
                <div class="erp-module-card" data-aos="fade-up" data-aos-delay="500">
                    <div class="erp-module-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <h3>Sales & CRM</h3>
                    <p>Convert leads faster and maintain long-term customer relationships with data-driven sales automation.</p>
                </div>

                <!-- Project Management -->
                <div class="erp-module-card" data-aos="fade-up" data-aos-delay="600">
                    <div class="erp-module-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <h3>Project Lifecycle</h3>
                    <p>Track project timelines, resource allocation, and budget burn-rates for maximum project profitability.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Strategic Advantages -->
    <section class="erp-advantages">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Why Choose Us</span>
                <h2 class="section-title-modern">The Shanfix <span>ERP Advantage</span></h2>
            </div>

            <div class="advantage-item" data-aos="fade-right">
                <div class="advantage-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                </div>
                <div class="advantage-text">
                    <h4>Single Source of Truth</h4>
                    <p>Eliminate data silos by housing all business processes in a single, unified database accessible from anywhere.</p>
                </div>
            </div>

            <div class="advantage-item" data-aos="fade-left">
                <div class="advantage-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                </div>
                <div class="advantage-text">
                    <h4>Bank-Grade Data Security</h4>
                    <p>Protect your sensitive corporate data with end-to-end encryption, multi-factor authentication, and regular automated backups.</p>
                </div>
            </div>

            <div class="advantage-item" data-aos="fade-right">
                <div class="advantage-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div class="advantage-text">
                    <h4>Real-Time Strategic Insight</h4>
                    <p>Powerful BI tools provide live dashboards that let you make informed decisions based on what's happening right now.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Industries Served -->
    <section class="erp-industries">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Our Impact</span>
                <h2 class="section-title-modern">Industries <span>We Power</span></h2>
            </div>

            <div class="industry-grid">
                <div class="erp-industry-card" data-aos="zoom-in" data-aos-delay="100">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M10 21V8l8 4v9"/><path d="M3 21V2l7 4v15"/><path d="M13 21V11l8 4v6"/><path d="M13 21h8"/><path d="M3 7l7 4"/><path d="M13 11l8 4"/></svg>
                    <h4>Manufacturing</h4>
                </div>
                <div class="erp-industry-card" data-aos="zoom-in" data-aos-delay="200">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <h4>Real Estate</h4>
                </div>
                <div class="erp-industry-card" data-aos="zoom-in" data-aos-delay="300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polyline points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    <h4>Logistics</h4>
                </div>
                <div class="erp-industry-card" data-aos="zoom-in" data-aos-delay="400">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C10 14.5 10.5 19 12 21"/></svg>
                    <h4>Agriculture</h4>
                </div>
            </div>
        </div>
    </section>

    <!-- ERP CTA Section -->
    <section class="erp-cta">
        <div class="container">
            <div class="erp-cta-box" data-aos="flip-up">
                <span class="section-tag">Transformation Starts Here</span>
                <h2>Ready to Scale Your <span>Organization?</span></h2>
                <p>Join the ranks of high-performance enterprises across East Africa using Shanfix ERP to dominate their markets.</p>
                <div class="hero-btns" style="margin-top: 3rem;">
                    <a href="contact.php" class="btn btn-primary">Schedule a Private Demo</a>
                    <a href="tel:<?= htmlspecialchars($_brand['phone_tel']) ?>" class="btn btn-secondary">Talk to an Architect</a>
                </div>
            </div>
        </div>
    </section>
</main>
<?php $ctaService = 'Business ERP Solution'; include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
