<?php
$pageSEO = [
    'title'       => 'School Management System in Kenya | Academic ERP - Shanfix Technology',
    'description' => 'Complete school management system for Kenyan schools — student records, fees collection, timetables, exams, and parent portals by Shanfix Technology.',
    'keywords'    => 'school management system Kenya, academic ERP, student management, school software',
    'canonical'   => '{{base}}/school-management.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"School Management System","description":"End-to-end school management for Kenyan schools — student records, fees, timetables, exams, and parent portals.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"{{base}}/"},"areaServed":{"@type":"Country","name":"Kenya"},"url":"{{base}}/school-management.php"}',
];
include 'includes/header.php'; ?>
<link rel="stylesheet" href="<?= site_asset('school-modern.css') ?>">

<main class="school-modern-page">
    <!-- Hero Section -->
    <section class="school-hero">
        <div class="container">
            <div class="school-hero-content">
                <span class="section-tag" data-aos="fade-up" style="background: rgba(89, 224, 10, 0.2); color: var(--school-brand-accent);">Digital Education Era</span>
                <h1 data-aos="fade-up" data-aos-delay="100">Intelligent <span>School Management</span> Ecosystem</h1>
                <p data-aos="fade-up" data-aos-delay="200">The most advanced platform in East Africa for automating academic workflows, financial management, and student lifecycle tracking in one unified environment.</p>
                <div class="hero-btns" data-aos="fade-up" data-aos-delay="300">
                    <a href="https://pos.shanfixtechnology.com/" class="btn-brand-green">Portal Login</a>
                    <a href="https://pos.shanfixtechnology.com/register.php" class="btn-brand-outline">Register Institution</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Importance Section -->
    <section class="school-grid-section">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">Core Value</span>
                <h2 class="section-title-modern">Why Choose <span>Digital Transformation?</span></h2>
            </div>

            <div class="school-card-grid">
                <!-- Data Security -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="card-icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
                    </div>
                    <h3>Immutable Records</h3>
                    <p>Protect student academic history and financial records with enterprise-grade encryption and automated cloud backups.</p>
                </div>

                <!-- Real-time Control -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="card-icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    </div>
                    <h3>Operational Control</h3>
                    <p>Monitor attendance, disciplinary actions, and staff performance in real-time from a single administrative dashboard.</p>
                </div>

                <!-- Cost Efficiency -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="card-icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <h3>Financial Optimization</h3>
                    <p>Reduce overhead costs by eliminating paper-based processes and automating fee collection and payroll management.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Key Benefits Section -->
    <section class="school-grid-section" style="background: var(--school-bg-alt);">
        <div class="container">
            <div class="section-header-modern" data-aos="fade-up">
                <span class="section-tag">System Capabilities</span>
                <h2 class="section-title-modern">Integrated <span>Platform Benefits</span></h2>
            </div>

            <div class="school-card-grid">
                <!-- Academic Management -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="card-icon-box" style="background: var(--school-brand-green); color: #fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                    </div>
                    <h3>Academic Excellence</h3>
                    <p>Dynamic timetable generation, digital curriculum planning, and specialized module for online learning and assignments.</p>
                </div>

                <!-- Attendance -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="card-icon-box" style="background: var(--school-brand-green); color: #fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <h3>Smart Attendance</h3>
                    <p>Real-time attendance tracking for students and staff with biometric integration and automated SMS alerts to parents for absentees.</p>
                </div>

                <!-- Exams & Grading -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="card-icon-box" style="background: var(--school-brand-green); color: #fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    <h3>Exam & Grading</h3>
                    <p>Automated result processing, weighted grading systems, and instant digital report card generation with historical performance tracking.</p>
                </div>

                <!-- Fee Management -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="card-icon-box" style="background: var(--school-brand-green); color: #fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polyline points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    </div>
                    <h3>Fee & Billing</h3>
                    <p>Unified fee collection via M-Pesa, Bank, and Cards. Includes automated invoicing, fine management, and partial payment tracking.</p>
                </div>

                <!-- Library -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="500">
                    <div class="card-icon-box" style="background: var(--school-brand-green); color: #fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    </div>
                    <h3>Library Management</h3>
                    <p>Comprehensive digital cataloging, barcode integration for book issuance, and automated fine calculation for overdue items.</p>
                </div>

                <!-- Transport -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="600">
                    <div class="card-icon-box" style="background: var(--school-brand-green); color: #fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-1.1 0-2 .9-2 2v7h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                    </div>
                    <h3>Fleet & Transport</h3>
                    <p>Real-time vehicle tracking, route optimization, and automated notifications to parents when the bus is near the pickup point.</p>
                </div>

                <!-- Staff & Payroll -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="700">
                    <div class="card-icon-box" style="background: var(--school-brand-green); color: #fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <h3>Staff & HR</h3>
                    <p>Automated payroll management, leave tracking, performance evaluations, and specialized recruitment module for academic staff.</p>
                </div>

                <!-- Communication -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="800">
                    <div class="card-icon-box" style="background: var(--school-brand-green); color: #fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <h3>Communication Hub</h3>
                    <p>Omni-channel messaging via SMS, Email, and WhatsApp for instant alerts on fee balances, holidays, and student emergencies.</p>
                </div>

                <!-- Inventory -->
                <div class="school-feature-card" data-aos="fade-up" data-aos-delay="900">
                    <div class="card-icon-box" style="background: var(--school-brand-green); color: #fff;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                    </div>
                    <h3>Asset & Inventory</h3>
                    <p>Track school assets, laboratory equipment, and stationery stock levels with automated low-stock notifications and vendor management.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Reseller Program Section -->
    <section class="reseller-banner">
        <div class="container">
            <div class="reseller-content-box" data-aos="zoom-in">
                <div class="reseller-info">
                    <span class="section-tag" style="background: rgba(89, 224, 10, 0.2); color: var(--school-brand-accent);">Growth Opportunity</span>
                    <h2>Become a <span>Shanfix Partner</span></h2>
                    <p>Scale your IT business by becoming an authorized reseller of our School Management System. We provide the infrastructure; you provide the local service.</p>
                    
                    <ul class="reseller-features">
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            High-Margin Recurring Commissions
                        </li>
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            Full Technical & Marketing Support
                        </li>
                        <li>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            Whitelabeling Options Available
                        </li>
                    </ul>

                    <a href="contact.php" class="btn-brand-green" style="background: var(--school-brand-accent); color: var(--school-brand-dark);">Join Reseller Program</a>
                </div>
                
                <div class="reseller-visual" style="flex: 0.8;">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="var(--school-brand-accent)" stroke-width="0.5">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                </div>
            </div>
        </div>
    </section>

    <!-- Final CTA -->
    <section class="school-grid-section" style="text-align: center; border-top: 1px solid #f3f4f6;">
        <div class="container" data-aos="fade-up">
            <h2 style="font-size: 3rem; font-weight: 800; margin-bottom: 1.5rem; color: var(--school-brand-dark);">Empower Your <span>Academic Institution</span></h2>
            <p style="font-size: 1.2rem; color: var(--school-text-muted); max-width: 600px; margin: 0 auto 3rem;">Join hundreds of schools across the region using Shanfix to lead the digital revolution in education.</p>
            <div class="hero-btns">
                <a href="https://pos.shanfixtechnology.com/register.php" class="btn-brand-green">Get Started Now</a>
                <a href="contact.php" class="btn btn-secondary" style="border: 2px solid var(--school-brand-dark); color: var(--school-brand-dark);">Consult an Expert</a>
            </div>
        </div>
    </section>
</main>

<?php $ctaService = 'School Management System'; include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
