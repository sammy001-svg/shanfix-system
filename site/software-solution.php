<?php
$pageSEO = [
    'title'       => 'Custom Software Solutions in Kenya | Shanfix Technology',
    'description' => 'Custom software development in Kenya — ERP, POS, accounting, CRM, school management, HRM, and more tailored business systems from Shanfix Technology.',
    'keywords'    => 'software solutions Kenya, custom software Nairobi, ERP system Kenya, business software',
    'canonical'   => '{{base}}/software-solution.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Custom Software Solutions","description":"Custom software development in Kenya — ERP, POS, CRM, accounting, and more tailored business systems.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"{{base}}/"},"areaServed":{"@type":"Country","name":"Kenya"},"url":"{{base}}/software-solution.php"}',
];
include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= site_asset('software-modern.css') ?>">

<main class="software-modern-page">
    <!-- Hero Section -->
    <section class="sw-hero">
        <div class="container">
            <div class="sw-hero-content">
                <span class="section-tag" data-aos="fade-up">Intelligent Engineering</span>
                <h1 data-aos="fade-up" data-aos-delay="100">Future-Ready <span>Software Solutions</span></h1>
                <p data-aos="fade-up" data-aos-delay="200">We build high-performance, scalable, and secure custom software tailored to transform your business operations and drive exponential growth.</p>
                <div class="hero-btns" data-aos="fade-up" data-aos-delay="300">
                    <a href="contact.php" class="btn btn-primary">Start Your Project</a>
                    <a href="#types" class="btn btn-secondary">Explore Solutions</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Software Types Grid -->
    <section id="types" class="sw-types-section">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Our Expertise</span>
                <h2 class="section-title-modern">Solutions for <span>Every Industry</span></h2>
                <p>From streamlined internal tools to complex enterprise systems, we deliver excellence.</p>
            </div>

            <div class="sw-grid">
                <!-- ERP Systems -->
                <div class="sw-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="sw-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                    </div>
                    <h3>Enterprise ERP</h3>
                    <p>Comprehensive systems to manage business processes, inventory, finance, and human resources in one unified platform.</p>
                    <ul class="sw-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Centralized Data</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Real-time Analytics</li>
                    </ul>
                </div>

                <!-- CRM Solutions -->
                <div class="sw-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="sw-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <h3>Custom CRM</h3>
                    <p>Build stronger relationships with your customers through tailored contact management and sales automation tools.</p>
                    <ul class="sw-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Sales Tracking</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Automated Outreach</li>
                    </ul>
                </div>

                <!-- School Management -->
                <div class="sw-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="sw-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                    </div>
                    <h3>Edu-Manage Systems</h3>
                    <p>End-to-end solutions for educational institutions, covering admissions, grading, attendance, and fee management.</p>
                    <ul class="sw-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Parent Portals</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Exam Management</li>
                    </ul>
                </div>

                <!-- POS Systems -->
                <div class="sw-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="sw-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    </div>
                    <h3>Smart POS</h3>
                    <p>Modern point-of-sale systems for retail and hospitality, featuring inventory sync and multi-payment support.</p>
                    <ul class="sw-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Offline Mode</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Multi-store Sync</li>
                    </ul>
                </div>

                <!-- Hospital Management -->
                <div class="sw-card" data-aos="fade-up" data-aos-delay="500">
                    <div class="sw-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>
                    </div>
                    <h3>HealthConnect</h3>
                    <p>Secure patient records, appointment scheduling, and billing systems for clinics and large medical centers.</p>
                    <ul class="sw-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Secure EMR</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Pharmacy Integration</li>
                    </ul>
                </div>

                <!-- HR & Payroll -->
                <div class="sw-card" data-aos="fade-up" data-aos-delay="600">
                    <div class="sw-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
                    </div>
                    <h3>PeopleForce HRM</h3>
                    <p>Automate payroll, leave management, and employee performance tracking with high precision and security.</p>
                    <ul class="sw-card-features">
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Automated Payroll</li>
                        <li><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> KRA Compliance</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Key Capabilities -->
    <section class="sw-features-section">
        <div class="container">
            <div class="sw-features-row" data-aos="fade-right">
                <div class="sw-feature-content">
                    <span class="section-tag" style="text-align: left;">High Performance</span>
                    <h2>Cloud-Native <span>Architecture</span></h2>
                    <p>Our software is built for the modern world. We leverage cloud-native technologies to ensure your applications are accessible, scalable, and always online.</p>
                    <ul class="feature-list-modern">
                        <li>Microservices structure for high reliability</li>
                        <li>AWS / Azure / Google Cloud integration</li>
                        <li>Seamless cross-device synchronization</li>
                    </ul>
                </div>
                <div class="sw-feature-image">
                    <svg width="200" height="200" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17.5 19c2.5 0 4.5-2 4.5-4.5 0-2-1.5-3.5-3.5-4 0-4-3-7-7-7-3 0-5.5 2-6.5 5C3 8.5 2 10 2 12c0 2.5 2 4.5 4.5 4.5" stroke="var(--sw-accent)" stroke-width="0.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M12 13V21M12 21L9 18M12 21L15 18" stroke="var(--sw-accent)" stroke-width="0.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>

            <div class="sw-features-row" data-aos="fade-left">
                <div class="sw-feature-content">
                    <span class="section-tag" style="text-align: left;">Bank-Grade</span>
                    <h2>Security-First <span>Approach</span></h2>
                    <p>Security is not an afterthought. We implement multi-layered encryption and rigorous testing to protect your sensitive business and customer data.</p>
                    <ul class="feature-list-modern">
                        <li>End-to-end data encryption</li>
                        <li>Regular security audits and updates</li>
                        <li>Compliance with local & international standards</li>
                    </ul>
                </div>
                <div class="sw-feature-image">
                    <svg width="200" height="200" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" stroke="var(--sw-accent)" stroke-width="0.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="m9 12 2 2 4-4" stroke="var(--sw-accent)" stroke-width="0.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>

    <!-- Development Process -->
    <section class="sw-process">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Workflow</span>
                <h2 class="section-title-modern">Our <span>Build Process</span></h2>
                <p>We follow a disciplined, agile methodology to ensure timely delivery and superior quality.</p>
            </div>

            <div class="process-steps">
                <div class="step-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="step-number">01</div>
                    <h4>Discovery</h4>
                    <p>Analyzing requirements and business goals.</p>
                </div>
                <div class="step-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="step-number">02</div>
                    <h4>Design</h4>
                    <p>Architecting systems and user interfaces.</p>
                </div>
                <div class="step-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="step-number">03</div>
                    <h4>Development</h4>
                    <p>Writing clean, efficient, and scalable code.</p>
                </div>
                <div class="step-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="step-number">04</div>
                    <h4>QA Testing</h4>
                    <p>Rigorous bug-fixing and performance testing.</p>
                </div>
                <div class="step-card" data-aos="fade-up" data-aos-delay="500">
                    <div class="step-number">05</div>
                    <h4>Deployment</h4>
                    <p>Launching and continuous maintenance.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Support/CTA Section -->
    <section class="hosting-support">
        <div class="container">
            <div class="support-box" data-aos="zoom-in">
                <div class="support-content">
                    <span class="section-tag" style="text-align: left;">Start Today</span>
                    <h2 style="color: #fff;">Got a Custom <span>Software Idea?</span></h2>
                    <p>We have the expertise to turn your vision into a powerful reality. Let's discuss your project today.</p>
                    <a href="contact.php" class="btn btn-primary">Schedule a Consultation</a>
                </div>
                <div class="support-visual">
                    <svg width="200" height="200" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="2" y="3" width="20" height="14" rx="2" stroke="var(--sw-accent)" stroke-width="0.1" opacity="0.3"/>
                        <path d="M6 21h12M12 17v4" stroke="var(--sw-accent)" stroke-width="0.5" stroke-linecap="round"/>
                        <circle cx="12" cy="10" r="3" stroke="var(--sw-accent)" stroke-width="0.5"/>
                    </svg>
                </div>
            </div>
        </div>
    </section>
</main>
<?php $ctaService = 'Software Development'; include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
