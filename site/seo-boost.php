<?php
$pageSEO = [
    'title'       => 'SEO Services in Nairobi | Search Engine Optimization - Shanfix Technology',
    'description' => 'Boost your website\'s Google rankings with expert SEO services in Nairobi, Kenya. On-page, off-page, and technical SEO by Shanfix Technology.',
    'keywords'    => 'SEO services Nairobi, search engine optimization Kenya, Google ranking, website SEO',
    'canonical'   => 'https://shanfixtechnology.com/seo-boost.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"SEO Services","description":"Boost your Google rankings with on-page, off-page, and technical SEO services in Nairobi, Kenya.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"https://shanfixtechnology.com/"},"areaServed":{"@type":"City","name":"Nairobi"},"url":"https://shanfixtechnology.com/seo-boost.php"}',
];
include 'includes/header.php'; ?>

    <!-- Hero Section -->
    <section class="service-hero hero-seo">
        <div class="service-hero-overlay"></div>
        <div class="container service-hero-content">
            <h1 data-aos="fade-up">SEO Boost Services</h1>
            <p class="hero-subtitle" data-aos="fade-up" data-aos-delay="100">
                Dominate the search results, drive organic traffic, and achieve sustainable growth with our data-driven SEO strategies.
            </p>
            <div class="mt-8" data-aos="fade-up" data-aos-delay="200">
                <a href="#packages" class="btn btn-primary">View Packages</a>
                <a href="contact.php" class="btn btn-secondary ml-4">Free SEO Audit</a>
            </div>
        </div>
    </section>

    <!-- Intro Section -->
    <section class="service-detail-section section-white pb-0">
        <div class="container">
            <div class="section-header text-center mx-auto max-w-3xl mb-12">
                <h2 class="section-title">Visibility that Drives Revenue</h2>
                <p class="section-subtitle">
                    Ranking #1 on Google is just the beginning. Our SEO services are designed to attract the *right* audience, improve user engagement, and convert visitors into loyal customers.
                </p>
            </div>
        </div>
    </section>

    <!-- SEO Pillars -->
    <section class="feature-cards-section section-light py-3xl">
      <div class="container">
        <div class="section-header text-center mb-12">
          <h2 class="section-title">Our SEO Pillars</h2>
          <p class="section-subtitle">A comprehensive approach to modern search engine optimization</p>
        </div>
        
        <div class="auto-grid-280">
          <!-- Technical SEO -->
          <div class="feature-card text-center">
            <div class="feature-card-icon mx-auto mb-4">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
            </div>
            <h3 class="feature-card-title">Technical SEO</h3>
            <p class="feature-card-description">We optimize your site's structure, speed, and mobile performance to ensure search engines can crawl and index your content efficiently.</p>
          </div>

          <!-- Keyword Research -->
          <div class="feature-card text-center">
            <div class="feature-card-icon mx-auto mb-4">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </div>
            <h3 class="feature-card-title">Keyword Strategy</h3>
            <p class="feature-card-description">Data-driven research to identify high-intent keywords that your customers are using, ensuring you show up for the right searches.</p>
          </div>
          
          <!-- Content SEO -->
          <div class="feature-card text-center">
            <div class="feature-card-icon mx-auto mb-4">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            </div>
            <h3 class="feature-card-title">On-Page Content</h3>
            <p class="feature-card-description">Crafting high-quality, relevant content that satisfies user intent while optimizing meta tags, headings, and internal links.</p>
          </div>

          <!-- Link Building -->
          <div class="feature-card text-center">
            <div class="feature-card-icon mx-auto mb-4">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
            </div>
            <h3 class="feature-card-title">Authority Building</h3>
            <p class="feature-card-description">Ethical link-building strategies that earn high-quality backlinks from reputable sources, increasing your domain authority.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- SEO Roadmap -->
    <section class="section-white py-3xl">
      <div class="container">
        <div class="section-header text-center">
          <h2 class="section-title">The SEO Roadmap</h2>
          <p class="section-subtitle">Our proven process for climbing the rankings</p>
        </div>
        <div class="process-grid">
          <div class="process-step">
            <div class="process-icon">1</div>
            <h4 class="process-title">Deep Audit</h4>
            <p class="process-desc">We analyze your current performance, identify technical bottlenecks, and spy on your competitors' strategies.</p>
          </div>
          <div class="process-step">
            <div class="process-icon">2</div>
            <h4 class="process-title">Strategic Plan</h4>
            <p class="process-desc">We create a customized roadmap focusing on high-impact keywords and technical fixes that will yield results.</p>
          </div>
          <div class="process-step">
            <div class="process-icon">3</div>
            <h4 class="process-title">Optimization</h4>
            <p class="process-desc">Our team implements the strategy, optimizing your code and content while building your site's authority.</p>
          </div>
          <div class="process-step">
            <div class="process-icon">4</div>
            <h4 class="process-title">Scale & Report</h4>
            <p class="process-desc">Continuous monitoring and monthly reports ensure we stay on track, adjusting strategies as Google's algorithms evolve.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Why Us -->
    <section class="section-light py-3xl">
      <div class="container">
        <div class="grid-cols-1 gap-8 items-center text-center mx-auto max-w-3xl">
            <h2 class="section-title mb-8">Why SEO with Shanfix?</h2>
            
            <div class="md-grid-cols-2 text-left">
              <ul class="feature-list m-0">
                <li class="feature-item mb-6 flex-row-start">
                  <span class="feature-inner-icon text-primary bg-primary-light p-2 rounded-full flex">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  </span>
                  <div><strong>Transparent Reporting:</strong> No vanity metrics. We focus on leads, sales, and organic growth you can track.</div>
                </li>
                <li class="feature-item mb-6 flex-row-start">
                  <span class="feature-inner-icon text-primary bg-primary-light p-2 rounded-full flex">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  </span>
                  <div><strong>Future-Proof SEO:</strong> We only use white-hat techniques that align with search engine guidelines to ensure long-term stability.</div>
                </li>
              </ul>
              <ul class="feature-list m-0">
                <li class="feature-item mb-6 flex-row-start">
                  <span class="feature-inner-icon text-primary bg-primary-light p-2 rounded-full flex">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  </span>
                  <div><strong>Local Expertise:</strong> Specialized Local SEO to help you dominate Nairobi and the larger East African market.</div>
                </li>
                <li class="feature-item mb-6 flex-row-start">
                  <span class="feature-inner-icon text-primary bg-primary-light p-2 rounded-full flex">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  </span>
                  <div><strong>Holistic Strategy:</strong> We integrate SEO with your content marketing and web development for maximum synergy.</div>
                </li>
              </ul>
            </div>
        </div>
      </div>
    </section>

    <!-- Pricing Section -->
    <section id="packages" class="section-white py-3xl">
      <div class="container">
        <div class="section-header text-center mb-12">
          <h2 class="section-title">SEO Service Packages</h2>
          <p class="section-subtitle">Tailored strategies for businesses at every stage</p>
        </div>

        <div class="pricing-grid">
          
          <!-- Basic -->
          <div class="pricing-card">
            <div class="pricing-header">
              <h3 class="pricing-title">Local SEO / Starter</h3>
              <div class="pricing-price">
                  <span class="pricing-currency">KES</span> 25,000
              </div>
              <div class="pricing-period">Per month</div>
            </div>
            <ul class="pricing-features">
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Google My Business Setup</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> 10 Keyword Trackers</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> On-Page Audit & Fixes</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Local Citation Building</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Monthly Progress Report</li>
            </ul>
            <button onclick="openPackageModal('Local SEO Package', 25000)" class="btn btn-secondary w-full">Get Started</button>
          </div>

          <!-- Professional -->
          <div class="pricing-card featured">
            <div class="pricing-header">
              <h3 class="pricing-title">Growth SEO</h3>
              <div class="pricing-price">
                  <span class="pricing-currency">KES</span> 60,000
              </div>
              <div class="pricing-period">Per month</div>
            </div>
            <ul class="pricing-features">
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Competitive Keyword Analysis</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> 30 Keyword Trackers</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> 4 SEO Blog Posts / Month</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Backlink Acquisition</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Speed & Core Web Vitals</li>
            </ul>
            <button onclick="openPackageModal('Growth SEO Package', 60000)" class="btn btn-primary w-full">Accelerate Growth</button>
          </div>

          <!-- Enterprise -->
          <div class="pricing-card">
            <div class="pricing-header">
              <h3 class="pricing-title">Enterprise / Global</h3>
              <div class="pricing-price">
                  Custom
              </div>
              <div class="pricing-period">Bespoke Enterprise Solutions</div>
            </div>
            <ul class="pricing-features">
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Multi-Region / Global SEO</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Custom Content Marketing</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Advanced Technical Cleanup</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Dedicated SEO Strategist</li>
              <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg> Weekly Strategy Calls</li>
            </ul>
            <a href="contact.php" class="btn btn-secondary w-full">Contact Sales</a>
          </div>

        </div>
      </div>
    </section>

    <!-- Final CTA -->
    <section class="section-white py-3xl">
        <div class="container text-center">
            <h2 class="section-title mb-6">Stop Being Invisible. Start Growing.</h2>
            <p class="section-subtitle mb-8 mx-auto max-w-2xl">
                Get a comprehensive analysis of your website's SEO health and a custom roadmap to the top of Google.
            </p>
            <a href="contact.php" class="btn btn-primary btn-lg">Request a Free SEO Audit</a>
        </div>
    </section>

<?php $ctaService = 'SEO Boost'; include 'includes/service_cta.php'; ?>
<?php include 'includes/footer.php'; ?>
