<?php
/**
 * The partner portal shell.
 *
 * The same chrome as the client portal — one brand, the same cards
 * underneath — because both are our customers looking at their own
 * account, and there is no reason for them to feel like different
 * products. What differs is the nav and a badge, so a partner can tell at
 * a glance which of the two they are signed in to.
 *
 * The nav is down the side now. A partner has their commission, their
 * customers and, since SMS came in, a whole reselling business to
 * navigate; that is far too many links for a row across the top.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Database;
use App\Core\PartnerAuth;
use App\Core\Settings;

$partner = PartnerAuth::user();
$brand   = Settings::company();

$here = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$on   = static fn(string $p): bool => str_ends_with($here, $p);
$in   = static fn(string $p): bool => str_contains($here, $p);

$smsOn = Settings::bool('bulk_sms_enabled', true);

// Their clients' payments waiting on them. Nobody checks a page that
// never says there is something to do.
$smsWaiting = 0;

if ($partner && $smsOn) {
    $smsWaiting = (int) Database::scalar(
        "SELECT COUNT(*) FROM bulk_purchases p
           JOIN bulk_accounts a ON a.id = p.seller_account_id
          WHERE a.owner_type = 'partner' AND a.owner_id = :p AND p.status = 'pending'",
        ['p' => $partner['id']],
        0
    );
}
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

<?php if (!$partner): ?>
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
<?php else: ?>

<div class="layout">

  <div class="sidebar__scrim"></div>

  <aside class="sidebar">
    <a class="sidebar__brand" href="<?= url('/partners') ?>" style="text-decoration:none">
      <?php if ($brand['logo']): ?>
        <?php // /brand/logo, not /files — that route is behind the staff guard. ?>
        <img class="sidebar__logo" src="<?= url('/brand/logo') ?>" alt="<?= e($brand['name']) ?>">
      <?php else: ?>
        <span class="sidebar__mark">SF</span>
        <span>
          <span class="sidebar__name"><?= e($brand['name']) ?></span>
          <span class="sidebar__sub">PARTNER PORTAL</span>
        </span>
      <?php endif; ?>
    </a>

    <nav class="sidebar__nav">
      <a class="nav-link <?= $on('/partners') ? 'is-active' : '' ?>" href="<?= url('/partners') ?>">
        <?= icon('grid', 'nav-link__icon') ?> Overview
      </a>

      <div class="nav-group__label">Your earnings</div>
      <a class="nav-link <?= $in('/partners/earnings') ? 'is-active' : '' ?>" href="<?= url('/partners/earnings') ?>">
        <?= icon('dollar', 'nav-link__icon') ?> Commission
      </a>
      <a class="nav-link <?= $on('/partners/upcoming') ? 'is-active' : '' ?>" href="<?= url('/partners/upcoming') ?>">
        <?= icon('calendar', 'nav-link__icon') ?> What is coming
      </a>

      <div class="nav-group__label">Your customers</div>
      <a class="nav-link <?= $in('/partners/customers') ? 'is-active' : '' ?>" href="<?= url('/partners/customers') ?>">
        <?= icon('users', 'nav-link__icon') ?> Customers
      </a>
      <a class="nav-link <?= $in('/partners/clients') ? 'is-active' : '' ?>" href="<?= url('/partners/clients/new') ?>">
        <?= icon('user-plus', 'nav-link__icon') ?> Register a customer
      </a>
      <a class="nav-link <?= $on('/partners/refer') ? 'is-active' : '' ?>" href="<?= url('/partners/refer') ?>">
        <?= icon('send', 'nav-link__icon') ?> Introduce someone
      </a>
      <a class="nav-link <?= $on('/partners/services') ? 'is-active' : '' ?>" href="<?= url('/partners/services') ?>">
        <?= icon('package', 'nav-link__icon') ?> What we do
      </a>

      <?php if ($smsOn): ?>
        <?php // Their own sending. ?>
        <div class="nav-group__label">Bulk SMS</div>
        <a class="nav-link <?= $on('/partners/sms') ? 'is-active' : '' ?>" href="<?= url('/partners/sms') ?>">
          <?= icon('send', 'nav-link__icon') ?> Send a message
        </a>
        <a class="nav-link <?= $in('/partners/sms/campaigns') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/campaigns') ?>">
          <?= icon('layers', 'nav-link__icon') ?> Campaigns
        </a>
        <a class="nav-link <?= $on('/partners/sms/send-file') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/send-file') ?>">
          <?= icon('file-text', 'nav-link__icon') ?> Send from a file
        </a>
        <a class="nav-link <?= $on('/partners/sms/scheduled') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/scheduled') ?>">
          <?= icon('clock', 'nav-link__icon') ?> Scheduled
        </a>
        <a class="nav-link <?= $in('/partners/sms/contacts') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/contacts') ?>">
          <?= icon('users', 'nav-link__icon') ?> Contacts
        </a>
        <a class="nav-link <?= $on('/partners/sms/groups') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/groups') ?>">
          <?= icon('list', 'nav-link__icon') ?> Contact lists
        </a>
        <a class="nav-link <?= $on('/partners/sms/templates') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/templates') ?>">
          <?= icon('file-text', 'nav-link__icon') ?> Saved messages
        </a>
        <a class="nav-link <?= $on('/partners/sms/reports') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/reports') ?>">
          <?= icon('activity', 'nav-link__icon') ?> Delivery reports
        </a>
        <a class="nav-link <?= $on('/partners/sms/senders') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/senders') ?>">
          <?= icon('shield', 'nav-link__icon') ?> Sender IDs
        </a>
        <a class="nav-link <?= $in('/partners/sms/buy') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/buy') ?>">
          <?= icon('credit-card', 'nav-link__icon') ?> Buy units
        </a>
        <a class="nav-link <?= $in('/partners/sms/api') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/api') ?>">
          <?= icon('code', 'nav-link__icon') ?> Developer API
        </a>
        <a class="nav-link <?= $on('/partners/sms/settings') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/settings') ?>">
          <?= icon('sliders', 'nav-link__icon') ?> SMS settings
        </a>

        <?php // Their reselling. ?>
        <div class="nav-group__label">Reselling SMS</div>
        <a class="nav-link <?= $in('/partners/sms/clients') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/clients') ?>">
          <?= icon('briefcase', 'nav-link__icon') ?> My SMS clients
        </a>
        <a class="nav-link <?= $in('/partners/sms/sales') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/sales') ?>">
          <?= icon('credit-card', 'nav-link__icon') ?> Their payments
          <?php if ($smsWaiting > 0): ?><span class="nav-link__badge"><?= $smsWaiting ?></span><?php endif; ?>
        </a>
        <a class="nav-link <?= $in('/partners/sms/pricing') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/pricing') ?>">
          <?= icon('dollar', 'nav-link__icon') ?> My prices
        </a>
        <a class="nav-link <?= $in('/partners/sms/branding') ? 'is-active' : '' ?>" href="<?= url('/partners/sms/branding') ?>">
          <?= icon('star', 'nav-link__icon') ?> How clients see me
        </a>
      <?php endif; ?>
    </nav>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn sidebar__toggle" data-sidebar-toggle type="button" aria-label="Toggle navigation">
        <?= icon('menu') ?>
      </button>

      <div class="topbar__title"><?= e($title ?? 'Your account') ?></div>

      <div class="topbar__spacer"></div>

      <span class="portal-kind">Partner</span>

      <button class="icon-btn" type="button" data-theme-toggle
              title="Switch between dark and light" aria-label="Switch between dark and light">
        <span data-theme-icon="dark"><?= icon('moon') ?></span>
        <span data-theme-icon="light" hidden><?= icon('sun') ?></span>
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
  </div>
</div>

<?php endif; ?>

<?= js_tag() ?>
</body>
</html>
