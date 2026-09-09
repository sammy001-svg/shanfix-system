<?php
$pageSEO = [
    'title'       => 'Networking Solutions in Nairobi | IT Infrastructure - Shanfix Technology',
    'description' => 'Professional networking and IT infrastructure solutions in Nairobi. LAN, WAN, Wi-Fi setup, network security, and structured cabling by Shanfix Technology.',
    'keywords'    => 'networking solutions Nairobi, IT infrastructure Kenya, LAN setup, network security',
    'canonical'   => 'https://shanfixtechnology.com/networking-solution.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Networking Solutions","description":"Professional networking and IT infrastructure — LAN, WAN, Wi-Fi, security, and structured cabling in Nairobi.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"https://shanfixtechnology.com/"},"areaServed":{"@type":"City","name":"Nairobi"},"url":"https://shanfixtechnology.com/networking-solution.php"}',
];
include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= site_asset('networking-modern.css') ?>">

<main class="networking-modern-page">
    <!-- Hero Section -->
    <section class="net-hero">
        <div class="container">
            <div class="net-hero-content">
                <span class="section-tag" data-aos="fade-up">Enterprise Connectivity</span>
                <h1 data-aos="fade-up" data-aos-delay="100">Future-Proof <span>Network Infrastructure</span></h1>
                <p data-aos="fade-up" data-aos-delay="200">We design, deploy, and manage high-performance network ecosystems that ensure your business stays connected, secure, and ahead of the curve.</p>
                <div class="hero-btns" data-aos="fade-up" data-aos-delay="300">
                    <a href="contact.php" class="btn btn-primary">Request a Site Audit</a>
                    <a href="#pricing" class="btn btn-secondary">View Packages</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Networking Packages -->
    <section id="pricing" class="pricing-section">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Reliable Setup</span>
                <h2 class="section-title-modern">Networking <span>Service Plans</span></h2>
                <p style="text-align: center; color: #94a3b8; max-width: 700px; margin: -2rem auto 4rem;">Choose the right infrastructure level for your business needs.</p>
            </div>

            <div class="pricing-grid">
                <!-- SOHO Setup -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="price-header">
                        <h3>SOHO / Startup</h3>
                        <div class="price-amount">Ksh 15k<span>/Starting</span></div>
                        <p class="price-desc">Perfect for small offices and home setups needing reliable connectivity.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Wi-Fi 6 Router Setup</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Up to 10 Nodes</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Basic Security Config</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 1-Year Local Support</li>
                    </ul>
                    <a href="contact.php" class="btn btn-secondary">Get Started</a>
                </div>

                <!-- Business Enterprise -->
                <div class="price-card featured" data-aos="fade-up" data-aos-delay="200" style="position: relative; border-color: var(--net-accent);">
                    <div class="price-header">
                        <h3>Business Enterprise</h3>
                        <div class="price-amount">Ksh 45k<span>/Starting</span></div>
                        <p class="price-desc">Comprehensive infrastructure for growing companies with high data demands.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Structured Cabling (Cat6)</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Rack & Server Mounting</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> VPN & Remote Access</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Firewall Management</li>
                    </ul>
                    <a href="contact.php" class="btn btn-primary" style="background: var(--net-accent); color: #000;">Scale Now</a>
                </div>

                <!-- Custom Infrastructure -->
                <div class="price-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="price-header">
                        <h3>Elite Custom</h3>
                        <div class="price-amount">Custom<span>/Quote</span></div>
                        <p class="price-desc">Tailor-made fiber-optic and wireless solutions for large-scale facilities.</p>
                    </div>
                    <ul class="price-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Fiber Optic Installation</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Multi-Site Bridging</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> 24/7 Managed NOC</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Redundant Failover</li>
                    </ul>
                    <a href="contact.php" class="btn btn-secondary">Request Quote</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Types of Networking Solutions -->
    <section class="net-capabilities">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Our Expertise</span>
                <h2 class="section-title-modern">Full-Stack <span>Networking</span></h2>
            </div>
            <div class="capability-grid">
                <div class="capability-card" data-aos="fade-up" data-aos-delay="100">
                    <h4>Structured Cabling</h4>
                    <p>Clean, organized Cat6/Cat7 cabling for maximum speed and easy maintenance.</p>
                </div>
                <div class="capability-card" data-aos="fade-up" data-aos-delay="200">
                    <h4>Wireless Solutions</h4>
                    <p>Ubiquiti & Cisco powered Wi-Fi 6 mesh networks for dead-zone-free coverage.</p>
                </div>
                <div class="capability-card" data-aos="fade-up" data-aos-delay="300">
                    <h4>Network Security</h4>
                    <p>Next-gen firewalls and IDS/IPS systems to protect your data from threats.</p>
                </div>
                <div class="capability-card" data-aos="fade-up" data-aos-delay="400">
                    <h4>Managed Services</h4>
                    <p>Proactive monitoring and maintenance to prevent downtime before it happens.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Support Section -->
    <section class="hosting-support">
        <div class="container">
            <div class="support-box" data-aos="zoom-in">
                <div class="support-content">
                    <span class="section-tag" style="text-align: left;">24/7 Monitoring</span>
                    <h2>Is Your Network <span>Secure?</span></h2>
                    <p>Don't wait for a failure to act. Get a professional network audit and upgrade your connectivity today.</p>
                    <a href="contact.php" class="btn btn-primary" style="background: var(--net-accent); color: #000;">Schedule My Site Audit</a>
                </div>
                <div class="support-visual">
                    <svg width="200" height="200" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="2" y="2" width="20" height="20" rx="2" stroke="var(--net-accent)" stroke-width="0.5"/>
                        <path d="M7 12h10M12 7v10" stroke="var(--net-accent)" stroke-width="0.5"/>
                        <circle cx="12" cy="12" r="4" stroke="var(--net-accent)" stroke-width="0.5" stroke-dasharray="2 2"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>
</main>

<?php $ctaService = 'Networking & IT Infrastructure'; include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
