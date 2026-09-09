<?php
/**
 * The partner portal shell.
 *
 * The same chrome as the client portal — navy header, one brand, the same
 * cards underneath — because both are our customers looking at their own
 * account, and there is no reason for them to feel like different
 * products. What differs is the nav and a badge, so a partner can tell at
 * a glance which of the two they are signed in to.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\PartnerAuth;

$partner = PartnerAuth::user();
$brand   = \App\Core\Settings::company();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php // The system shares a domain with the company website now, so a
      // crawler that finds its way in would otherwise index the sign-in
      // page, the portals and every URL it can reach from them. None of
      // this belongs in a search result: it is a business system, not
      // publishing. robots.txt asks crawlers not to fetch these; this
      // says not to list them even if a URL turns up elsewhere. ?>
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title ?? 'Your account') ?> · <?= e($brand['name']) ?></title>
<?php // Set the theme before anything paints, so somebody who chose light
      // does not see a dark frame flash first. ?>
<script nonce="<?= e(csp_nonce()) ?>">
  (function () {
    try {
      var saved = localStorage.getItem('shanfix-theme');
      if (saved === 'light' || saved === 'dark') {
        document.documentElement.setAttribute('data-theme', saved);
      }
    } catch (e) { /* private browsing can refuse storage */ }
  })();
</script>
<?= css_tag() ?>
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
<meta name="app-base" content="<?= e(base_path()) ?>">
<meta name="theme-color" content="#0C2B4A">
</head>
<body class="portal-body">

<a class="skip-link" href="#main">Skip to content</a>

<header class="portal-top">
  <a class="portal-brand" href="<?= url($partner ? '/partners' : '/partners/login') ?>">
    <?php if ($brand['logo']): ?>
      <?php // /brand/logo, not /files — that route is behind the staff guard. ?>
      <img class="portal-brand__logo" src="<?= url('/brand/logo') ?>" alt="<?= e($brand['name']) ?>">
    <?php else: ?>
      <span class="portal-brand__mark">SF</span>
      <span>
        <span class="portal-brand__name"><?= e($brand['name']) ?></span>
        <?php if ($brand['tagline']): ?>
          <span class="portal-brand__tag"><?= e($brand['tagline']) ?></span>
        <?php endif; ?>
      </span>
    <?php endif; ?>
  </a>

  <?php if ($partner): ?>
    <?php
      $here = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
      $on   = static fn(string $p): bool => str_ends_with($here, $p);
    ?>
    <nav class="portal-nav">
      <a class="portal-nav__link <?= $on('/partners') ? 'is-active' : '' ?>"
         href="<?= url('/partners') ?>">Overview</a>
      <a class="portal-nav__link <?= $on('/partners/earnings') ? 'is-active' : '' ?>"
         href="<?= url('/partners/earnings') ?>">Earnings</a>
      <a class="portal-nav__link <?= $on('/partners/customers') ? 'is-active' : '' ?>"
         href="<?= url('/partners/customers') ?>">Customers</a>
      <a class="portal-nav__link <?= $on('/partners/services') ? 'is-active' : '' ?>"
         href="<?= url('/partners/services') ?>">What we do</a>
      <a class="portal-nav__link <?= $on('/partners/upcoming') ? 'is-active' : '' ?>"
         href="<?= url('/partners/upcoming') ?>">What is coming</a>
      <a class="portal-nav__link <?= $on('/partners/clients') ? 'is-active' : '' ?>"
         href="<?= url('/partners/clients/new') ?>">Register a customer</a>
      <a class="portal-nav__link <?= $on('/partners/refer') ? 'is-active' : '' ?>"
         href="<?= url('/partners/refer') ?>">Introduce someone</a>
    </nav>

    <div class="portal-top__right">
      <span class="portal-kind">Partner</span>

      <button class="icon-btn" type="button" data-theme-toggle
              title="Switch between dark and light" aria-label="Switch between dark and light">
        <span data-theme-icon="dark"><?= icon('moon') ?></span>
        <span data-theme-icon="light"><?= icon('sun') ?></span>
      </button>

      <div class="dropdown">
        <button class="portal-who" type="button" data-dropdown aria-label="Your account">
          <span class="avatar avatar--sm"><?= e(initials($partner['name'])) ?></span>
          <span class="portal-who__name"><?= e($partner['company'] ?: $partner['name']) ?></span>
          <?= icon('chevron-down') ?>
        </button>
        <div class="dropdown__menu dropdown__menu--right">
          <div class="dropdown__label"><?= e($partner['email']) ?></div>
          <div class="dropdown__divider"></div>
          <a class="dropdown__item" href="<?= url('/partners/account') ?>">
            <?= icon('user') ?> Your details
          </a>
          <?php // Out to the company website. It is the front door of this
                // domain and the system sits behind it, so without this the
                // only way across is to edit the address bar. ?>
          <a class="dropdown__item" href="/" target="_blank" rel="noopener">
            <?= icon('external-link') ?> Visit our website
          </a>
          <div class="dropdown__divider"></div>
          <form method="post" action="<?= url('/partners/logout') ?>">
            <?= csrf_field() ?>
            <button class="dropdown__item" type="submit"><?= icon('log-out') ?> Sign out</button>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>
</header>

<main class="portal-main" id="main" tabindex="-1">
  <?php foreach (($flashes ?? []) as $flash): ?>
    <div class="alert alert--<?= e($flash['type']) ?>">
      <?= icon(match ($flash['type']) {
          'success' => 'check-circle',
          'error'   => 'x-circle',
          'warning' => 'alert-triangle',
          default   => 'info',
      }) ?>
      <div class="alert__body"><?= e($flash['message']) ?></div>
      <button class="alert__close" type="button" aria-label="Dismiss">&times;</button>
    </div>
  <?php endforeach; ?>
  <?= $content ?>
</main>

<footer class="portal-foot">
  <div><?= e($brand['name']) ?></div>
  <div>
    <?php if ($brand['phone']): ?><?= e($brand['phone']) ?><?php endif; ?>
    <?php if ($brand['email']): ?> &middot; <?= e($brand['email']) ?><?php endif; ?>
  </div>
</footer>

<?= js_tag() ?>
</body>
</html>
