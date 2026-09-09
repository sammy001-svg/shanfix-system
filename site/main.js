// ===================================
// SHANFIX TECHNOLOGY - MAIN JAVASCRIPT
// ===================================

// ===================================
// HERO CAROUSEL
// ===================================

let currentSlide = 0;
const slides = document.querySelectorAll('.hero-slide');
const indicators = document.querySelectorAll('.carousel-indicators .indicator');
const totalSlides = slides.length;
let autoplayInterval;

// Function to show specific slide
function showSlide(index) {
  // Remove active class from all slides and indicators
  slides.forEach(slide => slide.classList.remove('active'));
  indicators.forEach(indicator => indicator.classList.remove('active'));
  
  // Add active class to current slide and indicator
  slides[index].classList.add('active');
  indicators[index].classList.add('active');
  
  currentSlide = index;
}

// Function to go to next slide
function nextSlide() {
  let next = (currentSlide + 1) % totalSlides;
  showSlide(next);
}

// Function to go to previous slide
function prevSlide() {
  let prev = (currentSlide - 1 + totalSlides) % totalSlides;
  showSlide(prev);
}

// Autoplay carousel every 5 seconds
function startAutoplay() {
  autoplayInterval = setInterval(nextSlide, 5000);
}

function stopAutoplay() {
  clearInterval(autoplayInterval);
}

// Initialize carousel
startAutoplay();

// Pause autoplay on hover
const heroCarousel = document.querySelector('.hero-carousel');
if (heroCarousel) {
  heroCarousel.addEventListener('mouseenter', stopAutoplay);
  heroCarousel.addEventListener('mouseleave', startAutoplay);
}

// Navigation controls
const prevButton = document.querySelector('.carousel-control.prev');
const nextButton = document.querySelector('.carousel-control.next');

if (prevButton) {
  prevButton.addEventListener('click', () => {
    prevSlide();
    stopAutoplay();
    startAutoplay(); // Restart autoplay after manual navigation
  });
}

if (nextButton) {
  nextButton.addEventListener('click', () => {
    nextSlide();
    stopAutoplay();
    startAutoplay(); // Restart autoplay after manual navigation
  });
}

// Indicator navigation
indicators.forEach((indicator, index) => {
  indicator.addEventListener('click', () => {
    showSlide(index);
    stopAutoplay();
    startAutoplay(); // Restart autoplay after manual navigation
  });
});

// Smooth Scroll Navigation
document.querySelectorAll('a[href*="#"]').forEach(anchor => {
  anchor.addEventListener('click', function (e) {
    const href = this.getAttribute('href');
    
    // Check if it's purely a fragment on the current page OR if it's for current page
    if (href.startsWith('#') || href.startsWith('index.php#')) {
      const fragment = href.includes('#') ? '#' + href.split('#')[1] : null;
      const target = fragment ? document.querySelector(fragment) : null;
      
      if (target) {
        e.preventDefault();
        const navHeight = document.querySelector('.navbar').offsetHeight;
        const targetPosition = target.offsetTop - navHeight;
        
        window.scrollTo({
          top: targetPosition,
          behavior: 'smooth'
        });
      }
      // If it's index.php#ButNotThisPage, let the link follow normally
    }
  });
});

// Mobile Menu Toggle
const mobileMenuToggle = document.getElementById('mobileMenuToggle');
const navMenu = document.getElementById('navMenu');

if (mobileMenuToggle) {
  mobileMenuToggle.addEventListener('click', () => {
    navMenu.classList.toggle('active');
    mobileMenuToggle.classList.toggle('active');
  });
}

// Close mobile menu when clicking on a link
document.querySelectorAll('.nav-link').forEach(link => {
  link.addEventListener('click', () => {
    navMenu.classList.remove('active');
    mobileMenuToggle.classList.remove('active');
  });
});

// Mega Menu Mobile Toggle
const navItemDropdown = document.querySelector('.nav-item-dropdown');

if (navItemDropdown && window.innerWidth <= 767) {
  const dropdownLink = navItemDropdown.querySelector('.nav-link');
  
  dropdownLink.addEventListener('click', (e) => {
    e.preventDefault();
    navItemDropdown.classList.toggle('active');
  });
}

// Update mega menu behavior on window resize
window.addEventListener('resize', () => {
  const navItemDropdown = document.querySelector('.nav-item-dropdown');
  if (window.innerWidth > 767 && navItemDropdown) {
    navItemDropdown.classList.remove('active');
  }
});


// Navbar Scroll Effect
let lastScroll = 0;
const navbar = document.getElementById('navbar');

window.addEventListener('scroll', () => {
  const currentScroll = window.pageYOffset;
  
  if (currentScroll > 100) {
    navbar.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)';
  } else {
    navbar.style.boxShadow = '0 1px 2px 0 rgba(0, 0, 0, 0.05)';
  }
  
  lastScroll = currentScroll;
});

// Scroll Animation Observer
const observerOptions = {
  threshold: 0.1,
  rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('aos-animate');
    }
  });
}, observerOptions);

// Observe all elements with data-aos attribute
document.querySelectorAll('[data-aos]').forEach(el => {
  observer.observe(el);
});

// Contact Form Handling
const contactForm = document.getElementById('contactForm');

if (contactForm) {
  contactForm.addEventListener('submit', (e) => {
    e.preventDefault();
    
    // Get form values
    const formData = {
      name: document.getElementById('name').value,
      email: document.getElementById('email').value,
      service: document.getElementById('service').value,
      message: document.getElementById('message').value
    };
    
    // Here you would typically send the data to a server
    console.log('Form submitted:', formData);
    
    // Show success message (you can customize this)
    alert('Thank you for your message! We will get back to you soon.');
    
    // Reset form
    contactForm.reset();
  });
}

// Add active state to navigation links based on scroll position
const sections = document.querySelectorAll('section[id]');
const navLinks = document.querySelectorAll('.nav-link');

function highlightNavigation() {
  const scrollY = window.pageYOffset;
  
  sections.forEach(section => {
    const sectionHeight = section.offsetHeight;
    const sectionTop = section.offsetTop - 100;
    const sectionId = section.getAttribute('id');
    
    if (scrollY > sectionTop && scrollY <= sectionTop + sectionHeight) {
      navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href') === `#${sectionId}`) {
          link.classList.add('active');
        }
      });
    }
  });
}

window.addEventListener('scroll', highlightNavigation);

// Parallax effect for hero shapes
window.addEventListener('scroll', () => {
  const scrolled = window.pageYOffset;
  const shapes = document.querySelectorAll('.hero-shape');
  
  shapes.forEach((shape, index) => {
    const speed = (index + 1) * 0.1;
    shape.style.transform = `translateY(${scrolled * speed}px)`;
  });
});

// Animation for Skill Progress Bars
const skillBars = document.querySelectorAll('.skill-progress');
const skillObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const targetWidth = entry.target.dataset.width;
            if (targetWidth) {
                entry.target.style.width = targetWidth + '%';
            }
            skillObserver.unobserve(entry.target);
        }
    });
}, { threshold: 0.2 });

skillBars.forEach(bar => {
    // Get the width from the style attribute if it's there
    let targetWidth = bar.getAttribute('style');
    if (targetWidth && targetWidth.includes('width:')) {
        const match = targetWidth.match(/width:\s*(\d+)%/);
        if (match) {
            bar.dataset.width = match[1];
        }
    }
    bar.style.width = '0%'; // Start at 0
    skillObserver.observe(bar);
});

// Add hover effect to service cards
const serviceCards = document.querySelectorAll('.service-card');

serviceCards.forEach(card => {
  card.addEventListener('mouseenter', function() {
    this.style.borderColor = 'var(--color-primary)';
  });
  
  card.addEventListener('mouseleave', function() {
    this.style.borderColor = 'transparent';
  });
});

// Console welcome message
console.log('%cShanfix Technology', 'font-size: 24px; font-weight: bold; color: #22c55e;');
console.log('%cWebsite built with modern web technologies', 'font-size: 14px; color: #6b7280;');

// ===================================
// ADVERT CAROUSEL
// ===================================

const advertCarousel = document.querySelector('.advert-carousel');
if (advertCarousel) {
  let currentAdvertSlide = 0;
  const advertSlides = advertCarousel.querySelectorAll('.advert-slide');
  const advertIndicators = advertCarousel.querySelectorAll('.advert-indicator');
  const totalAdvertSlides = advertSlides.length;
  let advertAutoplayInterval;

  // Function to show specific slide
  function showAdvertSlide(index) {
    // Remove active class from all slides and indicators
    advertSlides.forEach(slide => slide.classList.remove('active'));
    advertIndicators.forEach(indicator => indicator.classList.remove('active'));
    
    // Add active class to current slide and indicator
    if (advertSlides[index]) advertSlides[index].classList.add('active');
    if (advertIndicators[index]) advertIndicators[index].classList.add('active');
    
    currentAdvertSlide = index;
  }

  // Function to go to next slide
  function nextAdvertSlide() {
    let next = (currentAdvertSlide + 1) % totalAdvertSlides;
    showAdvertSlide(next);
  }

  // Function to go to previous slide
  function prevAdvertSlide() {
    let prev = (currentAdvertSlide - 1 + totalAdvertSlides) % totalAdvertSlides;
    showAdvertSlide(prev);
  }

  // Autoplay carousel every 6 seconds (slightly different than hero to avoid sync)
  function startAdvertAutoplay() {
    advertAutoplayInterval = setInterval(nextAdvertSlide, 6000);
  }

  function stopAdvertAutoplay() {
    clearInterval(advertAutoplayInterval);
  }

  // Initialize carousel
  startAdvertAutoplay();

  // Pause autoplay on hover
  advertCarousel.addEventListener('mouseenter', stopAdvertAutoplay);
  advertCarousel.addEventListener('mouseleave', startAdvertAutoplay);

  // Navigation controls
  const advertPrevButton = advertCarousel.querySelector('.advert-control.prev');
  const advertNextButton = advertCarousel.querySelector('.advert-control.next');

  if (advertPrevButton) {
    advertPrevButton.addEventListener('click', () => {
      prevAdvertSlide();
      stopAdvertAutoplay();
      startAdvertAutoplay();
    });
  }

  if (advertNextButton) {
    advertNextButton.addEventListener('click', () => {
      nextAdvertSlide();
      stopAdvertAutoplay();
      startAdvertAutoplay();
    });
  }

  // Indicator navigation
  advertIndicators.forEach((indicator, index) => {
    indicator.addEventListener('click', () => {
      showAdvertSlide(index);
      stopAdvertAutoplay();
      startAdvertAutoplay();
    });
  });
}

// ===================================
// ORDER MODAL LOGIC
// ===================================

const orderModal = document.getElementById('orderModal');
const closeModalBtn = document.getElementById('closeModal');
const buyBtns = document.querySelectorAll('.open-order-modal');
const qtyInput = document.getElementById('qtyInput');
const qtyPlus = document.getElementById('qtyPlus');
const qtyMinus = document.getElementById('qtyMinus');
const placeOrderBtn = document.getElementById('placeOrderBtn');
const successOverlay = document.getElementById('successOverlay');
const continueShoppingBtn = document.getElementById('continueShopping');

// Modal elements to populate
const modalImage = document.getElementById('modalImage');
const modalTitle = document.getElementById('modalTitle');
const modalPrice = document.getElementById('modalPrice');
const modalDescription = document.getElementById('modalDescription');

if (orderModal) {
  // Open Modal
  buyBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      const card = this.closest('.product-card');
      const name = card.dataset.productName;
      const price = card.dataset.productPrice;
      const image = card.dataset.productImage;
      const description = card.dataset.productDescription;

      modalTitle.textContent = name;
      modalPrice.innerHTML = `<span>KES</span> ${price}`;
      modalImage.src = image;
      modalImage.alt = name;
      modalDescription.textContent = description;
      
      qtyInput.value = 1;
      orderModal.classList.add('active');
      document.body.style.overflow = 'hidden'; // Stop background scroll
    });
  });

  // Close Modal
  function closeOrderModal() {
    orderModal.classList.remove('active');
    successOverlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  closeModalBtn.addEventListener('click', closeOrderModal);

  // Close on backdrop click
  orderModal.addEventListener('click', (e) => {
    if (e.target === orderModal) closeOrderModal();
  });

  // Quantity Controls
  if (qtyPlus) {
    qtyPlus.addEventListener('click', () => {
      qtyInput.value = parseInt(qtyInput.value) + 1;
    });
  }

  if (qtyMinus) {
    qtyMinus.addEventListener('click', () => {
      if (parseInt(qtyInput.value) > 1) {
        qtyInput.value = parseInt(qtyInput.value) - 1;
      }
    });
  }

  // Handle Place Order Simulation
  if (placeOrderBtn) {
    placeOrderBtn.addEventListener('click', () => {
      successOverlay.classList.add('active');
    });
  }

  if (continueShoppingBtn) {
    continueShoppingBtn.addEventListener('click', closeOrderModal);
  }
}

// ===================================
// DYNAMIC CATEGORIZED CATALOG
// ===================================

async function renderCategorizedCatalog() {
    const catalogContainer = document.getElementById('dynamicCatalog');
    if (!catalogContainer) return;

    // Show loading state
    catalogContainer.innerHTML = `
        <div class="loading-catalog" style="text-align: center; padding: 50px;">
            <div class="spinner" style="margin-bottom: 20px;"><i class="fas fa-circle-notch fa-spin fa-3x"></i></div>
            <p>Loading our premium catalog...</p>
        </div>
    `;

    try {
        // Fetch categories and products in parallel
        // The stockroom. This page used to sell from a product table of
        // the site's own, kept by hand in an admin that no longer
        // exists; it sells from the system's inventory now, so there is
        // one catalogue, one price and one place a photograph lives.
        const res  = await fetch('api/inventory.php');
        const data = await res.json();

        if (!data.success) {
            throw new Error('Failed to load catalog data');
        }

        const categories = data.categories.map(c => c.name);
        const products = data.products;

        if (products.length === 0) {
            catalogContainer.innerHTML = `
                <div class="empty-catalog" data-aos="fade-up">
                    <i class="fas fa-box-open"></i>
                    <p>Our premium catalog is currently being updated. Please check back soon!</p>
                </div>
            `;
            return;
        }

        // Group products by category name
        const grouped = {};
        categories.forEach(cat => grouped[cat] = []);
        
        products.forEach(p => {
            const catName = p.category_name;
            if (!grouped[catName]) grouped[catName] = [];
            grouped[catName].push(p);
        });

        // Helper: split array into chunks of N
        const chunk = (arr, n) => {
            const out = [];
            for (let i = 0; i < arr.length; i += n) out.push(arr.slice(i, i + n));
            return out;
        };

        // Build one product card HTML
        const buildCard = (p, cat) => `
            <div class="product-card"
                 data-product-id="${p.id}"
                 data-product-name="${p.name.replace(/"/g, '&quot;')}"
                 data-product-price="${p.price}"
                 data-product-image="${(p.image_url || '').replace(/"/g, '&quot;')}"
                 data-product-images="${encodeURIComponent(JSON.stringify([p.image_url, ...(p.additional_images || [])].filter(Boolean)))}"
                 data-product-description="${(p.description || '').replace(/"/g, '&quot;')}">
                <div class="product-image-wrapper">
                    <img src="${p.image_url || 'assets/service-placeholder.jpg'}"
                         alt="${p.name.replace(/"/g, '&quot;')}"
                         class="product-image"
                         loading="lazy"
                         onerror="this.src='assets/service-placeholder.jpg'">
                    <div class="product-overlay">
                        <div class="overlay-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </div>
                    </div>
                </div>
                <div class="product-content">
                    <span class="product-category">${cat}</span>
                    <h3 class="product-title">${p.name}</h3>
                    <p class="product-description">${(p.description || '').substring(0, 100)}${(p.description || '').length > 100 ? '…' : ''}</p>
                    <div class="product-footer">
                        <div class="product-price"><span>KES</span> ${Number(p.price).toLocaleString()}</div>
                        <button class="btn btn-primary btn-buy open-order-modal">Order Now</button>
                    </div>
                </div>
            </div>`;

        // Build carousel HTML per category
        const ITEMS_PER_SLIDE = 4;
        let catalogHtml = '';

        categories.forEach(cat => {
            const catProducts = grouped[cat];
            if (!catProducts || catProducts.length === 0) return;

            const slides     = chunk(catProducts, ITEMS_PER_SLIDE);
            const hasCarousel = slides.length > 1;

            const slidesHtml = slides.map(slideProducts => `
                <div class="carousel-slide">
                    ${slideProducts.map(p => buildCard(p, cat)).join('')}
                </div>`).join('');

            const dotsHtml = hasCarousel
                ? `<div class="cat-carousel-dots" role="tablist">
                       ${slides.map((_, i) => `<button class="cat-dot${i === 0 ? ' active' : ''}" data-index="${i}" aria-label="Go to slide ${i + 1}"></button>`).join('')}
                   </div>`
                : '';

            const controlsHtml = hasCarousel
                ? `<div class="cat-carousel-controls">
                       <span class="cat-slide-counter">1 / ${slides.length}</span>
                       <button class="cat-nav-btn cat-prev-btn" aria-label="Previous slide">
                           <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                       </button>
                       <button class="cat-nav-btn cat-next-btn" aria-label="Next slide">
                           <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                       </button>
                   </div>`
                : '';

            catalogHtml += `
                <div class="catalog-category-section" data-aos="fade-up">
                    <div class="category-header-row">
                        <h2 class="category-heading">${cat}</h2>
                        ${controlsHtml}
                    </div>
                    <div class="product-carousel-wrapper">
                        <div class="product-carousel-track" data-total="${slides.length}">
                            ${slidesHtml}
                        </div>
                    </div>
                    ${hasCarousel ? '<div class="cat-progress-bar"><div class="cat-progress-fill"></div></div>' : ''}
                    ${dotsHtml}
                </div>`;
        });

        if (!catalogHtml) {
            catalogContainer.innerHTML = `
                <div class="empty-catalog" data-aos="fade-up">
                    <i class="fas fa-box-open"></i>
                    <p>No products available in the selected categories.</p>
                </div>`;
        } else {
            catalogContainer.innerHTML = catalogHtml;
        }

        // Wire up Order Now buttons and carousels
        initOrderModalTriggers();
        initCategoryCarousels();

        if (window.AOS) AOS.refresh();

    } catch (error) {
        console.error('Catalog loading error:', error);
        catalogContainer.innerHTML = `
            <div class="error-catalog" style="text-align: center; padding: 50px; color: #dc3545;">
                <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 20px;"></i>
                <p>Unable to load the catalog. Please try refreshing the page.</p>
            </div>
        `;
    }
}

// ── Category Carousels ────────────────────────────────────────────────────

function initCategoryCarousels() {
    const AUTOPLAY_MS = 4000; // ms between slides

    document.querySelectorAll('.catalog-category-section').forEach(section => {
        const track    = section.querySelector('.product-carousel-track');
        const wrapper  = section.querySelector('.product-carousel-wrapper');
        const prevBtn  = section.querySelector('.cat-prev-btn');
        const nextBtn  = section.querySelector('.cat-next-btn');
        const counter  = section.querySelector('.cat-slide-counter');
        const dots     = section.querySelectorAll('.cat-dot');
        const fill     = section.querySelector('.cat-progress-fill');
        if (!track || !wrapper) return;

        const total  = parseInt(track.dataset.total) || 1;
        if (total <= 1) return;          // nothing to carousel

        let current  = 0;
        let timer    = null;
        let progress = null;

        /* ── Core navigation ──────────────────────────────────────── */
        function goTo(index, resetProgress = true) {
            current = (index + total) % total;

            // Slide the track
            track.style.transform = `translateX(calc(-${current} * 100%))`;

            // Update counter
            if (counter) counter.textContent = `${current + 1} / ${total}`;

            // Update dots
            dots.forEach((d, i) => d.classList.toggle('active', i === current));

            // Restart progress bar
            if (resetProgress && fill) {
                fill.style.transition = 'none';
                fill.style.width = '0%';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    fill.style.transition = `width ${AUTOPLAY_MS}ms linear`;
                    fill.style.width = '100%';
                }));
            }
        }

        /* ── Autoplay ─────────────────────────────────────────────── */
        function startAutoplay() {
            stopAutoplay();
            // Kick off progress bar
            if (fill) {
                fill.style.transition = `width ${AUTOPLAY_MS}ms linear`;
                fill.style.width = '100%';
            }
            timer = setInterval(() => goTo(current + 1), AUTOPLAY_MS);
        }

        function stopAutoplay() {
            if (timer)    { clearInterval(timer);    timer    = null; }
            if (progress) { clearTimeout(progress);  progress = null; }
            // Freeze progress bar width wherever it is
            if (fill) {
                const computed = getComputedStyle(fill).width;
                const parentW  = fill.parentElement.offsetWidth;
                const pct      = parentW > 0 ? (parseFloat(computed) / parentW * 100).toFixed(1) : '0';
                fill.style.transition = 'none';
                fill.style.width = pct + '%';
            }
        }

        /* ── Event listeners ──────────────────────────────────────── */
        prevBtn.addEventListener('click', () => { goTo(current - 1); stopAutoplay(); startAutoplay(); });
        nextBtn.addEventListener('click', () => { goTo(current + 1); stopAutoplay(); startAutoplay(); });

        dots.forEach(dot => {
            dot.addEventListener('click', () => {
                goTo(parseInt(dot.dataset.index));
                stopAutoplay();
                startAutoplay();
            });
        });

        // Pause on hover — resume when mouse leaves the wrapper
        wrapper.addEventListener('mouseenter', stopAutoplay);
        wrapper.addEventListener('mouseleave', startAutoplay);

        // Touch / swipe support
        let touchX = 0;
        wrapper.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; stopAutoplay(); }, { passive: true });
        wrapper.addEventListener('touchend', e => {
            const diff = touchX - e.changedTouches[0].clientX;
            if (Math.abs(diff) > 48) goTo(diff > 0 ? current + 1 : current - 1);
            startAutoplay();
        }, { passive: true });

        // Keyboard left / right when focused inside
        section.addEventListener('keydown', e => {
            if (e.key === 'ArrowLeft')  { goTo(current - 1); stopAutoplay(); startAutoplay(); }
            if (e.key === 'ArrowRight') { goTo(current + 1); stopAutoplay(); startAutoplay(); }
        });

        // Boot up
        goTo(0, false);
        startAutoplay();
    });
}

// ── Gallery state ────────────────────────────────────────────────────────
let _galleryImages  = [];
let _galleryIndex   = 0;

function _setGalleryImage(index) {
    const modalImage    = document.getElementById('modalImage');
    const counter       = document.getElementById('galleryCounter');
    const thumbnails    = document.getElementById('modalThumbnails');
    if (!modalImage || !_galleryImages.length) return;

    _galleryIndex = (index + _galleryImages.length) % _galleryImages.length;
    const src = _galleryImages[_galleryIndex];

    // Fade transition
    modalImage.style.opacity = '0';
    setTimeout(() => {
        modalImage.src = src || 'assets/service-placeholder.jpg';
        modalImage.style.opacity = '1';
    }, 150);

    // Counter
    if (counter) {
        counter.textContent = `${_galleryIndex + 1} / ${_galleryImages.length}`;
    }

    // Highlight active thumbnail
    if (thumbnails) {
        thumbnails.querySelectorAll('.gallery-thumb').forEach((th, i) => {
            th.classList.toggle('active', i === _galleryIndex);
        });
    }
}

function _buildGallery(images, name) {
    const modalImage  = document.getElementById('modalImage');
    const thumbStrip  = document.getElementById('modalThumbnails');
    const prevBtn     = document.getElementById('galleryPrev');
    const nextBtn     = document.getElementById('galleryNext');
    const counter     = document.getElementById('galleryCounter');

    _galleryImages = images.filter(Boolean);
    _galleryIndex  = 0;

    // Main image
    if (modalImage) {
        modalImage.style.transition = 'opacity 0.15s ease';
        modalImage.src = _galleryImages[0] || 'assets/service-placeholder.jpg';
        modalImage.alt = name;
    }

    const hasMultiple = _galleryImages.length > 1;

    // Nav arrows
    if (prevBtn) prevBtn.style.display = hasMultiple ? 'flex' : 'none';
    if (nextBtn) nextBtn.style.display = hasMultiple ? 'flex' : 'none';
    if (counter) {
        counter.style.display = hasMultiple ? 'block' : 'none';
        counter.textContent   = `1 / ${_galleryImages.length}`;
    }

    // Thumbnails
    if (thumbStrip) {
        if (hasMultiple) {
            thumbStrip.innerHTML = _galleryImages.map((src, i) => `
                <button class="gallery-thumb${i === 0 ? ' active' : ''}"
                        style="background-image:url('${src || 'assets/service-placeholder.jpg'}')"
                        data-index="${i}"
                        aria-label="View image ${i + 1}">
                </button>
            `).join('');
            thumbStrip.querySelectorAll('.gallery-thumb').forEach(th => {
                th.addEventListener('click', () => _setGalleryImage(parseInt(th.dataset.index)));
            });
        } else {
            thumbStrip.innerHTML = '';
        }
    }
}

// Opens the order/preview modal from any product card element
function openProductModal(card) {
    if (!card) return;
    const name        = card.dataset.productName        || '';
    const price       = card.dataset.productPrice       || '0';
    const description = card.dataset.productDescription || '';
    const orderModal  = document.getElementById('orderModal');
    const qtyInput    = document.getElementById('qtyInput');

    let images = [];
    try {
        images = JSON.parse(decodeURIComponent(card.dataset.productImages || '[]'));
    } catch (e) {
        const fallback = card.dataset.productImage;
        images = fallback ? [fallback] : [];
    }
    if (!images.length && card.dataset.productImage) images = [card.dataset.productImage];

    const el = id => document.getElementById(id);
    if (el('modalTitle'))       el('modalTitle').textContent       = name;
    if (el('modalPrice'))       el('modalPrice').innerHTML         = `<span>KES</span> ${parseFloat(price).toLocaleString()}`;
    if (el('modalDescription')) el('modalDescription').textContent = description;
    if (qtyInput)               qtyInput.value = 1;

    _buildGallery(images, name);

    const prevBtn = el('galleryPrev');
    const nextBtn = el('galleryNext');
    if (prevBtn) prevBtn.onclick = () => _setGalleryImage(_galleryIndex - 1);
    if (nextBtn) nextBtn.onclick = () => _setGalleryImage(_galleryIndex + 1);

    if (orderModal) {
        orderModal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function initOrderModalTriggers() {
    // Clicking anywhere on the card (including the Order Now button) opens the modal
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', function(e) {
            // Ignore clicks on the Order Now button itself — it bubbles up anyway,
            // but prevent any other interactive element inside the card from double-firing
            openProductModal(this);
        });
    });

    // Keyboard navigation for image gallery while modal is open
    document.addEventListener('keydown', (e) => {
        const modal = document.getElementById('orderModal');
        if (!modal || !modal.classList.contains('active') || _galleryImages.length < 2) return;
        if (e.key === 'ArrowLeft')  _setGalleryImage(_galleryIndex - 1);
        if (e.key === 'ArrowRight') _setGalleryImage(_galleryIndex + 1);
    });
}

// Initialize dynamic catalog if container exists
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('dynamicCatalog')) {
        renderCategorizedCatalog();
    }
});
// ===================================
// HOSTING CHECKOUT LOGIC
// ===================================

const checkoutModal = document.getElementById('checkoutModal');
const checkoutForm = document.getElementById('checkoutForm');
const steps = document.querySelectorAll('.checkout-step');
const stepIndicators = document.querySelectorAll('.step-indicator .step');
const nextBtns = document.querySelectorAll('.next-step');
const prevBtns = document.querySelectorAll('.prev-step');
let currentCheckoutStep = 1;

// Function to open checkout modal
function openCheckout(packageName, packagePrice) {
    const displayPackageName = document.getElementById('displayPackageName');
    const displayPackagePrice = document.getElementById('displayPackagePrice');
    
    if (displayPackageName) displayPackageName.textContent = packageName;
    if (displayPackagePrice) displayPackagePrice.textContent = packagePrice;
    
    // Reset to step 1
    currentCheckoutStep = 1;
    updateStepVisibility();
    
    if (checkoutModal) {
        checkoutModal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

// Update step visibility and indicators
function updateStepVisibility() {
    steps.forEach((step, index) => {
        step.classList.toggle('active', index + 1 === currentCheckoutStep);
    });
    
    stepIndicators.forEach((indicator, index) => {
        indicator.classList.toggle('active', index + 1 <= currentCheckoutStep);
    });
}

// Navigation Listeners
nextBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        if (validateStep(currentCheckoutStep)) {
            currentCheckoutStep++;
            updateStepVisibility();
        }
    });
});

prevBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        currentCheckoutStep--;
        updateStepVisibility();
    });
});

// Basic step validation
function validateStep(step) {
    if (step === 1) {
        const confirmCheck = document.getElementById('confirmPackage');
        if (!confirmCheck.checked) {
            alert('Please confirm your package selection.');
            return false;
        }
    } else if (step === 2) {
        const requiredInputs = steps[1].querySelectorAll('[required]');
        let valid = true;
        requiredInputs.forEach(input => {
            if (!input.value.trim()) {
                input.style.borderColor = '#ef4444';
                valid = false;
            } else {
                input.style.borderColor = 'rgba(255, 255, 255, 0.1)';
            }
        });
        
        // Password match check
        const password = document.getElementById('regPassword').value;
        const confirm = document.getElementById('confirmPassword').value;
        if (password !== confirm) {
            alert('Passwords do not match!');
            valid = false;
        }
        
        if (!valid) alert('Please fill in all required fields.');
        return valid;
    }
    return true;
}

// Payment method toggle
const paymentRadios = document.querySelectorAll('input[name="payment_method"]');
paymentRadios.forEach(radio => {
    radio.addEventListener('change', (e) => {
        const bankDetails = document.getElementById('bankDetails');
        const mpesaDetails = document.getElementById('mpesaDetails');
        
        if (e.target.value === 'bank') {
            bankDetails.classList.remove('hidden');
            mpesaDetails.classList.add('hidden');
        } else {
            bankDetails.classList.add('hidden');
            mpesaDetails.classList.remove('hidden');
        }
    });
});

// Close modal
const closeCheckoutBtn = document.querySelector('.checkout-modal .close-modal');
if (closeCheckoutBtn) {
    closeCheckoutBtn.addEventListener('click', () => {
        checkoutModal.classList.remove('active');
        document.body.style.overflow = '';
    });
}

// Form Submission
if (checkoutForm) {
    checkoutForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submitBtn = document.getElementById('submitOrder');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Processing...';
        
        const formData = new FormData(checkoutForm);
        // Add package details from the display elements
        formData.append('package_name', document.getElementById('displayPackageName').textContent);
        formData.append('package_price', document.getElementById('displayPackagePrice').textContent);
        
        try {
            const response = await fetch('process_checkout.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                alert('Success! Your order has been placed and is awaiting approval. You will be redirected to your dashboard.');
                window.location.href = 'client-area.php'; // Redirect to client area
            } else {
                alert('Error: ' + result.message);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Complete Purchase';
            }
        } catch (error) {
            console.error('Checkout error:', error);
            alert('An unexpected error occurred. Please try again.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Complete Purchase';
        }
    });
}

// Attach to hosting buttons
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('open-checkout-modal')) {
        e.preventDefault();
        const packageName = e.target.dataset.packageName;
        const packagePrice = e.target.dataset.packagePrice;
        openCheckout(packageName, packagePrice);
    }
});
