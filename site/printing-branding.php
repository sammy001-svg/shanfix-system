<?php
$pageSEO = [
    'title'       => 'Printing & Branding Services in Nairobi | Shanfix Technology',
    'description' => 'High-quality printing and branding services in Nairobi — business cards, flyers, banners, branded merchandise, and corporate stationery.',
    'keywords'    => 'printing services Nairobi, branding Kenya, business cards, flyers, banners printing',
    'canonical'   => 'https://shanfixtechnology.com/printing-branding.php',
    'json_ld'     => '{"@context":"https://schema.org","@type":"Service","name":"Printing & Branding","description":"High-quality printing and branding — business cards, flyers, banners, and corporate stationery in Nairobi.","provider":{"@type":"LocalBusiness","name":"Shanfix Technology","url":"https://shanfixtechnology.com/"},"areaServed":{"@type":"City","name":"Nairobi"},"url":"https://shanfixtechnology.com/printing-branding.php"}',
];
include 'includes/header.php'; ?>
    <!-- Hero Section with Carousel -->
    <section class="hero printing-hero" id="home">
      <div class="hero-carousel print-carousel">
        <!-- Slide 1 -->
        <div class="hero-slide active">
          <div class="hero-slide-bg print-bg-1" style="background: url('assets/print-slide-1.png') no-repeat center center; background-size: cover;"></div>
          <div class="hero-slide-overlay"></div>
          <div class="container hero-container">
            <div class="hero-content">
              <h1 class="hero-title">
                Precision <span>Printing Solutions</span>
              </h1>
              <p class="hero-subtitle">
                Delivering high-quality, professional print materials that make your business stand out from the crowd.
              </p>
              <div class="hero-buttons">
                <a href="#products" class="btn btn-primary">View Catalog</a>
                <a href="contact.php" class="btn btn-secondary">Get a Quote</a>
              </div>
            </div>
          </div>
        </div>

        <!-- Slide 2 -->
        <div class="hero-slide">
          <div class="hero-slide-bg print-bg-2" style="background: url('assets/print-slide-2.png') no-repeat center center; background-size: cover;"></div>
          <div class="hero-slide-overlay"></div>
          <div class="container hero-container">
            <div class="hero-content">
              <h1 class="hero-title">
                Elite <span>Corporate Branding</span>
              </h1>
              <p class="hero-subtitle">
                Transform your brand identity with premium business cards, letterheads, and cohesive corporate suites.
              </p>
              <div class="hero-buttons">
                <a href="contact.php" class="btn btn-primary">Start Branding</a>
                <a href="#products" class="btn btn-secondary">Our Products</a>
              </div>
            </div>
          </div>
        </div>

        <!-- Slide 3 -->
        <div class="hero-slide">
          <div class="hero-slide-bg print-bg-3" style="background: url('assets/print-slide-3.png') no-repeat center center; background-size: cover;"></div>
          <div class="hero-slide-overlay"></div>
          <div class="container hero-container">
            <div class="hero-content">
              <h1 class="hero-title">
                Vibrant <span>Merchandise</span>
              </h1>
              <p class="hero-subtitle">
                Custom branded apparel, hoodies, and promotional gifts designed to build lasting brand loyalty.
              </p>
              <div class="hero-buttons">
                <a href="contact.php" class="btn btn-primary">Order Merchandise</a>
                <a href="#products" class="btn btn-secondary">Browse Options</a>
              </div>
            </div>
          </div>
        </div>

        <!-- Carousel Controls -->
        <button class="carousel-control prev" aria-label="Previous slide">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <button class="carousel-control next" aria-label="Next slide">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>

        <!-- Carousel Indicators -->
        <div class="carousel-indicators">
          <button class="indicator active" data-slide="0" aria-label="Go to slide 1"></button>
          <button class="indicator" data-slide="1" aria-label="Go to slide 2"></button>
          <button class="indicator" data-slide="2" aria-label="Go to slide 3"></button>
        </div>
      </div>
    </section>

    <link rel="stylesheet" href="<?= site_asset('./printing-modern.css') ?>">

    <section class="service-detail-section" id="products">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title" data-aos="fade-up">Premium Printing & Branding Catalog</h2>
                <p class="section-subtitle" data-aos="fade-up" data-aos-delay="100">Explore our high-quality branding solutions tailored for your business success.</p>
            </div>
            <!-- Dynamic Catalog Container -->
            <div id="dynamicCatalog">
                <div class="catalog-loading">
                    <div class="loader"></div>
                    <p>Loading premium catalog...</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Floating Cart Button ───────────────────────────────────── -->
    <button id="cartFloatBtn" aria-label="Open cart" style="display:none;">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <span id="cartBadge">0</span>
    </button>

    <!-- ── Side Cart Drawer ───────────────────────────────────────── -->
    <div id="cartOverlay"></div>
    <aside id="cartDrawer" aria-label="Shopping cart">
        <div class="cart-header">
            <h3><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg> Your Cart</h3>
            <button id="cartClose" aria-label="Close cart">&times;</button>
        </div>

        <div id="cartItems" class="cart-items-list">
            <p class="cart-empty-msg">Your cart is empty.<br>Browse the catalog and add items.</p>
        </div>

        <div class="cart-footer" id="cartFooter" style="display:none;">
            <div class="cart-summary">
                <span>Subtotal (<span id="cartCount">0</span> item<span id="cartCountPlural">s</span>)</span>
                <strong>KES <span id="cartTotal">0</span></strong>
            </div>
            <button class="cart-checkout-btn" id="cartCheckoutBtn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Proceed to Checkout
            </button>
            <button class="cart-clear-btn" id="cartClearBtn">Clear cart</button>
        </div>

        <!-- ── Checkout Form (inside drawer) ──────────────────────── -->
        <div id="checkoutPane" class="checkout-pane" style="display:none;">
            <button class="checkout-back-btn" id="checkoutBack">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
                Back to cart
            </button>
            <h4 class="checkout-pane-title">Complete Your Order</h4>

            <div class="checkout-order-summary" id="checkoutSummary"></div>

            <form id="printingCheckoutForm" novalidate>
                <div class="co-field">
                    <label>Full Name <span>*</span></label>
                    <input type="text" id="co_name" placeholder="John Doe" required>
                </div>
                <div class="co-field">
                    <label>Email Address <span>*</span></label>
                    <input type="email" id="co_email" placeholder="john@example.com" required>
                </div>
                <div class="co-field">
                    <label>Phone Number <span>*</span></label>
                    <input type="tel" id="co_phone" placeholder="0712 345 678" required>
                </div>
                <div class="co-field">
                    <label>M-PESA Number <span>*</span></label>
                    <input type="tel" id="co_mpesa" placeholder="07XX XXX XXX (Safaricom)" required>
                    <small>An STK push will be sent to this number</small>
                </div>

                <button type="submit" class="cart-checkout-btn" id="coSubmitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M8 12l2.5 2.5L16 9"/></svg>
                    Pay via M-PESA
                </button>
            </form>

            <!-- STK waiting state -->
            <div id="stkWaiting" style="display:none; text-align:center; padding:24px 16px;">
                <div class="stk-spinner"></div>
                <p id="stkWaitMsg" style="color:#1e293b; font-weight:600; margin:12px 0 4px;">Waiting for payment…</p>
                <small style="color:#64748b;">Check your phone and enter your M-PESA PIN</small>
                <button id="stkCancelBtn" style="display:block; margin:16px auto 0; background:none; border:1px solid #e2e8f0; border-radius:8px; padding:8px 20px; color:#64748b; cursor:pointer; font-size:0.82rem;">Cancel</button>
            </div>

            <!-- Success state -->
            <div id="coSuccess" style="display:none; text-align:center; padding:24px 16px;">
                <div class="co-success-icon">✓</div>
                <h4 style="color:#166534; margin:12px 0 6px;">Payment Received!</h4>
                <p style="color:#4b5563; font-size:0.875rem;">Thank you! Your order has been recorded. We'll contact you shortly.</p>
                <button id="coSuccessClose" class="cart-checkout-btn" style="margin-top:16px;">Continue Shopping</button>
            </div>
        </div>
    </aside>

    <style>
    /* ── Floating cart button ──────────────────────────────────── */
    #cartFloatBtn {
        position: fixed;
        bottom: 32px;
        right: 32px;
        z-index: 8000;
        width: 58px;
        height: 58px;
        border-radius: 50%;
        background: linear-gradient(135deg, #083f0c, #0a5210);
        color: #fff;
        border: none;
        cursor: pointer;
        box-shadow: 0 6px 24px rgba(8,63,12,0.45);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.25s, box-shadow 0.25s;
    }
    #cartFloatBtn:hover { transform: scale(1.1); box-shadow: 0 10px 32px rgba(8,63,12,0.55); }
    #cartBadge {
        position: absolute;
        top: -4px;
        right: -4px;
        background: #ef4444;
        color: #fff;
        font-size: 0.7rem;
        font-weight: 800;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #fff;
    }

    /* ── Overlay ───────────────────────────────────────────────── */
    #cartOverlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.45);
        z-index: 8100;
        backdrop-filter: blur(2px);
    }
    #cartOverlay.active { display: block; }

    /* ── Drawer ────────────────────────────────────────────────── */
    #cartDrawer {
        position: fixed;
        top: 0;
        right: 0;
        height: 100dvh;
        width: 420px;
        max-width: 100vw;
        background: #fff;
        z-index: 8200;
        transform: translateX(100%);
        transition: transform 0.38s cubic-bezier(0.4,0,0.2,1);
        display: flex;
        flex-direction: column;
        box-shadow: -8px 0 40px rgba(0,0,0,0.18);
        overflow: hidden;
    }
    #cartDrawer.open { transform: translateX(0); }

    /* Header */
    .cart-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 18px 20px;
        border-bottom: 1px solid #e9ecef;
        background: #f8fafc;
        flex-shrink: 0;
    }
    .cart-header h3 {
        font-size: 1.05rem;
        font-weight: 800;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0;
    }
    #cartClose {
        background: none;
        border: none;
        font-size: 1.6rem;
        color: #94a3b8;
        cursor: pointer;
        line-height: 1;
        padding: 0;
    }
    #cartClose:hover { color: #1e293b; }

    /* Items list */
    .cart-items-list {
        flex: 1;
        overflow-y: auto;
        padding: 16px 20px;
    }
    .cart-empty-msg {
        text-align: center;
        color: #94a3b8;
        padding: 48px 0;
        font-size: 0.9rem;
        line-height: 1.7;
    }

    /* Single cart item row */
    .cart-item {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .cart-item:last-child { border-bottom: none; }
    .cart-item-img {
        width: 64px;
        height: 64px;
        border-radius: 10px;
        object-fit: contain;
        background: #f8fafc;
        border: 1px solid #e9ecef;
        flex-shrink: 0;
        padding: 4px;
    }
    .cart-item-body { flex: 1; min-width: 0; }
    .cart-item-name { font-weight: 700; color: #1e293b; font-size: 0.875rem; margin-bottom: 2px; }
    .cart-item-price { color: #22c55e; font-weight: 700; font-size: 0.82rem; margin-bottom: 8px; }
    .cart-qty-row { display: flex; align-items: center; gap: 6px; }
    .cart-qty-btn {
        width: 26px; height: 26px;
        border-radius: 6px;
        border: 1px solid #d1d5db;
        background: #f9fafb;
        color: #374151;
        font-weight: 700;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem;
        line-height: 1;
        transition: background .15s;
    }
    .cart-qty-btn:hover { background: #e5e7eb; }
    .cart-qty-val { font-weight: 700; font-size: 0.875rem; color: #1e293b; min-width: 22px; text-align: center; }
    .cart-item-remove { color: #ef4444; font-size: 0.78rem; cursor: pointer; border: none; background: none; padding: 0; margin-left: auto; align-self: flex-start; }

    /* Footer */
    .cart-footer {
        padding: 16px 20px 20px;
        border-top: 1px solid #e9ecef;
        background: #f8fafc;
        flex-shrink: 0;
    }
    .cart-summary {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.9rem;
        color: #374151;
        margin-bottom: 14px;
    }
    .cart-summary strong { font-size: 1.05rem; color: #083f0c; }
    .cart-checkout-btn {
        width: 100%;
        padding: 13px;
        background: linear-gradient(135deg, #083f0c, #166534);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: opacity .2s, transform .15s;
    }
    .cart-checkout-btn:hover { opacity: .9; transform: translateY(-1px); }
    .cart-checkout-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
    .cart-clear-btn {
        width: 100%;
        margin-top: 8px;
        padding: 9px;
        background: none;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.8rem;
        color: #94a3b8;
        cursor: pointer;
        transition: color .2s, border-color .2s;
    }
    .cart-clear-btn:hover { color: #ef4444; border-color: #fca5a5; }

    /* ── Checkout pane (inside drawer) ───────────────────────── */
    .checkout-pane { flex: 1; overflow-y: auto; padding: 16px 20px 24px; }
    .checkout-back-btn {
        background: none;
        border: none;
        display: flex;
        align-items: center;
        gap: 6px;
        color: #64748b;
        font-size: 0.82rem;
        cursor: pointer;
        padding: 0;
        margin-bottom: 14px;
    }
    .checkout-back-btn:hover { color: #083f0c; }
    .checkout-pane-title { font-size: 1rem; font-weight: 800; color: #1e293b; margin: 0 0 14px; }
    .checkout-order-summary {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 18px;
        font-size: 0.82rem;
        color: #166534;
        max-height: 110px;
        overflow-y: auto;
    }
    .co-sum-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
    .co-sum-row:last-child { border-top: 1px solid #bbf7d0; margin-top: 6px; padding-top: 6px; font-weight: 800; }
    .co-field { margin-bottom: 14px; }
    .co-field label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 5px; }
    .co-field label span { color: #ef4444; }
    .co-field input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 9px;
        font-size: 0.875rem;
        color: #1e293b;
        outline: none;
        box-sizing: border-box;
        transition: border-color .2s;
    }
    .co-field input:focus { border-color: #22c55e; }
    .co-field small { display: block; color: #94a3b8; font-size: 0.75rem; margin-top: 4px; }

    /* STK spinner */
    .stk-spinner {
        width: 48px; height: 48px;
        border: 4px solid #e9ecef;
        border-top-color: #22c55e;
        border-radius: 50%;
        animation: cartSpin .8s linear infinite;
        margin: 0 auto;
    }
    @keyframes cartSpin { to { transform: rotate(360deg); } }

    /* Success icon */
    .co-success-icon {
        width: 56px; height: 56px;
        background: #22c55e;
        color: #fff;
        border-radius: 50%;
        font-size: 1.8rem;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 4px;
    }

    /* Modal override: make "Add to Cart" the primary action */
    #placeOrderBtn { display: none !important; }

    .btn-add-to-cart {
        width: 100%;
        padding: 13px;
        background: linear-gradient(135deg, #083f0c, #166534);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-size: 0.92rem;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        transition: opacity .2s;
    }
    .btn-add-to-cart:hover { opacity: .9; }

    @media (max-width: 480px) {
        #cartDrawer { width: 100vw; }
        #cartFloatBtn { bottom: 20px; right: 16px; width: 52px; height: 52px; }
    }
    </style>

    <!-- Order Modal -->
    <div class="modal" id="orderModal">
        <div class="modal-container">
            <button class="modal-close" id="closeModal" aria-label="Close Order Modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
            
            <div class="modal-product-layout">
                <div class="modal-product-image-side">
                    <!-- Main image display -->
                    <div class="modal-gallery-main">
                        <img src="" alt="" id="modalImage">
                        <button class="gallery-nav-btn gallery-prev" id="galleryPrev" aria-label="Previous image" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                        </button>
                        <button class="gallery-nav-btn gallery-next" id="galleryNext" aria-label="Next image" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="gallery-counter" id="galleryCounter" style="display:none;"></div>
                    </div>
                    <!-- Thumbnail strip -->
                    <div class="modal-thumbnails" id="modalThumbnails"></div>
                </div>
                <div class="modal-product-content-side">
                    <h2 class="modal-product-title" id="modalTitle">Product Name</h2>
                    <div class="modal-product-price" id="modalPrice">KES 0</div>
                    <p class="modal-product-description" id="modalDescription">Detailed product description goes here...</p>
                    
                    <div class="quantity-controller">
                        <span class="quantity-label">Quantity:</span>
                        <div class="quantity-selector">
                            <button class="qty-btn" id="qtyMinus" aria-label="Decrease quantity">-</button>
                            <input type="number" class="qty-input" value="1" min="1" id="qtyInput" aria-label="Order quantity">
                            <button class="qty-btn" id="qtyPlus" aria-label="Increase quantity">+</button>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button class="btn btn-primary btn-place-order" id="placeOrderBtn">Place Order</button>
                        <button class="btn-add-to-cart" id="addToCartBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            Add to Cart
                        </button>
                    </div>
                </div>
            </div>

            <!-- Success Overlay -->
            <div class="order-success-overlay" id="successOverlay">
                <div class="success-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </div>
                <h2>Order Placed Successfully!</h2>
                <p>Thank you for choosing Shanfix Technology. Our team will contact you shortly to finalize your order details.</p>
                <button class="btn btn-primary btn-continue-shopping" id="continueShopping">Continue Shopping</button>
            </div>
        </div>
    </div>
<?php $ctaService = 'Printing & Branding'; include 'includes/service_cta.php'; ?>

<script>
(function () {
    /* ── Cart state ──────────────────────────────────────────────── */
    let cart = []; // [{id, name, price, image, qty}]
    let currentModalProduct = null;
    let stkPollTimer = null;

    const floatBtn     = document.getElementById('cartFloatBtn');
    const cartOverlay  = document.getElementById('cartOverlay');
    const cartDrawer   = document.getElementById('cartDrawer');
    const cartClose    = document.getElementById('cartClose');
    const cartItemsEl  = document.getElementById('cartItems');
    const cartFooter   = document.getElementById('cartFooter');
    const cartBadge    = document.getElementById('cartBadge');
    const cartCountEl  = document.getElementById('cartCount');
    const cartPluralEl = document.getElementById('cartCountPlural');
    const cartTotalEl  = document.getElementById('cartTotal');
    const checkoutBtn  = document.getElementById('cartCheckoutBtn');
    const clearBtn     = document.getElementById('cartClearBtn');
    const checkoutPane = document.getElementById('checkoutPane');
    const checkoutBack = document.getElementById('checkoutBack');
    const addToCartBtn = document.getElementById('addToCartBtn');
    const orderModal   = document.getElementById('orderModal');
    const modalTitle   = document.getElementById('modalTitle');
    const modalPrice   = document.getElementById('modalPrice');
    const modalImage   = document.getElementById('modalImage');
    const modalDesc    = document.getElementById('modalDescription');
    const qtyInput     = document.getElementById('qtyInput');
    const checkoutForm = document.getElementById('printingCheckoutForm');
    const stkWaiting   = document.getElementById('stkWaiting');
    const coSuccess    = document.getElementById('coSuccess');
    const stkCancelBtn = document.getElementById('stkCancelBtn');
    const coSuccessClose = document.getElementById('coSuccessClose');

    /* ── Open / close drawer ─────────────────────────────────────── */
    function openCart() {
        cartDrawer.classList.add('open');
        cartOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeCart() {
        cartDrawer.classList.remove('open');
        cartOverlay.classList.remove('active');
        document.body.style.overflow = '';
        showCartPanel();
    }
    function showCartPanel() {
        checkoutPane.style.display = 'none';
        cartItemsEl.style.display = '';
        cartFooter.style.display = cart.length ? '' : 'none';
    }
    function showCheckoutPanel() {
        renderCheckoutSummary();
        cartItemsEl.style.display = 'none';
        cartFooter.style.display = 'none';
        checkoutPane.style.display = '';
        checkoutForm.style.display = '';
        stkWaiting.style.display = 'none';
        coSuccess.style.display = 'none';
    }

    floatBtn.addEventListener('click', openCart);
    cartOverlay.addEventListener('click', closeCart);
    cartClose.addEventListener('click', closeCart);
    if (checkoutBtn) checkoutBtn.addEventListener('click', showCheckoutPanel);
    if (checkoutBack) checkoutBack.addEventListener('click', showCartPanel);
    if (clearBtn) clearBtn.addEventListener('click', () => { cart = []; renderCart(); });

    /* ── Cart helpers ────────────────────────────────────────────── */
    function cartTotal() {
        return cart.reduce((s, i) => s + i.price * i.qty, 0);
    }
    function totalItems() {
        return cart.reduce((s, i) => s + i.qty, 0);
    }
    function updateBadge() {
        const n = totalItems();
        cartBadge.textContent = n;
        floatBtn.style.display = n > 0 ? 'flex' : 'none';
    }

    function renderCart() {
        updateBadge();
        if (cart.length === 0) {
            cartItemsEl.innerHTML = '<p class="cart-empty-msg">Your cart is empty.<br>Browse the catalog and add items.</p>';
            cartFooter.style.display = 'none';
            return;
        }
        cartFooter.style.display = '';
        const n = totalItems();
        cartCountEl.textContent = n;
        cartPluralEl.textContent = n === 1 ? '' : 's';
        cartTotalEl.textContent = cartTotal().toLocaleString();

        cartItemsEl.innerHTML = cart.map((item, idx) => `
            <div class="cart-item">
                <img class="cart-item-img" src="${item.image || 'assets/service-placeholder.jpg'}" alt="${item.name}" onerror="this.src='assets/service-placeholder.jpg'">
                <div class="cart-item-body">
                    <div class="cart-item-name">${item.name}</div>
                    <div class="cart-item-price">KES ${(item.price * item.qty).toLocaleString()}</div>
                    <div class="cart-qty-row">
                        <button class="cart-qty-btn" data-idx="${idx}" data-dir="-1">−</button>
                        <span class="cart-qty-val">${item.qty}</span>
                        <button class="cart-qty-btn" data-idx="${idx}" data-dir="1">+</button>
                    </div>
                </div>
                <button class="cart-item-remove" data-idx="${idx}" title="Remove">✕</button>
            </div>`).join('');

        cartItemsEl.querySelectorAll('.cart-qty-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const i = +btn.dataset.idx, d = +btn.dataset.dir;
                cart[i].qty = Math.max(1, cart[i].qty + d);
                renderCart();
            });
        });
        cartItemsEl.querySelectorAll('.cart-item-remove').forEach(btn => {
            btn.addEventListener('click', () => {
                cart.splice(+btn.dataset.idx, 1);
                renderCart();
            });
        });
    }

    function addToCart(product) {
        const existing = cart.find(i => i.id === product.id);
        if (existing) {
            existing.qty += product.qty;
        } else {
            cart.push({ ...product });
        }
        renderCart();
        updateBadge();
    }

    /* ── Override modal to capture current product ───────────────── */
    // Wait for the catalog JS to attach its listeners, then intercept
    document.addEventListener('click', function (e) {
        // Card or "Order Now" button clicked → update currentModalProduct
        const card = e.target.closest('.product-card');
        if (card) {
            currentModalProduct = {
                // The stockroom's own id. It used to be the product name,
                // because the card carried no id — which is also why the
                // server had nothing to check a price against.
                id:    parseInt(card.dataset.productId, 10) || 0,
                name:  card.dataset.productName,
                price: parseFloat(card.dataset.productPrice) || 0,
                image: card.dataset.productImage || '',
                qty:   1,
            };
        }
    }, true); // capture phase so we get it before the modal opens

    /* ── Add to Cart button in modal ─────────────────────────────── */
    if (addToCartBtn) {
        addToCartBtn.addEventListener('click', () => {
            if (!currentModalProduct) return;
            const qty = Math.max(1, parseInt(qtyInput ? qtyInput.value : 1) || 1);
            addToCart({ ...currentModalProduct, qty });

            // Close modal
            if (orderModal) orderModal.classList.remove('active');
            document.body.style.overflow = '';

            // Flash button
            addToCartBtn.textContent = '✓ Added!';
            addToCartBtn.style.background = 'linear-gradient(135deg,#166534,#15803d)';
            setTimeout(() => {
                addToCartBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg> Add to Cart`;
                addToCartBtn.style.background = '';
            }, 1800);

            openCart();
        });
    }

    /* ── Checkout summary ────────────────────────────────────────── */
    function renderCheckoutSummary() {
        const el = document.getElementById('checkoutSummary');
        if (!el) return;
        el.innerHTML = cart.map(i =>
            `<div class="co-sum-row"><span>${i.name} ×${i.qty}</span><span>KES ${(i.price * i.qty).toLocaleString()}</span></div>`
        ).join('') +
        `<div class="co-sum-row"><span>Total</span><span>KES ${cartTotal().toLocaleString()}</span></div>`;
    }

    /* ── STK Push checkout ───────────────────────────────────────── */
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const name   = document.getElementById('co_name').value.trim();
            const email  = document.getElementById('co_email').value.trim();
            const phone  = document.getElementById('co_phone').value.trim();
            const mpesa  = document.getElementById('co_mpesa').value.trim();

            if (!name || !email || !phone || !mpesa) {
                alert('Please fill in all required fields.');
                return;
            }
            if (cart.length === 0) { alert('Your cart is empty.'); return; }

            const submitBtn = document.getElementById('coSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing…';

            try {
                const res  = await fetch('api/printing-order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name, email, phone, mpesa_phone: mpesa,
                        // The id is what the server prices the order from.
                        // Whatever price is sent with it is only for show.
                        items: cart.map(i => ({ id: i.id, name: i.name, price: i.price, qty: i.qty })),
                        total: cartTotal(),
                    }),
                });
                const data = await res.json();

                if (!data.success) {
                    alert(data.message || 'Order failed. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M8 12l2.5 2.5L16 9"/></svg> Pay via M-PESA`;
                    return;
                }

                // Show waiting state
                checkoutForm.style.display = 'none';
                stkWaiting.style.display = '';
                document.getElementById('stkWaitMsg').textContent = data.stk_sent
                    ? 'Check your phone and enter your M-PESA PIN'
                    : 'Order placed! We will contact you for payment.';

                if (data.stk_sent && data.transaction_id) {
                    pollPayment(data.transaction_id);
                } else {
                    // No STK (not configured) — just show success
                    setTimeout(showOrderSuccess, 2000);
                }
            } catch (err) {
                alert('Network error. Please try again.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M8 12l2.5 2.5L16 9"/></svg> Pay via M-PESA`;
            }
        });
    }

    /* ── Poll for payment confirmation ──────────────────────────── */
    function pollPayment(transactionId) {
        let attempts = 0;
        stkPollTimer = setInterval(async () => {
            attempts++;
            if (attempts > 24) { clearInterval(stkPollTimer); return; } // 2 min max
            try {
                const r = await fetch(`api/printing-order.php?poll=${transactionId}`);
                const d = await r.json();
                if (d.status === 'paid') {
                    clearInterval(stkPollTimer);
                    showOrderSuccess();
                } else if (d.status === 'failed') {
                    clearInterval(stkPollTimer);
                    stkWaiting.style.display = 'none';
                    checkoutForm.style.display = '';
                    document.getElementById('coSubmitBtn').disabled = false;
                    document.getElementById('coSubmitBtn').innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M8 12l2.5 2.5L16 9"/></svg> Pay via M-PESA`;
                    alert('Payment was not completed. Please try again.');
                }
            } catch (_) {}
        }, 5000);
    }

    function showOrderSuccess() {
        stkWaiting.style.display = 'none';
        coSuccess.style.display = '';
        cart = [];
        renderCart();
    }

    if (stkCancelBtn) {
        stkCancelBtn.addEventListener('click', () => {
            clearInterval(stkPollTimer);
            stkWaiting.style.display = 'none';
            checkoutForm.style.display = '';
            const btn = document.getElementById('coSubmitBtn');
            btn.disabled = false;
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M8 12l2.5 2.5L16 9"/></svg> Pay via M-PESA`;
        });
    }

    if (coSuccessClose) {
        coSuccessClose.addEventListener('click', closeCart);
    }

    // Init
    renderCart();
})();
</script>

    <?php include 'includes/footer.php'; ?>
