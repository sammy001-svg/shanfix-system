<?php
require_once __DIR__ . '/asset.php';
require_once __DIR__ . '/brand.php';

// Its own lookup rather than the header's variable: the footer is
// included on its own by a couple of pages, and site_brand() is answered
// from memory after the first call either way.
$_brand = site_brand();
?>
<!-- Footer -->
    <footer class="footer">
      <div class="footer-top-decoration"></div>
      <div class="container">
        <div class="footer-newsletter" data-aos="fade-up">
          <div class="newsletter-content">
            <h3 class="newsletter-title">Subscribe to Our <span class="highlight">Newsletter</span></h3>
            <p>Get the latest updates on technology trends and business solutions.</p>
          </div>
          <form class="newsletter-form">
            <div class="input-group">
              <input type="email" placeholder="Your email address" required aria-label="Email Address">
              <button type="submit" class="btn btn-primary">Subscribe Now</button>
            </div>
          </form>
        </div>

        <div class="footer-grid">
          <div class="footer-col" data-aos="fade-up" data-aos-delay="100">
            <img src="assets/shanfix-logo.png" alt="Shanfix Technology Logo" class="footer-logo">
            <p class="footer-text">
              Pioneering innovative technology solutions and professional business services across East Africa.
            </p>
            <div class="contact-info-list">
              <div class="contact-info-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                <span><?= htmlspecialchars($_brand['phone']) ?></span>
              </div>
              <div class="contact-info-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                <span><?= htmlspecialchars($_brand['email']) ?></span>
              </div>
              <div class="contact-info-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                <span><?= htmlspecialchars($_brand['address']) ?></span>
              </div>
            </div>
          </div>

          <div class="footer-col" data-aos="fade-up" data-aos-delay="200">
            <h4 class="footer-heading">Quick Navigation</h4>
            <ul class="footer-links">
              <li><a href="index.php">Home Page</a></li>
              <li><a href="who-we-are.php">Our Story</a></li>
              <li><a href="index.php#services">Core Services</a></li>
              <li><a href="portfolio.php">Case Studies</a></li>
              <li><a href="contact.php">Contact Support</a></li>
            </ul>
          </div>

          <div class="footer-col" data-aos="fade-up" data-aos-delay="300">
            <h4 class="footer-heading">Solution Hub</h4>
            <ul class="footer-links">
              <li><a href="web-development.php">Custom Web Design</a></li>
              <li><a href="software-solution.php">Enterprise Systems</a></li>
              <li><a href="bulk-sms.php">Bulk SMS Platform</a></li>
              <li><a href="seo-boost.php">Search Engine Visibility</a></li>
              <li><a href="networking-solution.php">IT Infrastructure</a></li>
            </ul>
          </div>

          <div class="footer-col" data-aos="fade-up" data-aos-delay="400">
            <h4 class="footer-heading">Global Reach</h4>
            <div class="social-links">
              <a href="#" aria-label="Facebook" class="social-link">
                <svg viewBox="0 0 24 24" fill="currentColor">
                  <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                </svg>
              </a>
              <a href="#" aria-label="Twitter" class="social-link">
                <svg viewBox="0 0 24 24" fill="currentColor">
                  <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                </svg>
              </a>
              <a href="#" aria-label="LinkedIn" class="social-link">
                <svg viewBox="0 0 24 24" fill="currentColor">
                  <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                </svg>
              </a>
            </div>
            <div class="footer-badges">
              <div class="badge-item">Security Verified</div>
              <div class="badge-item">24/7 Support</div>
            </div>
          </div>
        </div>
        <div class="footer-bottom">
          <p>&copy; 2026 Shanfix Technology. Empowering Innovation. All rights reserved.</p>
        </div>
      </div>
    </footer>

    <!-- Checkout Modal -->
    <div id="checkoutModal" class="modal checkout-modal">
        <div class="modal-content glass-morphism">
            <span class="close-modal">&times;</span>
            
            <div class="checkout-header">
                <div class="step-indicator">
                    <div class="step active" data-step="1">1<span>Confirm</span></div>
                    <div class="step-line"></div>
                    <div class="step" data-step="2">2<span>Details</span></div>
                    <div class="step-line"></div>
                    <div class="step" data-step="3">3<span>Payment</span></div>
                </div>
            </div>

            <form id="checkoutForm">
                <!-- Step 1: Confirmation -->
                <div class="checkout-step active" id="step1">
                    <h2 class="step-title">Review Your Selection</h2>
                    <div class="package-summary-card">
                        <div class="package-info">
                            <h3 id="displayPackageName">Premium Shared Hosting</h3>
                            <p id="displayPackagePrice">KES 5,500/yr</p>
                        </div>
                        <div class="package-status">
                            <span class="status-badge">Package Selected</span>
                        </div>
                    </div>
                    <div class="confirmation-check">
                        <label class="checkbox-container">
                            I confirm this is the package I want to purchase
                            <input type="checkbox" required id="confirmPackage">
                            <span class="checkmark"></span>
                        </label>
                    </div>
                    <div class="step-actions">
                        <button type="button" class="btn btn-primary next-step">Proceed to Details</button>
                    </div>
                </div>

                <!-- Step 2: Personal Details -->
                <div class="checkout-step" id="step2">
                    <h2 class="step-title">Personal & Company Details</h2>
                    <div class="form-grid">
                        <div class="input-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" required placeholder="John">
                        </div>
                        <div class="input-group">
                            <label>Last Name</label>
                            <input type="text" name="last_name" required placeholder="Doe">
                        </div>
                        <div class="input-group">
                            <label>Email Address</label>
                            <input type="email" name="email" required placeholder="john@example.com">
                        </div>
                        <div class="input-group">
                            <label>Phone Number</label>
                            <input type="tel" name="phone" required placeholder="+254 700 000 000">
                        </div>
                        <div class="input-group">
                            <label>Address 1</label>
                            <input type="text" name="address1" required placeholder="Street Address, P.O Box">
                        </div>
                        <div class="input-group">
                            <label>Address 2 (Optional)</label>
                            <input type="text" name="address2" placeholder="Apartment, suite, unit, etc.">
                        </div>
                        <div class="input-group">
                            <label>City</label>
                            <input type="text" name="city" required placeholder="Nairobi">
                        </div>
                        <div class="input-group">
                            <label>State / Region</label>
                            <input type="text" name="state_region" required placeholder="Nairobi County">
                        </div>
                        <div class="input-group">
                            <label>County</label>
                            <input type="text" name="county" required placeholder="Kenya">
                        </div>
                        <div class="input-group">
                            <label>Company Name (Optional)</label>
                            <input type="text" name="company" placeholder="Shanfix Tech">
                        </div>
                        <div class="input-group">
                            <label>Billing Contact</label>
                            <input type="text" name="billing_contact" placeholder="Accounts Dept">
                        </div>
                        <div class="input-group">
                            <label>Tax ID (Optional)</label>
                            <input type="text" name="tax_id" placeholder="KRA PIN">
                        </div>
                        <div class="input-group">
                            <label>Choose Language</label>
                            <select name="language">
                                <option value="English">English</option>
                                <option value="Swahili">Swahili</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>How did you find us?</label>
                            <select name="referral_source">
                                <option value="Google">Google Search</option>
                                <option value="Social Media">Social Media</option>
                                <option value="Friend">Friend Referral</option>
                                <option value="Advertisement">Advertisement</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Password</label>
                            <input type="password" name="password" id="regPassword" required placeholder="********">
                        </div>
                        <div class="input-group">
                            <label>Confirm Password</label>
                            <input type="password" id="confirmPassword" required placeholder="********">
                        </div>
                    </div>
                    <div class="step-actions">
                        <button type="button" class="btn btn-secondary prev-step">Back</button>
                        <button type="button" class="btn btn-primary next-step">Proceed to Payment</button>
                    </div>
                </div>

                <!-- Step 3: Payment -->
                <div class="checkout-step" id="step3">
                    <h2 class="step-title">Choose Payment Method</h2>
                    <div class="payment-options">
                        <label class="payment-card">
                            <input type="radio" name="payment_method" value="mpesa" checked>
                            <div class="payment-card-content">
                                <img src="assets/mpesa-logo.png" alt="Mpesa" class="payment-logo">
                                <span>M-Pesa STK Push</span>
                            </div>
                        </label>
                        <label class="payment-card">
                            <input type="radio" name="payment_method" value="bank">
                            <div class="payment-card-content">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                                <span>Bank Transfer</span>
                            </div>
                        </label>
                    </div>

                    <div id="bankDetails" class="payment-details-box hidden">
                        <h4>Bank Account Details</h4>
                        <p>Bank: Equity Bank</p>
                        <p>Account Name: Shanfix Technology</p>
                        <p>Account Number: 1234567890</p>
                        <div class="input-group">
                            <label>Transaction Reference Code</label>
                            <input type="text" name="bank_ref" placeholder="Enter Ref Code">
                        </div>
                    </div>

                    <div id="mpesaDetails" class="payment-details-box">
                        <p>Enter your M-Pesa number to receive a payment prompt.</p>
                        <div class="input-group">
                            <label>M-Pesa Number</label>
                            <input type="tel" name="mpesa_number" placeholder="254700000000">
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-secondary prev-step">Back</button>
                        <button type="submit" class="btn btn-primary" id="submitOrder">Complete Purchase</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= site_asset('./main.js') ?>"></script>

    <!-- PWA Install Banner -->
    <div id="pwaInstallBanner" style="display:none;" role="dialog" aria-label="Install Shanfix app">
      <div class="pwa-banner-icon">
        <img src="/assets/icons/icon-192x192.png" alt="Shanfix" width="48" height="48">
      </div>
      <div class="pwa-banner-text">
        <strong>Install Shanfix App</strong>
        <span>Add to home screen for quick access, even offline.</span>
      </div>
      <div class="pwa-banner-actions">
        <button id="pwaInstallBtn" class="pwa-btn-install">Install</button>
        <button id="pwaDismissBtn" class="pwa-btn-dismiss" aria-label="Dismiss">&#x2715;</button>
      </div>
    </div>

    <style>
      #pwaInstallBanner {
        position: fixed;
        bottom: 24px;
        left: 50%;
        transform: translateX(-50%) translateY(120px);
        z-index: 99999;
        background: #1e293b;
        border: 1px solid rgba(34,197,94,0.35);
        border-radius: 16px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.45), 0 0 0 1px rgba(34,197,94,0.12);
        padding: 14px 18px;
        display: flex !important;
        align-items: center;
        gap: 14px;
        max-width: 420px;
        width: calc(100vw - 32px);
        transition: transform 0.45s cubic-bezier(0.34,1.56,0.64,1), opacity 0.35s ease;
        opacity: 0;
      }
      #pwaInstallBanner.pwa-visible {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
      }
      .pwa-banner-icon img { border-radius: 10px; flex-shrink: 0; }
      .pwa-banner-text { flex: 1; min-width: 0; }
      .pwa-banner-text strong { display: block; color: #f1f5f9; font-size: 0.95rem; font-weight: 700; font-family: 'Outfit', sans-serif; }
      .pwa-banner-text span { display: block; color: #94a3b8; font-size: 0.8rem; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
      .pwa-banner-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
      .pwa-btn-install {
        background: linear-gradient(135deg, #22c55e, #16a34a);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 8px 18px;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        transition: opacity 0.2s;
        white-space: nowrap;
      }
      .pwa-btn-install:hover { opacity: 0.88; }
      .pwa-btn-dismiss {
        background: none;
        border: none;
        color: #64748b;
        font-size: 1.1rem;
        cursor: pointer;
        padding: 4px 6px;
        border-radius: 6px;
        transition: color 0.2s;
        line-height: 1;
      }
      .pwa-btn-dismiss:hover { color: #f1f5f9; }
      @media (max-width: 480px) {
        #pwaInstallBanner { bottom: 16px; padding: 12px 14px; gap: 10px; }
        .pwa-banner-text span { display: none; }
      }
    </style>

    <!-- PWA Service Worker + Install Prompt -->
    <script>
      (function () {
        var DISMISS_KEY = 'pwa_banner_dismissed';
        var DISMISS_TTL = 7 * 24 * 60 * 60 * 1000; // 7 days
        var deferredPrompt = null;
        var banner = document.getElementById('pwaInstallBanner');
        var installBtn = document.getElementById('pwaInstallBtn');
        var dismissBtn = document.getElementById('pwaDismissBtn');

        function isDismissed() {
          var ts = localStorage.getItem(DISMISS_KEY);
          return ts && (Date.now() - parseInt(ts, 10)) < DISMISS_TTL;
        }
        function showBanner() {
          if (!banner || isDismissed()) return;
          banner.style.display = 'flex';
          setTimeout(function () { banner.classList.add('pwa-visible'); }, 50);
        }
        function hideBanner() {
          banner.classList.remove('pwa-visible');
          setTimeout(function () { banner.style.display = 'none'; }, 400);
        }

        // Capture the install prompt
        window.addEventListener('beforeinstallprompt', function (e) {
          e.preventDefault();
          deferredPrompt = e;
          setTimeout(showBanner, 4000); // show after 4 s
        });

        // iOS Safari fallback: show banner if standalone not already
        if (/iphone|ipad|ipod/i.test(navigator.userAgent) && !window.navigator.standalone) {
          setTimeout(showBanner, 4000);
          if (installBtn) {
            installBtn.textContent = 'How to Install';
            installBtn.addEventListener('click', function () {
              alert('To install: tap the Share button 📤 in Safari, then tap “Add to Home Screen”.');
              localStorage.setItem(DISMISS_KEY, Date.now());
              hideBanner();
            });
          }
        }

        if (installBtn) {
          installBtn.addEventListener('click', function () {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
              deferredPrompt = null;
              localStorage.setItem(DISMISS_KEY, Date.now());
              hideBanner();
            });
          });
        }
        if (dismissBtn) {
          dismissBtn.addEventListener('click', function () {
            localStorage.setItem(DISMISS_KEY, Date.now());
            hideBanner();
          });
        }

        // Hide once already installed
        window.addEventListener('appinstalled', function () { hideBanner(); });

        // Service Worker
        // /sw.js is served by the system, not by this site. A browser
        // allows one service worker per origin and this site now shares an
        // origin with the system, so there is one between them: pages
        // network-first with an offline fallback, assets
        // stale-while-revalidate. The site's own worker was cache-first on
        // everything under /assets/, which would have gone on serving the
        // system's old stylesheet long after a deployment replaced it.
        if ('serviceWorker' in navigator) {
          window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () {});
          });
        }
      })();
    </script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
      AOS.init({
        duration: 1000,
        once: true,
        offset: 100
      });
    </script>
  </body>
</html>



