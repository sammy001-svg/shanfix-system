<?php
require_once __DIR__ . '/asset.php';
require_once __DIR__ . '/brand.php';

// Its own lookup rather than the header's variable: the footer is
// included on its own by a couple of pages, and site_brand() is answered
// from memory after the first call either way.
$_brand = site_brand();
?>
<?php /* Footer
     Rebuilt. What changed and why:
       - The newsletter form saves now. It posts to the system's
         /api/newsletter/subscribe; before, the field had no name and the
         form no action, so every sign-up was lost on reload.
       - Social icons come from the system's company settings and only
         the filled-in ones appear. They were all href="#".
       - "24/7 Support" is gone. It contradicted the live chat's own
         hours; the real hours are shown instead, from the same settings.
       - No fade-in animations: a footer is where people go looking for a
         phone number, and it should simply be there. */ ?>
<?php
$_year   = date('Y');
$_social = $_brand['social'] ?? [];

// One path per network, drawn at 24x24 and filled with currentColor.
$_socialIcons = [
    'facebook'  => ['Facebook',  'M24 12.07C24 5.44 18.63.07 12 .07S0 5.44 0 12.07c0 5.99 4.39 10.95 10.13 11.85v-8.38H7.08v-3.47h3.05V9.43c0-3.01 1.79-4.67 4.53-4.67 1.31 0 2.69.24 2.69.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87v2.25h3.33l-.53 3.47h-2.8v8.38C19.61 23.02 24 18.06 24 12.07z'],
    'instagram' => ['Instagram', 'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.72 3.72 0 0 1-1.38-.9 3.72 3.72 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63a5.88 5.88 0 0 0-2.13 1.38A5.88 5.88 0 0 0 .63 4.14C.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.3.79.72 1.46 1.38 2.13a5.88 5.88 0 0 0 2.13 1.38c.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56a5.88 5.88 0 0 0 2.13-1.38 5.88 5.88 0 0 0 1.38-2.13c.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.88 5.88 0 0 0-1.38-2.13A5.88 5.88 0 0 0 19.86.63C19.1.33 18.22.13 16.95.07 15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.4-11.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88z'],
    'linkedin'  => ['LinkedIn',  'M20.45 20.45h-3.55v-5.57c0-1.33-.03-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13zM7.12 20.45H3.56V9h3.56v11.45zM22.23 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z'],
    'x'         => ['X',         'M18.9 1.15h3.68l-8.04 9.19L24 22.85h-7.4l-5.8-7.58-6.63 7.58H.49l8.6-9.83L0 1.15h7.59l5.24 6.93 6.07-6.93zm-1.29 19.5h2.04L6.48 3.24H4.3l13.31 17.41z'],
    'tiktok'    => ['TikTok',    'M12.53.02C13.84 0 15.14.01 16.44 0c.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z'],
    'youtube'   => ['YouTube',   'M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19C0 8.07 0 12 0 12s0 3.93.5 5.81a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14C24 15.93 24 12 24 12s0-3.93-.5-5.81zM9.55 15.57V8.43L15.82 12l-6.27 3.57z'],
    'whatsapp'  => ['WhatsApp',  'M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.39-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51l-.57-.01c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.79h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88a9.82 9.82 0 0 1 6.99 2.9 9.82 9.82 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.88 9.88zm8.41-18.3A11.81 11.81 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.16-3.48-8.41z'],
];

$_line = static fn(string $d): string =>
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
?>
    <footer class="sf">

      <?php // ── Newsletter ───────────────────────────────────────────── ?>
      <div class="sf-news">
        <div class="container sf-news-inner">
          <div class="sf-news-copy">
            <h3>Stay in the loop</h3>
            <p>Occasional news, offers and practical technology tips. No spam, and you can leave at any time.</p>
          </div>

          <form class="sf-news-form" id="sfNewsForm" novalidate>
            <?php // Real visitors never see this field. A bot that fills in
                  // every input it finds is told it succeeded and nothing
                  // is saved. ?>
            <input type="text" name="website" class="sf-trap" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="sf-sr" for="sfNewsEmail">Email address</label>
            <input type="email" id="sfNewsEmail" name="email" placeholder="Your email address"
                   autocomplete="email" required maxlength="160">
            <button type="submit">Subscribe</button>
            <p class="sf-news-msg" id="sfNewsMsg" role="status" aria-live="polite"></p>
          </form>
        </div>
      </div>

      <?php // ── The main footer ──────────────────────────────────────── ?>
      <div class="container sf-main">
        <div class="sf-brand">
          <a href="index.php"><img src="assets/shanfix-logo.png" alt="<?= htmlspecialchars($_brand['name']) ?>" class="sf-logo"></a>
          <p>Software, systems, printing and branding for businesses across Kenya and East Africa.</p>

          <?php if ($_social): ?>
            <div class="sf-social">
              <?php foreach ($_social as $network => $href): if (!isset($_socialIcons[$network])) continue; ?>
                <a href="<?= htmlspecialchars($href) ?>" target="_blank" rel="noopener"
                   aria-label="<?= htmlspecialchars($_brand['name'] . ' on ' . $_socialIcons[$network][0]) ?>">
                  <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="<?= $_socialIcons[$network][1] ?>"/></svg>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <nav class="sf-col" aria-label="Services">
          <h4>Services</h4>
          <ul>
            <li><a href="web-development.php">Web Development</a></li>
            <li><a href="app-development.php">App Development</a></li>
            <li><a href="web-hosting.php">Web Hosting</a></li>
            <li><a href="printing-branding.php">Printing &amp; Branding</a></li>
            <li><a href="digital-marketing.php">Digital Marketing</a></li>
            <li><a href="bulk-sms.php">Bulk SMS</a></li>
          </ul>
        </nav>

        <nav class="sf-col" aria-label="Software">
          <h4>Software</h4>
          <ul>
            <li><a href="software-solution.php">Software Solutions</a></li>
            <li><a href="pos-solution.php">Point of Sale</a></li>
            <li><a href="erp-solution.php">Business ERP</a></li>
            <li><a href="school-management.php">School Management</a></li>
            <li><a href="event-ticketing.php">Event Ticketing</a></li>
          </ul>
        </nav>

        <nav class="sf-col" aria-label="Company">
          <h4>Company</h4>
          <ul>
            <li><a href="who-we-are.php">About Us</a></li>
            <li><a href="portfolio.php">Our Work</a></li>
            <li><a href="blog.php">Blog</a></li>
            <li><a href="contact.php">Contact</a></li>
            <li><a href="/portal/login">Client Portal</a></li>
          </ul>
        </nav>

        <div class="sf-col sf-contact">
          <h4>Get in touch</h4>
          <ul>
            <li>
              <?= $_line('<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>') ?>
              <a href="tel:<?= htmlspecialchars($_brand['phone_tel']) ?>"><?= htmlspecialchars($_brand['phone']) ?></a>
            </li>
            <li>
              <?= $_line('<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>') ?>
              <a href="mailto:<?= htmlspecialchars($_brand['email']) ?>"><?= htmlspecialchars($_brand['email']) ?></a>
            </li>
            <li>
              <?= $_line('<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>') ?>
              <span><?= htmlspecialchars($_brand['address']) ?></span>
            </li>
            <?php if (!empty($_brand['hours'])): ?>
              <li>
                <?= $_line('<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>') ?>
                <span><?= htmlspecialchars($_brand['hours']) ?></span>
              </li>
            <?php endif; ?>
          </ul>
        </div>
      </div>

      <?php // ── Bottom line ──────────────────────────────────────────── ?>
      <div class="sf-bottom">
        <div class="container sf-bottom-inner">
          <p>&copy; <?= $_year ?> <?= htmlspecialchars($_brand['name']) ?>. All rights reserved.</p>
          <nav aria-label="Footer">
            <a href="contact.php">Contact</a>
            <a href="/portal/login">Client Portal</a>
            <a href="/signin" rel="nofollow">Staff Sign In</a>
          </nav>
        </div>
      </div>
    </footer>

    <script>
    // The newsletter form. Posts to the system and says what happened in
    // place, without reloading the page — the old form reloaded and saved
    // nothing, which is exactly what this replaces.
    (function () {
      var form = document.getElementById('sfNewsForm');
      if (!form) { return; }
      var msg = document.getElementById('sfNewsMsg');
      var btn = form.querySelector('button');

      form.addEventListener('submit', function (e) {
        e.preventDefault();

        var email = form.email.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          msg.className = 'sf-news-msg is-bad';
          msg.textContent = 'Please enter a valid email address.';
          form.email.focus();
          return;
        }

        var data = new FormData(form);
        data.append('page', location.href.slice(0, 255));
        btn.disabled = true;

        fetch('/api/newsletter/subscribe', { method: 'POST', body: data })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            msg.className = 'sf-news-msg ' + (d.ok ? 'is-good' : 'is-bad');
            msg.textContent = d.ok ? d.message : (d.error || 'That did not work. Please try again.');
            if (d.ok) { form.email.value = ''; }
          })
          .catch(function () {
            msg.className = 'sf-news-msg is-bad';
            msg.textContent = 'We could not reach our server. Please try again in a moment.';
          })
          .then(function () { btn.disabled = false; });
      });
    })();
    </script>

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



