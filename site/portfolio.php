<?php
/**
 * Our work.
 *
 * Everything on this page comes from the business system, where staff
 * enter it under Portfolio. Nothing is written in here.
 *
 * It used to be. The page read a portfolio_projects table in the site's
 * own database, found it empty, and fell back to three case studies
 * written into the file — a "Skyline E-Commerce Platform", an "Apex
 * Real-time Trading Dashboard" and a "Nexus Health Tracking App", with
 * figures to match. None of them was ours, and a visitor had no way to
 * know that. It is the same fault the testimonials had, and it is worse
 * here, because it claimed client projects and the results of them.
 *
 * So: three sections, each one left out entirely when there is nothing
 * in it, and a page that says we are still putting this together rather
 * than inventing something when there is nothing at all.
 */
require_once 'includes/db_connect.php';
require_once __DIR__ . '/includes/system.php';

$websites = system_projects('website');
$systems  = system_projects('system');
$gallery  = system_work();
$clients  = system_clients();

// The big treatment at the top, whichever kind they are.
$featured = array_values(array_filter(
    array_merge($websites, $systems),
    static fn(array $p): bool => $p['featured'] && $p['image'] !== ''
));

// The rest, which the grids below list.
$restWebsites = array_values(array_filter(
    $websites,
    static fn(array $p): bool => !in_array($p, $featured, true)
));
$restSystems = array_values(array_filter(
    $systems,
    static fn(array $p): bool => !in_array($p, $featured, true)
));

// The filter buttons on the gallery are built from what is actually
// there, so a category nobody has used does not get a button.
$categories = [];

foreach ($gallery as $job) {
    if ($job['category'] !== '') {
        $categories[$job['category']] = true;
    }
}

$categories = array_keys($categories);
sort($categories);

$hasAnything = $websites || $systems || $gallery || $clients;

$pageSEO = [
    'title'       => 'Our Work | Portfolio - Shanfix Technology',
    'description' => 'Websites and business systems we have built, printing and branding we have produced, and the clients we have done it for.',
    'keywords'    => 'Shanfix Technology portfolio, web development Kenya, business systems Kenya, printing and branding Nakuru',
    'canonical'   => '{{base}}/portfolio.php',
];
include 'includes/header.php';

/** A card for one project, used by both grids. */
$projectCard = static function (array $p): void { ?>
    <article class="work-card" data-aos="fade-up">
        <?php if ($p['image'] !== ''): ?>
            <div class="work-card__shot">
                <img src="<?= htmlspecialchars($p['image']) ?>"
                     alt="<?= htmlspecialchars($p['title']) ?>" loading="lazy">
            </div>
        <?php endif; ?>

        <div class="work-card__body">
            <h3 class="work-card__title"><?= htmlspecialchars($p['title']) ?></h3>

            <?php if ($p['client'] !== ''): ?>
                <p class="work-card__client">for <?= htmlspecialchars($p['client']) ?></p>
            <?php endif; ?>

            <?php if ($p['summary'] !== ''): ?>
                <p class="work-card__summary"><?= htmlspecialchars($p['summary']) ?></p>
            <?php endif; ?>

            <?php if ($p['built_with']): ?>
                <ul class="work-tags">
                    <?php foreach ($p['built_with'] as $tag): ?>
                        <li><?= htmlspecialchars($tag) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($p['url'] !== ''): ?>
                <a class="work-card__link" href="<?= htmlspecialchars($p['url']) ?>"
                   target="_blank" rel="noopener noreferrer">
                    Visit the site
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
                         stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                </a>
            <?php endif; ?>
        </div>
    </article>
<?php };
?>
<link rel="stylesheet" href="<?= site_asset('portfolio-modern.css') ?>">

<main class="page-modern-portfolio">

    <section class="portfolio-hero">
        <div class="portfolio-hero-content">
            <h1 class="portfolio-hero-title" data-aos="fade-up">Our <span class="highlight">Work</span></h1>
            <p class="portfolio-hero-subtitle" data-aos="fade-up" data-aos-delay="100">
                Websites and business systems we have built, printing and branding
                we have produced, and the people we have done it for.
            </p>
        </div>
    </section>

    <?php if (!$hasAnything): ?>
        <?php // Nothing entered yet. Said plainly, because the alternative
              // — which is what this page used to do — is to make something
              // up and hope nobody asks about it. ?>
        <section class="featured-work-section container">
            <div class="work-empty">
                <h2>We are putting this page together.</h2>
                <p>
                    Our work speaks for itself and we would rather show you the real
                    thing than a stock photograph. In the meantime,
                    <a href="contact.php">talk to us</a> about what you need and we
                    will show you jobs like it.
                </p>
            </div>
        </section>
    <?php endif; ?>

    <?php // ───────────────── The big ones ───────────────── ?>
    <?php if ($featured): ?>
    <section class="featured-work-section container">
        <div class="section-label" data-aos="fade-right">Selected work</div>

        <?php foreach ($featured as $i => $p): ?>
            <article class="featured-project<?= $i % 2 === 1 ? ' reverse' : '' ?>" data-aos="fade-up">
                <div class="fp-image">
                    <img src="<?= htmlspecialchars($p['image']) ?>"
                         alt="<?= htmlspecialchars($p['title']) ?>" loading="lazy">
                </div>
                <div class="fp-content">
                    <span class="fp-badge">
                        <?= $p['kind'] === 'system' ? 'Business system' : 'Website' ?>
                        <?= $p['year'] !== '' ? ' · ' . htmlspecialchars($p['year']) : '' ?>
                    </span>

                    <h2 class="fp-title"><?= htmlspecialchars($p['title']) ?></h2>

                    <?php if ($p['client'] !== ''): ?>
                        <p class="fp-client">for <?= htmlspecialchars($p['client']) ?></p>
                    <?php endif; ?>

                    <p class="fp-desc">
                        <?= htmlspecialchars($p['description'] !== '' ? $p['description'] : $p['summary']) ?>
                    </p>

                    <?php if ($p['built_with']): ?>
                        <ul class="work-tags">
                            <?php foreach ($p['built_with'] as $tag): ?>
                                <li><?= htmlspecialchars($tag) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($p['url'] !== ''): ?>
                        <a href="<?= htmlspecialchars($p['url']) ?>" target="_blank"
                           rel="noopener noreferrer" class="btn btn-primary">Visit the site</a>
                    <?php else: ?>
                        <a href="contact.php" class="btn btn-primary">Ask about something similar</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php // ───────────────── Websites ───────────────── ?>
    <?php if ($restWebsites): ?>
    <section class="projects-grid-section container">
        <div class="section-label" data-aos="fade-right">Websites</div>
        <div class="work-grid">
            <?php foreach ($restWebsites as $p) { $projectCard($p); } ?>
        </div>
    </section>
    <?php endif; ?>

    <?php // ───────────────── Systems ───────────────── ?>
    <?php if ($restSystems): ?>
    <section class="projects-grid-section container">
        <div class="section-label" data-aos="fade-right">Business systems</div>
        <div class="work-grid">
            <?php foreach ($restSystems as $p) { $projectCard($p); } ?>
        </div>
    </section>
    <?php endif; ?>

    <?php // ───────────────── Printing and branding ───────────────── ?>
    <?php if ($gallery): ?>
    <section class="projects-grid-section container">
        <div class="section-label" data-aos="fade-right">Printing &amp; branding</div>
        <p class="section-intro">
            Jobs we have finished and delivered. Everything here was printed,
            cut, wrapped or fitted by us.
        </p>

        <?php if (count($categories) > 1): ?>
        <div class="gallery-filters" data-aos="fade-up">
            <button type="button" class="gallery-filter is-active" data-filter="all">All</button>
            <?php foreach ($categories as $category): ?>
                <button type="button" class="gallery-filter"
                        data-filter="<?= htmlspecialchars(strtolower($category)) ?>">
                    <?= htmlspecialchars($category) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="gallery-grid" id="galleryGrid">
            <?php foreach ($gallery as $job): ?>
                <figure class="gallery-item<?= $job['featured'] ? ' is-wide' : '' ?>"
                        data-category="<?= htmlspecialchars(strtolower($job['category'])) ?>"
                        data-aos="fade-up">
                    <img src="<?= htmlspecialchars($job['image']) ?>"
                         alt="<?= htmlspecialchars($job['title']) ?>" loading="lazy">
                    <figcaption class="gallery-item__caption">
                        <span class="gallery-item__title"><?= htmlspecialchars($job['title']) ?></span>
                        <?php if ($job['client'] !== '' || $job['category'] !== ''): ?>
                            <span class="gallery-item__meta">
                                <?= htmlspecialchars(trim($job['category'] . ' · ' . $job['client'], ' ·')) ?>
                            </span>
                        <?php endif; ?>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php // ───────────────── Who we have worked for ───────────────── ?>
    <?php if ($clients): ?>
    <section class="clients-section">
        <div class="container">
            <div class="section-label" data-aos="fade-right">Who we have worked for</div>

            <div class="clients-grid">
                <?php foreach ($clients as $client): ?>
                    <?php
                      $tag  = $client['url'] !== '' ? 'a' : 'div';
                      $attr = $client['url'] !== ''
                        ? ' href="' . htmlspecialchars($client['url']) . '" target="_blank" rel="noopener noreferrer"'
                        : '';
                    ?>
                    <<?= $tag ?> class="client-card"<?= $attr ?> data-aos="zoom-in">
                        <?php if ($client['logo'] !== ''): ?>
                            <img class="client-card__logo" src="<?= htmlspecialchars($client['logo']) ?>"
                                 alt="<?= htmlspecialchars($client['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="client-card__name"><?= htmlspecialchars($client['name']) ?></span>
                        <?php endif; ?>

                        <?php if ($client['did'] !== ''): ?>
                            <span class="client-card__did"><?= htmlspecialchars($client['did']) ?></span>
                        <?php endif; ?>
                    </<?= $tag ?>>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php
    // One client quote, from Testimonials in the system. With none
    // entered, the ribbon is left out rather than made up.
    $testimonial = system_testimonials(1)[0] ?? null;
    $starSvg = '<svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg>';
    ?>
    <?php if ($testimonial): ?>
    <section class="testimonial-ribbon">
        <div class="container tr-content" data-aos="zoom-in">
            <div class="tr-stars"><?= str_repeat($starSvg, $testimonial['rating']) ?></div>
            <blockquote class="tr-quote">"<?= htmlspecialchars($testimonial['quote']) ?>"</blockquote>
            <p class="tr-author"><?= htmlspecialchars($testimonial['author']) ?></p>
            <p class="tr-company"><?= htmlspecialchars(trim($testimonial['role'] . ', ' . $testimonial['company'], ', ')) ?></p>
        </div>
    </section>
    <?php endif; ?>

</main>

<?php if ($gallery && count($categories) > 1): ?>
<script>
/* Filtering the gallery. Plain show and hide: the grid is a handful of
   photographs, and a library to reflow them would be more code than the
   page it sits on. */
(function () {
    var buttons = document.querySelectorAll('.gallery-filter');
    var items   = document.querySelectorAll('#galleryGrid .gallery-item');

    if (!buttons.length || !items.length) { return; }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            var want = button.getAttribute('data-filter');

            buttons.forEach(function (b) { b.classList.remove('is-active'); });
            button.classList.add('is-active');

            items.forEach(function (item) {
                var mine = item.getAttribute('data-category') || '';
                item.style.display = (want === 'all' || want === mine) ? '' : 'none';
            });
        });
    });
}());
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
