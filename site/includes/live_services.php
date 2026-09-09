<?php
/**
 * What we actually do, at today's prices, read from the business system.
 *
 * The point of this block is that there is no second copy. Prices on a
 * marketing site normally drift: somebody changes a rate in the system,
 * nobody remembers the website, and a customer arrives quoting a figure
 * we stopped charging a year ago. This reads the live catalogue every
 * time the page is rendered, so that cannot happen.
 *
 * If the system is not reachable the block prints nothing at all. A
 * marketing page with one section missing is a great deal better than a
 * marketing page that will not load, and the rest of this page does not
 * depend on it.
 *
 * Usage:
 *   include 'includes/live_services.php';
 *
 * The markup deliberately reuses the classes services.php already
 * defines — .category-block, .price-cards-grid, .modern-price-card and
 * the rest — so it looks like part of the page rather than bolted on.
 */

require_once __DIR__ . '/system.php';

$_liveServices = system_services();

if (!$_liveServices) {
    return;
}

// Grouped the way the catalogue is organised in the system, so the
// headings on the website match what staff see.
$_byCategory = [];

foreach ($_liveServices as $_svc) {
    $_byCategory[$_svc['category']][] = $_svc;
}

ksort($_byCategory);
?>

<section class="live-services-section" id="what-we-do">
    <div class="container">
        <div class="category-header" style="text-align:center; display:block; margin-bottom:12px;">
            <h2>What We Do</h2>
            <p style="color:#94a3b8; max-width:640px; margin:12px auto 0; line-height:1.7;">
                Our full range of services and what they cost today. These come
                straight from our own system, so what you see here is what we
                are charging right now.
            </p>
        </div>

        <?php foreach ($_byCategory as $_category => $_services): ?>
            <div class="category-block" data-aos="fade-up">
                <div class="category-header">
                    <h2><?= htmlspecialchars($_category) ?></h2>
                    <div class="line"></div>
                </div>

                <div class="price-cards-grid">
                    <?php foreach ($_services as $_svc): ?>
                        <div class="modern-price-card">
                            <div class="card-header">
                                <h3><?= htmlspecialchars($_svc['name']) ?></h3>
                                <?php if ($_svc['description'] !== ''): ?>
                                    <p class="card-desc"><?= htmlspecialchars($_svc['description']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="card-price">
                                <?= htmlspecialchars(system_price_label($_svc)) ?>
                            </div>

                            <?php // How long it takes matters as much as what it
                                  // costs, and it is the question the phone call
                                  // would have been about. ?>
                            <?php if ($_svc['lead_time'] !== ''): ?>
                                <ul class="card-features">
                                    <li>
                                        <i class="fas fa-clock"></i>
                                        <?= htmlspecialchars($_svc['lead_time']) ?>
                                    </li>
                                </ul>
                            <?php endif; ?>

                            <?php // Straight to the enquiry form with the service
                                  // already filled in, so it arrives with sales
                                  // knowing what was being looked at. ?>
                            <a class="btn btn-primary"
                               style="display:block; text-align:center; text-decoration:none;"
                               href="contact.php?service=<?= urlencode($_svc['name']) ?>">
                                Get a Quote
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
