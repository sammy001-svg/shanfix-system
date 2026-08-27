<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\ClientAuth;

$portalUser = ClientAuth::user();
$brand      = \App\Core\Settings::company();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title ?? 'Your account') ?> · <?= e($brand['name']) ?></title>
<?php
  // Same trick as the staff layout: set the theme before anything paints,
  // so somebody who chose light does not see a dark frame flash first.
?>
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
  <a class="portal-brand" href="<?= url($portalUser ? '/portal' : '/portal/login') ?>">
    <?php if ($brand['logo']): ?>
      <img class="portal-brand__logo" src="<?= url('files/' . $brand['logo']) ?>" alt="<?= e($brand['name']) ?>">
    <?php else: ?>
      <span class="portal-brand__mark">SF</span>
    <?php endif; ?>
    <span>
      <span class="portal-brand__name"><?= e($brand['name']) ?></span>
      <?php if ($brand['tagline']): ?>
        <span class="portal-brand__tag"><?= e($brand['tagline']) ?></span>
      <?php endif; ?>
    </span>
  </a>

  <?php if ($portalUser): ?>
    <?php
      // is_active_nav('/portal') matches every page in the portal, so the
      // overview would stay lit on all of them. Matched on the path itself.
      $here = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
      $on   = static fn(string $p): bool => str_ends_with($here, $p);
    ?>
    <nav class="portal-nav">
      <a class="portal-nav__link <?= $on('/portal') ? 'is-active' : '' ?>"
         href="<?= url('/portal') ?>">Overview</a>
      <a class="portal-nav__link <?= str_contains($here, '/portal/quotations') ? 'is-active' : '' ?>"
         href="<?= url('/portal/quotations') ?>">Quotations</a>
      <a class="portal-nav__link <?= str_contains($here, '/portal/invoices') ? 'is-active' : '' ?>"
         href="<?= url('/portal/invoices') ?>">Invoices</a>
      <a class="portal-nav__link <?= $on('/portal/statement') ? 'is-active' : '' ?>"
         href="<?= url('/portal/statement') ?>">Statement</a>
    </nav>

    <div class="portal-top__right">
      <button class="icon-btn" type="button" data-theme-toggle
              title="Switch between dark and light" aria-label="Switch between dark and light">
        <span data-theme-icon="dark"><?= icon('moon') ?></span>
        <span data-theme-icon="light"><?= icon('sun') ?></span>
      </button>

      <div class="dropdown">
        <button class="portal-who" type="button" data-dropdown aria-label="Your account">
          <span class="avatar avatar--sm"><?= e(initials($portalUser['name'])) ?></span>
          <span class="portal-who__name"><?= e($portalUser['client_name'] ?: $portalUser['name']) ?></span>
          <?= icon('chevron-down') ?>
        </button>
        <div class="dropdown__menu dropdown__menu--right">
          <div class="dropdown__label"><?= e($portalUser['email']) ?></div>
          <div class="dropdown__divider"></div>
          <form method="post" action="<?= url('/portal/logout') ?>">
            <?= csrf_field() ?>
            <button class="dropdown__item" type="submit"><?= icon('log-out') ?> Sign out</button>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>
</header>

<main class="portal-main" id="main" tabindex="-1">
  <?php // $flashes is shared by App::run(), same as the staff layout. ?>
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
    <?php if ($brand['website']): ?> &middot; <?= e($brand['website']) ?><?php endif; ?>
  </div>
</footer>

<?= js_tag() ?>
</body>
</html>
