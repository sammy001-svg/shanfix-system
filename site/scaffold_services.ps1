
$services = @(
  @{ Title = "App Development"; Filename = "app-development.html"; Image = "assets/App-development-jpeg.jpg"; Desc = "Custom mobile applications for iOS and Android platforms." },
  @{ Title = "Web Development"; Filename = "web-development.html"; Image = "assets/web-development-jpeg.jpg"; Desc = "Modern, responsive websites that drive results and engage users." },
  @{ Title = "Web Hosting"; Filename = "web-hosting.html"; Image = "assets/web-hosting-jpeg.jpg"; Desc = "Reliable, secure, and fast hosting solutions for your business." },
  @{ Title = "Software Solution"; Filename = "software-solution.html"; Image = "assets/Software-solution-jpeg.jpg"; Desc = "Tailored software to streamline your operations and efficiency." },
  @{ Title = "Networking Solution"; Filename = "networking-solution.html"; Image = "assets/networking-solution-jpeg.jpg"; Desc = "Robust network infrastructure setup, maintenance, and security." },
  @{ Title = "Digital Marketing"; Filename = "digital-marketing.html"; Image = "assets/digital-marketing-jpeg.jpg"; Desc = "Strategic digital marketing to grow your brand's online presence." },
  @{ Title = "Bulk SMS"; Filename = "bulk-sms.html"; Image = "assets/Bulk-sms-jpeg.jpg"; Desc = "Effective mass communication tools for instant customer outreach." },
  @{ Title = "SEO Boost"; Filename = "seo-boost.html"; Image = "assets/SEO-boost-jpeg.jpg"; Desc = "Optimize your site to rank higher on search engines and drive traffic." },
  @{ Title = "Event Management"; Filename = "event-management.html"; Image = "assets/event-management-jpeg.jpg"; Desc = "Professional planning for memorable corporate and social events." },
  @{ Title = "Event Ticketing"; Filename = "event-ticketing.html"; Image = "assets/event-ticketing-jpeg.jpg"; Desc = "Seamless, secure, and easy-to-use ticketing solutions for your events." },
  @{ Title = "Printing & Branding"; Filename = "printing-branding.html"; Image = "assets/printing-brabdibg-jpeg.jpg"; Desc = "High-quality print materials and comprehensive brand asset creation." },
  @{ Title = "Consultancy"; Filename = "consultancy.html"; Image = "assets/service-4.jpg"; Desc = "Expert technology advice to guide your strategic business decisions." }
)

$specialPages = @(
  @{ Title = "Portfolio"; Filename = "portfolio.html"; Image = "assets/web-development-jpeg.jpg"; Desc = "Explore our recent projects and success stories." }
)

$header = @"
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Shanfix Technology | SERVICE_TITLE</title>
    <link rel="stylesheet" href="./index.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap"
      rel="stylesheet"
    />
    <style>
        .service-hero {
            height: 60vh;
            background-size: cover;
            background-position: center;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-align: center;
        }
        .service-hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
        }
        .service-hero-content {
            position: relative;
            z-index: 2;
        }
        .service-hero h1 {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
        }
        .service-detail-section {
            padding: 80px 0;
        }
        .service-main {
            width: 100%;
        }
    </style>
  </head>
  <body>
    <!-- Top Header Bar -->
    <div class="top-header">
      <div class="container top-header-container">
        <div class="top-header-left">
          <a href="tel:+254751869165" class="top-header-item">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
            </svg>
            <span>+254 751 869 165</span>
          </a>
          <a href="mailto:info@shanfixtechnology.com" class="top-header-item">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
              <polyline points="22,6 12,13 2,6"></polyline>
            </svg>
            <span>info@shanfixtechnology.com</span>
          </a>
          <span class="top-header-item">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
            <span>Tana House, Karen - Nairobi</span>
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
          <a href="#login" class="top-header-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
              <circle cx="12" cy="7" r="4"></circle>
            </svg>
            <span>Login</span>
          </a>
        </div>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar" id="navbar">
      <div class="container nav-container">
        <div class="logo">
          <a href="index.html">
            <img src="assets/shanfix-logo.png" alt="Shanfix Technology Logo" class="logo-image">
          </a>
        </div>
        <ul class="nav-menu" id="navMenu">
          <li><a href="index.html" class="nav-link">Home</a></li>
          <li class="nav-item-dropdown">
            <a href="index.html#about" class="nav-link">
              About Us
              <svg class="dropdown-arrow" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
              </svg>
            </a>
            <div class="mega-menu">
              <div class="mega-menu-content">
                <a href="who-we-are.html" class="mega-menu-item">
                  <div class="mega-menu-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                      <circle cx="9" cy="7" r="4"></circle>
                      <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                      <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                  </div>
                  <div class="mega-menu-text">
                    <h4>Who We Are</h4>
                    <p>Our Mission, Vision & Team</p>
                  </div>
                </a>
                <a href="portfolio.html" class="mega-menu-item">
                  <div class="mega-menu-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                      <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                    </svg>
                  </div>
                  <div class="mega-menu-text">
                    <h4>Portfolio</h4>
                    <p>Showcase of Our Recent Work</p>
                  </div>
                </a>
              </div>
            </div>
          </li>
          <li class="nav-item-dropdown">
            <a href="index.html#services" class="nav-link">
              Our Services
              <svg class="dropdown-arrow" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
              </svg>
            </a>
             <div class="mega-menu">
              <div class="mega-menu-content">
                <a href="web-development.html" class="mega-menu-item">
                  <div class="mega-menu-icon">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <rect x="2" y="4" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/>
                      <path d="M2 8 H22" stroke="currentColor" stroke-width="2"/>
                      <path d="M7 12 L10 15 L7 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                      <path d="M13 18 H17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                  </div>
                  <div class="mega-menu-text">
                    <h4>Web Development</h4>
                    <p>Custom websites & web applications</p>
                  </div>
                </a>
                <a href="web-hosting.html" class="mega-menu-item">
                  <div class="mega-menu-icon">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <rect x="3" y="4" width="18" height="5" rx="1" stroke="currentColor" stroke-width="2"/>
                      <rect x="3" y="11" width="18" height="5" rx="1" stroke="currentColor" stroke-width="2"/>
                      <rect x="3" y="18" width="18" height="2" rx="1" stroke="currentColor" stroke-width="2"/>
                      <circle cx="6" cy="6.5" r="0.5" fill="currentColor"/>
                      <circle cx="8" cy="6.5" r="0.5" fill="currentColor"/>
                      <circle cx="6" cy="13.5" r="0.5" fill="currentColor"/>
                      <circle cx="8" cy="13.5" r="0.5" fill="currentColor"/>
                    </svg>
                  </div>
                  <div class="mega-menu-text">
                    <h4>Web Hosting</h4>
                    <p>Reliable & secure hosting solutions</p>
                  </div>
                </a>
                <a href="software-solution.html" class="mega-menu-item">
                  <div class="mega-menu-icon">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <rect x="4" y="6" width="16" height="12" rx="2" stroke="currentColor" stroke-width="2"/>
                      <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                      <path d="M12 9 V6" stroke="currentColor" stroke-width="2"/>
                      <path d="M12 18 V15" stroke="currentColor" stroke-width="2"/>
                      <path d="M9 12 H4" stroke="currentColor" stroke-width="2"/>
                      <path d="M20 12 H15" stroke="currentColor" stroke-width="2"/>
                    </svg>
                  </div>
                  <div class="mega-menu-text">
                    <h4>Software Solution</h4>
                    <p>Custom software & system development</p>
                  </div>
                </a>
                <a href="digital-marketing.html" class="mega-menu-item">
                  <div class="mega-menu-icon">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path d="M3 8 L10 12 L3 16 V8Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                      <path d="M10 4 L21 12 L10 20 V4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                      <circle cx="17" cy="8" r="2" stroke="currentColor" stroke-width="2"/>
                    </svg>
                  </div>
                  <div class="mega-menu-text">
                    <h4>Digital Marketing</h4>
                    <p>SEO, social media & online advertising</p>
                  </div>
                </a>
                <a href="networking-solution.html" class="mega-menu-item">
                  <div class="mega-menu-icon">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <circle cx="12" cy="5" r="2" stroke="currentColor" stroke-width="2"/>
                      <circle cx="5" cy="19" r="2" stroke="currentColor" stroke-width="2"/>
                      <circle cx="19" cy="19" r="2" stroke="currentColor" stroke-width="2"/>
                      <path d="M11 7 L7 17" stroke="currentColor" stroke-width="2"/>
                      <path d="M13 7 L17 17" stroke="currentColor" stroke-width="2"/>
                      <path d="M7 19 H17" stroke="currentColor" stroke-width="2"/>
                    </svg>
                  </div>
                  <div class="mega-menu-text">
                    <h4>Networking</h4>
                    <p>Network setup & IT infrastructure</p>
                  </div>
                </a>
              </div>
            </div>
          </li>
          <li class="nav-item-dropdown">
            <a href="printing-branding.html" class="nav-link">
              Printing & Branding
              <svg class="dropdown-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </a>
            <div class="mega-menu">
              <div class="mega-menu-content">
                <a href="printing-branding.html" class="mega-menu-item">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                  </svg>
                  <div>
                    <h4>Printing & Branding</h4>
                    <p>Professional printing services and brand materials</p>
                  </div>
                </a>
                <a href="printing-branding.html" class="mega-menu-item">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                  </svg>
                  <div>
                    <h4>Graphics Design</h4>
                    <p>Creative design solutions for your brand identity</p>
                  </div>
                </a>
                <a href="printing-branding.html" class="mega-menu-item">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3h18v18H3z"></path>
                    <path d="M9 9h6v6H9z"></path>
                  </svg>
                  <div>
                    <h4>3D & 2D Signages</h4>
                    <p>Eye-catching signage solutions for your business</p>
                  </div>
                </a>
              </div>
            </div>
          </li>
          <li><a href="event-management.html" class="nav-link">Event Solution</a></li>
          <li><a href="contact.html" class="nav-link">Contacts</a></li>
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
"@

$footer = @"
    <!-- Footer -->
    <footer class="footer">
      <div class="container">
        <div class="footer-grid">
          <div class="footer-col">
            <h4 class="footer-title">Shanfix Technology</h4>
            <p class="footer-text">
              Your trusted partner for innovative technology and business solutions in Nairobi, Kenya.
            </p>
          </div>
          <div class="footer-col">
            <h4 class="footer-title">Quick Links</h4>
            <ul class="footer-links">
              <li><a href="index.html">Home</a></li>
              <li><a href="index.html#about">About Us</a></li>
              <li><a href="index.html#services">Services</a></li>
              <li><a href="contact.html">Contact</a></li>
            </ul>
          </div>
          <div class="footer-col">
            <h4 class="footer-title">Contact</h4>
            <ul class="footer-contact">
              <li>Tana House, Karen, Nairobi</li>
              <li>+254 751 869 165</li>
              <li>info@shanfixtechnology.com</li>
            </ul>
          </div>
        </div>
        <div class="footer-bottom">
          <p>&copy; 2024 Shanfix Technology. All rights reserved.</p>
        </div>
      </div>
    </footer>
    <script src="main.js"></script>
  </body>
</html>
"@

function New-Page {
  param(
    [string]$Title,
    [string]$Filename,
    [string]$Image,
    [string]$Desc
  )

  $content = $header.Replace("SERVICE_TITLE", $Title)
    
  $heroClass = "service-hero"
  if ($Filename -eq "portfolio.html") { $heroClass += " portfolio-hero" }
  if ($Filename -eq "who-we-are.html") { $heroClass += " who-we-are-hero" }

  $bgStyle = if ($heroClass -eq 'service-hero') { " style=`"background-image: url('$($Image)');`"" } else { "" }

  $hero = @"
    <section class="$($heroClass)"$($bgStyle)>
        <div class="service-hero-overlay"></div>
        <div class="container service-hero-content">
            <h1 data-aos="fade-up">$($Title)</h1>
            <p class="hero-subtitle" data-aos="fade-up" data-aos-delay="100">$($Desc)</p>
        </div>
    </section>

    <section class="service-detail-section">
        <div class="container">
            <div class="service-main">
                <h2 class="section-title">$($Title)</h2>
                <p class="lead section-lead">
                    At Shanfix Technology, we specialize in <strong>$($Title)</strong>. 
                    $($Desc) Our commitment to excellence ensures that we provide solutions that drive success.
                </p>
                
                <h3>Why Choose Shanfix?</h3>
                <ul class="feature-list">
                    <li class="feature-item">
                        <span class="feature-inner-icon">Check:</span>
                        <span><strong>Expert Team:</strong> Experienced professionals dedicated to your success.</span>
                    </li>
                    <li class="feature-item">
                        <span class="feature-inner-icon">Check:</span>
                        <span><strong>Custom Solutions:</strong> Strategies tailored uniquely to your business needs.</span>
                    </li>
                </ul>

                <p>
                    Discover more about how we can help you achieve your goals with our professional <strong>$($Title)</strong>.
                </p>
            </div>
        </div>
    </section>
"@
  $finalHtml = $content + $hero + $footer
  $path = "c:\Shanfix Technology\" + $Filename
  Set-Content -Path $path -Value $finalHtml
  Write-Host "Created $($Filename)"
}

# Generate Service Pages
foreach ($service in $services) {
  New-Page -Title $service.Title -Filename $service.Filename -Image $service.Image -Desc $service.Desc
}

# Generate Special Pages
foreach ($page in $specialPages) {
  New-Page -Title $page.Title -Filename $page.Filename -Image $page.Image -Desc $page.Desc
}
