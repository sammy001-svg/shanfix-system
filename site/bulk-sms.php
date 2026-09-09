<?php
$pageSEO = [
    'title'       => 'Bulk SMS Services in Kenya | Mass Messaging - Shanfix Technology',
    'description' => 'Send bulk SMS messages across Kenya at affordable rates. Reliable bulk SMS gateway for businesses, churches, schools, and organizations.',
    'keywords'    => 'bulk SMS Kenya, mass messaging, SMS gateway Nairobi, affordable bulk SMS',
    'canonical'   => 'https://shanfixtechnology.com/bulk-sms.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Bulk SMS","description":"Reliable bulk SMS gateway for businesses, churches, schools, and organizations across Kenya.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"https://shanfixtechnology.com/"},"areaServed":{"@type":"Country","name":"Kenya"},"url":"https://shanfixtechnology.com/bulk-sms.php"}',
];
include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= site_asset('sms-modern.css') ?>">

<main>
    <!-- Hero Section -->
    <section class="sms-hero">
        <div class="sms-hero-overlay"></div>
        <div class="container sms-hero-content" data-aos="fade-up">
            <h1>Bulk SMS Solutions</h1>
            <p>Connect with your audience instantly with our reliable, high-speed messaging platform. Reach thousands in seconds.</p>
            <div class="hero-buttons">
                <a href="#pricing" class="btn btn-primary">View Pricing</a>
                <a href="https://sms.shanfixtechnology.com/" class="btn btn-secondary" target="_blank">Portal Login</a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="sms-features">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <h2>Powerful Messaging Features</h2>
                <p>Everything you need to run successful mobile marketing campaigns and critical notifications.</p>
            </div>
            
            <div class="features-grid">
                <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    </div>
                    <h3>High Speed Delivery</h3>
                    <p>Our direct connections to major carriers ensure your messages reach recipients almost instantly, no matter the volume.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                    </div>
                    <h3>Custom Sender ID</h3>
                    <p>Enhance brand recognition by using your company name as the sender identity for all your outgoing messages.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    </div>
                    <h3>Real-time Analytics</h3>
                    <p>Track delivery rates, open rates, and engagement with our comprehensive dashboard and real-time reporting tools.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </div>
                    <h3>Scheduled Campaigns</h3>
                    <p>Plan your marketing strategy in advance. Schedule your messages to go out at the perfect time for your audience.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="500">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3>Secure & Reliable</h3>
                    <p>Our platform uses enterprise-grade security to ensure your data and customer information are always protected.</p>
                </div>
                
                <div class="feature-card" data-aos="fade-up" data-aos-delay="600">
                    <div class="feature-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
                    </div>
                    <h3>Developer Friendly API</h3>
                    <p>Integrate SMS capabilities directly into your own applications with our robust and well-documented REST API.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section class="sms-pricing" id="pricing">
        <div class="container">
            <div class="section-header" data-aos="fade-up">
                <h2>Flexible Pricing Tiers</h2>
                <p>Choose a plan that scales with your business needs. No hidden fees, just pure communication.</p>
            </div>
            
            <div class="pricing-grid">
                <div class="pricing-card" data-aos="fade-right">
                    <div class="pricing-header">
                        <h3>Starter</h3>
                        <div class="price">1.0<span> KES / SMS</span></div>
                        <p>1,000 - 5,000 SMS Credits</p>
                    </div>
                    <ul class="pricing-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Free Sender ID Setup</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> API Integration</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Standard Support</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Web Portal Access</li>
                    </ul>
                    <a href="contact.php?plan=starter" class="btn btn-secondary">Get Started</a>
                </div>
                
                <div class="pricing-card featured" data-aos="fade-up">
                    <div class="pricing-header">
                        <h3>Growth</h3>
                        <div class="price">0.9<span> KES / SMS</span></div>
                        <p>5,001 - 20,000 SMS Credits</p>
                    </div>
                    <ul class="pricing-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Personalized Messaging</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Priority Support</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Advanced Analytics</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> API & Webhook Support</li>
                    </ul>
                    <a href="contact.php?plan=growth" class="btn btn-primary">Most Popular</a>
                </div>
                
                <div class="pricing-card" data-aos="fade-left">
                    <div class="pricing-header">
                        <h3>Enterprise</h3>
                        <div class="price">0.8<span> KES / SMS</span></div>
                        <p>20,001+ SMS Credits</p>
                    </div>
                    <ul class="pricing-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Dedicated Account Manager</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Custom SLA</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Unlimited Sender IDs</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Full API Suite</li>
                    </ul>
                    <a href="contact.php?plan=enterprise" class="btn btn-secondary">Contact Sales</a>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="sms-cta">
        <div class="container" data-aos="zoom-in">
            <h2>Ready to Start Your Campaign?</h2>
            <p>Join hundreds of businesses using Shanfix Technology to grow their reach. Sign up today and get 50 free credits to test our platform.</p>
            <a href="contact.php" class="btn btn-white">Get Started Now</a>
        </div>
    </section>
</main>
<?php $ctaService = 'Bulk SMS Marketing'; include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
