<?php
require_once __DIR__ . '/asset.php';
require_once __DIR__ . '/brand.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Who we are and where we answer, from the one place that holds them.
$_brand = site_brand();

// Per-page SEO defaults — pages override by setting $pageSEO before including this file
$_seo = array_merge([
    'title'       => 'Shanfix Technology | Premier IT Solutions & Digital Services in Nairobi',
    'description' => 'Shanfix Technology is a leading IT solutions provider in Nairobi, Kenya, specializing in Web Development, Software Solutions, Bulk SMS, SEO, Networking, Printing & Branding, and Event Management.',
    'keywords'    => 'web development, system development, bulk SMS, SEO, networking, printing, branding, event management, Nairobi, Kenya, Shanfix Technology',
    'og_title'    => 'Shanfix Technology | Premier IT Solutions & Digital Services',
    'og_desc'     => 'Innovating your digital future with expert Web Development, Software Solutions, and Branding services in Nairobi, Kenya.',
    'og_image'    => '{{base}}/assets/og-image.png',
    'og_type'     => 'website',
    'canonical'   => '{{base}}/',
], $pageSEO ?? []);
$_seo['og_title'] = $pageSEO['og_title'] ?? $pageSEO['title'] ?? $_seo['og_title'];
$_seo['og_desc']  = $pageSEO['og_desc']  ?? $pageSEO['description'] ?? $_seo['og_desc'];

// Pages name themselves from the outside — canonical, Open Graph image,
// the url in their structured data — and every one of them used to spell
// the domain out in full. Thirty-one files held a copy, so moving the
// site to another address, or testing it at one, meant every page still
// pointed search engines at the old one.
//
// They write {{base}} now and it is expanded here, once, from the single
// setting. Done over the whole array rather than field by field so a page
// adding an SEO field of its own gets it without anything being changed
// here.
foreach ($_seo as $_k => $_v) {
    if (is_string($_v)) {
        $_seo[$_k] = str_replace('{{base}}', rtrim(site_url(), '/'), $_v);
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!-- Primary Meta Tags -->
    <title><?= htmlspecialchars($_seo['title']) ?></title>
    <meta name="title" content="<?= htmlspecialchars($_seo['title']) ?>" />
    <meta name="description" content="<?= htmlspecialchars($_seo['description']) ?>" />
    <meta name="keywords" content="<?= htmlspecialchars($_seo['keywords']) ?>" />
    <meta name="author" content="<?= htmlspecialchars($_brand['name']) ?>" />
    <link rel="canonical" href="<?= htmlspecialchars($_seo['canonical']) ?>" />

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="icon" type="image/x-icon" href="/favicon.ico" />
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/icons/favicon-16x16.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/icons/apple-touch-icon.png" />

    <!-- PWA -->
    <link rel="manifest" href="/manifest.json" />
    <meta name="theme-color" content="#22c55e" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="Shanfix" />
    <meta name="application-name" content="Shanfix Technology" />
    <meta name="msapplication-TileColor" content="#1e293b" />
    <meta name="msapplication-TileImage" content="/assets/icons/icon-192x192.png" />

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="<?= htmlspecialchars($_seo['og_type']) ?>" />
    <meta property="og:url" content="<?= htmlspecialchars($_seo['canonical']) ?>" />
    <meta property="og:title" content="<?= htmlspecialchars($_seo['og_title']) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars($_seo['og_desc']) ?>" />
    <meta property="og:image" content="<?= htmlspecialchars($_seo['og_image']) ?>" />

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image" />
    <meta property="twitter:url" content="<?= htmlspecialchars($_seo['canonical']) ?>" />
    <meta property="twitter:title" content="<?= htmlspecialchars($_seo['og_title']) ?>" />
    <meta property="twitter:description" content="<?= htmlspecialchars($_seo['og_desc']) ?>" />
    <meta property="twitter:image" content="<?= htmlspecialchars($_seo['og_image']) ?>" />

    <!-- JSON-LD: Local Business -->
    <?php // Structured data, so a search result can carry the phone
          // number and the address rather than only a blue link. Built
          // from the brand rather than typed out, because a phone number
          // that is right on the page and stale in here is worse than
          // having none: this is the copy Google reads aloud. ?>
    <script type="application/ld+json">
    <?= json_encode([
        '@context'    => 'https://schema.org',
        '@type'       => 'LocalBusiness',
        'name'        => $_brand['name'],
        'url'         => site_url(),
        'logo'        => site_url('assets/shanfix-logo.png'),
        'image'       => site_url('assets/og-image.png'),
        'description' => 'Premier IT solutions provider in Nairobi, Kenya — web development, software, networking, digital marketing, printing, and event management.',
        'telephone'   => $_brand['phone_tel'],
        'email'       => $_brand['email'],
        // The town is only named when the address said which part of
        // it is the town. An empty addressLocality is not neutral: it is
        // a claim that the business is in nowhere.
        'address'     => array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => $_brand['street'],
            'addressLocality' => $_brand['city'],
            'addressCountry'  => $_brand['country'],
        ], static fn ($v) => $v !== ''),
        'geo' => [
            '@type'     => 'GeoCoordinates',
            'latitude'  => -1.3224,
            'longitude' => 36.7092,
        ],
        'openingHoursSpecification' => [[
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'opens'     => '08:00',
            'closes'    => '17:00',
        ]],
        'sameAs' => [
            'https://sms.shanfixtechnology.com/',
            'https://aicoder.shanfixtechnology.com/',
            'https://automation.shanfixtechnology.com/',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
    </script><?php // The page's own structured data, taken from $_seo rather
                   // than $pageSEO so that {{base}} has been expanded. ?><?php if (!empty($_seo['json_ld'])): ?>
    <script type="application/ld+json"><?= $_seo['json_ld'] ?></script><?php endif; ?>
    <link rel="stylesheet" href="<?= site_asset('./index.css') ?>" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap"
      rel="stylesheet"
    />

    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!--Start of Tawk.to Script-->
    <script type="text/javascript">
    var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
    (function(){
    var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
    s1.async=true;
    s1.src='https://embed.tawk.to/69f1040841473c1c31d84248/1jnanfh6l';
    s1.charset='UTF-8';
    s1.setAttribute('crossorigin','*');
    s0.parentNode.insertBefore(s1,s0);
    })();
    </script>
    <!--End of Tawk.to Script-->
  </head>
  <body>
    <header class="main-header">
      <!-- Top Header Bar -->
      <div class="top-header">
      <div class="container top-header-container">
        <div class="top-header-left">
          <a href="tel:<?= htmlspecialchars($_brand['phone_tel']) ?>" class="top-header-item">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
            </svg>
            <span><?= htmlspecialchars($_brand['phone']) ?></span>
          </a>
          <a href="mailto:<?= htmlspecialchars($_brand['email']) ?>" class="top-header-item">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
              <polyline points="22,6 12,13 2,6"></polyline>
            </svg>
            <span><?= htmlspecialchars($_brand['email']) ?></span>
          </a>
          <span class="top-header-item">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
            <span><?= htmlspecialchars($_brand['address']) ?></span>
          </span>
        </div>
        <div class="top-header-right">
          <a href="https://www.kaki.co.ke" target="_blank" rel="noopener noreferrer" class="top-header-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
              <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
            </svg>
            <span>Computer Repair / Shop</span>
          </a>
          <?php // One client portal, and it is the system's. The site used
                // to run its own beside it, which meant two passwords and
                // two places a customer's invoices could disagree. ?>
          <a href="/portal/login" class="top-header-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
              <circle cx="12" cy="7" r="4"></circle>
            </svg>
            <span>Client Login</span>
          </a>
          <a href="https://sms.shanfixtechnology.com/" target="_blank" rel="noopener noreferrer" class="top-header-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
            <span>Bulk SMS Login</span>
          </a>
        </div>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar" id="navbar">
      <div class="container nav-container">
        <div class="logo" onclick="window.location.href='index.php'" style="cursor: pointer;">
          <img src="assets/shanfix-logo.png" alt="Shanfix Technology Logo" class="logo-image">
        </div>
        <ul class="nav-menu" id="navMenu">
          <li><a href="index.php" class="nav-link">Home</a></li>
          <li><a href="who-we-are.php" class="nav-link">About Us</a></li>
          <li class="nav-item-dropdown">
            <a href="index.php#services" class="nav-link">
              Our Services
              <svg class="dropdown-arrow" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
              </svg>
            </a>
            <div class="mega-menu">
              <div class="mega-menu-content">
                <!-- Column 1: IT & Systems -->
                <div class="mega-menu-column">
                  <h5 class="mega-menu-category-title">IT & Development</h5>
                  <a href="web-development.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="14" rx="2"/><path d="M2 8h20M7 12l3 3-3 3M13 18h4"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Web Development</h4>
                      <p>Custom sites & web apps</p>
                    </div>
                  </a>
                  <a href="app-development.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>App Development</h4>
                      <p>iOS & Android solutions</p>
                    </div>
                  </a>
                  <a href="software-solution.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="6" width="16" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M12 9v-3M12 18v-3M9 12H4M20 12h-5"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Software Solution</h4>
                      <p>Custom system development</p>
                    </div>
                  </a>
                  <a href="web-hosting.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="5" rx="1"/><rect x="3" y="11" width="18" height="5" rx="1"/><rect x="3" y="18" width="18" height="2" rx="1"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Web Hosting</h4>
                      <p>Secure & reliable servers</p>
                    </div>
                  </a>
                  <a href="networking-solution.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M11 7L7 17M13 7l4 10M7 19h10"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Networking</h4>
                      <p>IT infrastructure setup</p>
                    </div>
                  </a>
                </div>

                <!-- Column 2: Marketing & Comms -->
                <div class="mega-menu-column">
                  <h5 class="mega-menu-category-title">Marketing & Comms</h5>
                  <a href="digital-marketing.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8l7 4-7 4V8zM10 4l11 8-10 8V4z"/><circle cx="17" cy="8" r="2"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Digital Marketing</h4>
                      <p>SEO & Social media growth</p>
                    </div>
                  </a>
                  <a href="seo-boost.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>SEO Boost</h4>
                      <p>Search engine optimization</p>
                    </div>
                  </a>
                  <a href="bulk-sms.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Bulk SMS</h4>
                      <p>Mass outreach solutions</p>
                    </div>
                  </a>
                  <a href="graphics-design.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Graphics Design</h4>
                      <p>Creative brand identity</p>
                    </div>
                  </a>
                  <a href="consultancy.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Consultancy</h4>
                      <p>Expert technology advice</p>
                    </div>
                  </a>
                </div>

                <!-- Column 3: Creative & Physical -->
                <div class="mega-menu-column">
                  <h5 class="mega-menu-category-title">Creative & Events</h5>
                  <a href="printing-branding.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Printing & Branding</h4>
                      <p>High-quality print assets</p>
                    </div>
                  </a>
                  <a href="signage-solution.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>3D & 2D Signages</h4>
                      <p>Eye-catching business signs</p>
                    </div>
                  </a>
                  <a href="event-management.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Event Management</h4>
                      <p>Corporate event planning</p>
                    </div>
                  </a>
                  <a href="event-ticketing.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v2z"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Event Ticketing</h4>
                      <p>Seamless ticket solutions</p>
                    </div>
                  </a>
                </div>
              </div>
            </div>
          </li>
          <li class="nav-item-dropdown">
            <a href="software-solution.php" class="nav-link">
              Our Software
              <svg class="dropdown-arrow" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
              </svg>
            </a>
            <div class="mega-menu">
              <div class="mega-menu-content">
                <!-- Column 1: Retail & Enterprise -->
                <div class="mega-menu-column">
                  <h5 class="mega-menu-category-title">Retail & Enterprise</h5>
                  <a href="pos-solution.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Point of Sales</h4>
                      <p>Retail & Hospitality POS</p>
                    </div>
                  </a>
                  <a href="erp-solution.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/><path d="M3 9h6M15 9h6M9 3v6M9 15v6"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Business ERP</h4>
                      <p>Full business integration</p>
                    </div>
                  </a>
                  <a href="software-solution.php#accounting" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="16" y2="14"/><line x1="8" y1="18" x2="16" y2="18"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Accounting System</h4>
                      <p>Financial tracking & reports</p>
                    </div>
                  </a>
                  <a href="software-solution.php#hardware" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Hardware Management</h4>
                      <p>Specialized inventory POS</p>
                    </div>
                  </a>
                  <a href="software-solution.php#crm" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>CRM</h4>
                      <p>Client relationship tools</p>
                    </div>
                  </a>
                </div>

                <!-- Column 2: Specialized Systems -->
                <div class="mega-menu-column">
                  <h5 class="mega-menu-category-title">Specialized Systems</h5>
                  <a href="school-management.php" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>School Management</h4>
                      <p>Academic institution ERP</p>
                    </div>
                  </a>
                  <a href="software-solution.php#hospital" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Hospital Management</h4>
                      <p>Healthcare records & billing</p>
                    </div>
                  </a>
                  <a href="software-solution.php#rental" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Rental Management</h4>
                      <p>Property & rental tracking</p>
                    </div>
                  </a>
                  <a href="software-solution.php#caryard" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Car Yard Management</h4>
                      <p>Inventory & sales tracking</p>
                    </div>
                  </a>
                </div>

                <!-- Column 3: Finance & Operations -->
                <div class="mega-menu-column">
                  <h5 class="mega-menu-category-title">Finance & Operations</h5>
                  <a href="software-solution.php#sacco" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>SACCO Management</h4>
                      <p>Savings & credit tools</p>
                    </div>
                  </a>
                  <a href="software-solution.php#microfinance" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Microfinance System</h4>
                      <p>Loan & interest tracking</p>
                    </div>
                  </a>
                  <a href="software-solution.php#hrm" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>HRM</h4>
                      <p>Employee & payroll management</p>
                    </div>
                  </a>
                  <a href="software-solution.php#transport" class="mega-menu-item">
                    <div class="mega-menu-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polyline points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    </div>
                    <div class="mega-menu-text">
                      <h4>Transport Management</h4>
                      <p>Logistics & fleet tracking</p>
                    </div>
                  </a>
                </div>
              </div>
            </div>
          </li>

          <li><a href="portfolio.php" class="nav-link">Portfolio</a></li>
          <li><a href="blog.php" class="nav-link">Blog</a></li>
          <li><a href="contact.php" class="nav-link">Contacts</a></li>
          <li><a href="https://aicoder.shanfixtechnology.com/" class="btn btn-primary nav-btn-small">AI Coder</a></li>
          <li><a href="https://automation.shanfixtechnology.com/" class="btn btn-secondary nav-btn-small">AI Automations</a></li>

          <?php // The way into the business system that sits behind this
                // site. One link rather than three, because /signin asks
                // which of the three you are and none of the forms works
                // in the others — a customer landing on the staff form has
                // no way of telling why their password is refused. ?>
          <li>
            <a href="/signin" class="btn btn-secondary nav-btn-small" rel="nofollow">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                   stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"
                   aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>Sign in
            </a>
          </li>

        </ul>
        <button
          class="mobile-menu-toggle"
          id="mobileMenuToggle"
          aria-label="Toggle menu"
        >
          <span></span>
          <span></span>
          <span></span>
        </button>
      </div>
    </nav>
    </header>