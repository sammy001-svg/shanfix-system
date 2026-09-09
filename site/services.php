<?php include 'includes/header.php'; ?>

<main class="services-modern-page">
    <!-- Hero Section -->
    <section class="services-hero">
        <div class="container hero-inner">
            <div class="services-hero-content" data-aos="fade-up">
                <span class="section-tag">Empowering Digital Growth</span>
                <h1>Professional <span>Service Catalog</span></h1>
                <p>Strategic solutions tailored for your business. From web hosting to digital marketing, we provide the tools you need to succeed in the digital era.</p>
            </div>
        </div>
    </section>

    <?php // What we actually do, read live from the system so a price on
          // this page can never be one we stopped charging. Prints nothing
          // if the system is unreachable, and the rest of the page stands
          // on its own. ?>
    <?php include 'includes/live_services.php'; ?>

    <!-- Services Grid -->
    <section class="services-grid-section">
        <div class="container">
            <div id="services-dynamic-container">
                <!-- Services will be loaded here via AJAX -->
                <div class="loading-state py-100 text-center">
                    <div class="spinner-modern"></div>
                    <p class="mt-20">Synchronizing catalog...</p>
                </div>
            </div>
        </div>
    </section>
</main>

<style>
/* Modern Services Page Styles */
.services-hero {
    padding: 120px 0 80px;
    background: radial-gradient(circle at top right, rgba(0, 102, 255, 0.05), transparent);
    text-align: center;
}
.services-hero h1 {
    font-size: 3.5rem;
    margin-bottom: 20px;
}
.services-hero h1 span {
    color: var(--primary-color);
}
.services-hero p {
    max-width: 700px;
    margin: 0 auto;
    color: var(--text-muted);
}

.category-block {
    margin-bottom: 80px;
}
.category-header {
    margin-bottom: 40px;
    display: flex;
    align-items: center;
    gap: 20px;
}
.category-header h2 {
    font-size: 2.2rem;
    color: var(--secondary-color);
}
.category-header .line {
    flex: 1;
    height: 1px;
    background: linear-gradient(to right, var(--primary-color), transparent);
}

.price-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 30px;
}

.modern-price-card {
    background: var(--glass-bg);
    backdrop-filter: blur(12px);
    border: 1px solid var(--glass-border);
    border-radius: 24px;
    padding: 40px;
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
}
.modern-price-card:hover {
    transform: translateY(-10px);
    border-color: var(--primary-color);
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
}
.modern-price-card.featured {
    border: 2px solid var(--primary-color);
    background: rgba(0, 102, 255, 0.03);
}
.popular-badge {
    position: absolute;
    top: 20px;
    right: -35px;
    background: var(--primary-color);
    color: white;
    padding: 5px 40px;
    transform: rotate(45deg);
    font-size: 0.75rem;
    font-weight: 700;
}

.card-header h3 {
    font-size: 1.5rem;
    margin-bottom: 10px;
}
.card-price {
    font-size: 2.5rem;
    font-weight: 800;
    margin: 20px 0;
    color: var(--primary-color);
}
.card-price span {
    font-size: 1rem;
    color: var(--text-muted);
}
.card-desc {
    font-size: 0.9rem;
    color: var(--text-muted);
    margin-bottom: 30px;
    min-height: 3em;
}

.card-features {
    list-style: none;
    padding: 0;
    margin: 0 0 40px 0;
    flex: 1;
}
.card-features li {
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.95rem;
}
.card-features li i {
    color: var(--primary-color);
}

.spinner-modern {
    width: 50px;
    height: 50px;
    border: 3px solid rgba(0, 102, 255, 0.1);
    border-radius: 50%;
    border-top-color: var(--primary-color);
    animation: spin 1s ease-in-out infinite;
    margin: 0 auto;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', async () => {
    const container = document.getElementById('services-dynamic-container');
    
    try {
        // One call to the public catalogue. It used to be two, into the
        // site's admin folder, which no longer exists — the system behind
        // this site is the only admin now.
        const res  = await fetch('api/catalogue.php');
        const data = await res.json();

        if (data.success) {
            const products = data.products;
            const categories = data.categories;
            
            let html = '';
            
            categories.forEach(cat => {
                const catProducts = products.filter(p => p.category_id == cat.id);
                if (catProducts.length === 0) return;
                
                html += `
                    <div class="category-block" data-aos="fade-up">
                        <div class="category-header">
                            <h2>${cat.name}</h2>
                            <div class="line"></div>
                        </div>
                        <div class="price-cards-grid">
                            ${catProducts.map(p => {
                                const features = (p.features || '').split('\n').filter(f => f.trim());
                                return `
                                    <div class="modern-price-card ${p.is_featured ? 'featured' : ''}">
                                        ${p.is_featured ? '<div class="popular-badge">POPULAR</div>' : ''}
                                        <div class="card-header">
                                            <h3>${p.name}</h3>
                                            <p class="card-desc">${p.description}</p>
                                        </div>
                                        <div class="card-price">
                                            KES ${parseFloat(p.price).toLocaleString()}
                                            <span>/one-time</span>
                                        </div>
                                        <ul class="card-features">
                                            ${features.map(f => `<li><i class="fas fa-check-circle"></i> ${f}</li>`).join('')}
                                        </ul>
                                        <button class="btn btn-primary open-checkout-modal" 
                                                data-package-id="${p.id}"
                                                data-package-name="${p.name}" 
                                                data-package-price="KES ${parseFloat(p.price).toLocaleString()}">
                                            Purchase Now
                                        </button>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html || '<div class="text-center py-100"><p>Our catalog is currently being updated. Please check back soon.</p></div>';
            
            // Re-initialize animations and modal triggers
            if (typeof AOS !== 'undefined') AOS.init();
            initCheckoutTriggers();
            
        }
    } catch (e) {
        container.innerHTML = '<div class="text-center py-100"><p>Failed to load services. Please refresh the page.</p></div>';
    }
});

function initCheckoutTriggers() {
    document.querySelectorAll('.open-checkout-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            const name = this.getAttribute('data-package-name');
            const price = this.getAttribute('data-package-price');
            const id = this.getAttribute('data-package-id');
            
            if (typeof openCheckout === 'function') {
                openCheckout(name, price, id);
            } else {
                console.error('openCheckout function not found');
            }
        });
    });
}
</script>

<?php include 'includes/footer.php'; ?>
