<?php
/**
 * The customer portal's shell.
 *
 * The navigation is down the side, the same shape as the staff system's.
 * It was across the top, which worked while there were eight links and
 * stopped working when Bulk SMS arrived with seven of its own: a row that
 * wraps onto three lines is not a menu, it is a wall. Down the side the
 * list can group itself, say which section you are in, and grow again
 * without being redesigned.
 *
 * The shell classes (.layout, .sidebar, .main, .topbar) are the staff
 * system's, so the small-screen drawer, its scrim and the toggle button
 * all work here without a second implementation.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\ClientAuth;
use App\Core\Settings;
use App\Services\ClientNotifier;

$portalUser = ClientAuth::user();
$brand      = Settings::company();

// is_active_nav('/portal') matches every page in the portal, so the
// overview would stay lit on all of them. Matched on the path itself.
$here = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$on   = static fn(string $p): bool => str_ends_with($here, $p);
$in   = static fn(string $p): bool => str_contains($here, $p);

$smsOn = Settings::bool('bulk_sms_enabled', true);

// Badge counts — fetched once per page load from indexed queries.
$portalClientId    = $portalUser ? ($portalUser['client_id'] ?? null) : null;
$notifUnread       = $portalClientId ? ClientNotifier::unreadCount((int) $portalClientId) : 0;
$msgUnread         = $portalClientId ? ClientNotifier::unreadMessages((int) $portalClientId) : 0;
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

<?php if (!$portalUser): ?>
  <?php // Signed out — the sign-in pages draw themselves, with no menu to
        // show and nothing to navigate to. ?>
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
    <a class="sidebar__brand" href="<?= url('/portal') ?>" style="text-decoration:none">
      <?php if ($brand['logo']): ?>
        <?php // /brand/logo, not /files — that route is behind the staff
              // guard, so a client got a broken image where the logo
              // should be. ?>
        <img class="sidebar__logo" src="<?= url('/brand/logo') ?>" alt="<?= e($brand['name']) ?>">
      <?php else: ?>
        <span class="sidebar__mark">SF</span>
        <span>
          <span class="sidebar__name"><?= e($brand['name']) ?></span>
          <span class="sidebar__sub">CUSTOMER PORTAL</span>
        </span>
      <?php endif; ?>
    </a>

    <nav class="sidebar__nav">
      <a class="nav-link <?= $on('/portal') ? 'is-active' : '' ?>" href="<?= url('/portal') ?>">
        <?= icon('grid', 'nav-link__icon') ?> Overview
      </a>

      <div class="nav-group__label">Your account</div>
      <a class="nav-link <?= $in('/portal/quotations') ? 'is-active' : '' ?>" href="<?= url('/portal/quotations') ?>">
        <?= icon('file-text', 'nav-link__icon') ?> Quotations
      </a>
      <a class="nav-link <?= $in('/portal/invoices') ? 'is-active' : '' ?>" href="<?= url('/portal/invoices') ?>">
        <?= icon('receipt', 'nav-link__icon') ?> Invoices
      </a>
      <a class="nav-link <?= $in('/portal/receipts') ? 'is-active' : '' ?>" href="<?= url('/portal/receipts') ?>">
        <?= icon('check-circle', 'nav-link__icon') ?> Receipts
      </a>
      <a class="nav-link <?= $on('/portal/statement') ? 'is-active' : '' ?>" href="<?= url('/portal/statement') ?>">
        <?= icon('list', 'nav-link__icon') ?> Statement
      </a>
      <a class="nav-link <?= $on('/portal/services') ? 'is-active' : '' ?>" href="<?= url('/portal/services') ?>">
        <?= icon('repeat', 'nav-link__icon') ?> Renewals
      </a>

      <div class="nav-group__label">Working with us</div>
      <a class="nav-link <?= $in('/portal/jobs') ? 'is-active' : '' ?>" href="<?= url('/portal/jobs') ?>">
        <?= icon('briefcase', 'nav-link__icon') ?> My jobs
      </a>
      <a class="nav-link <?= $on('/portal/catalogue') ? 'is-active' : '' ?>" href="<?= url('/portal/catalogue') ?>">
        <?= icon('package', 'nav-link__icon') ?> What we do
      </a>
      <a class="nav-link <?= $on('/portal/requests') ? 'is-active' : '' ?>" href="<?= url('/portal/requests') ?>">
        <?= icon('inbox', 'nav-link__icon') ?> My requests
      </a>
      <a class="nav-link <?= $on('/portal/briefs') ? 'is-active' : '' ?>" href="<?= url('/portal/briefs') ?>">
        <?= icon('clipboard', 'nav-link__icon') ?> Briefs
      </a>
      <a class="nav-link <?= $on('/portal/uploads') ? 'is-active' : '' ?>" href="<?= url('/portal/uploads') ?>">
        <?= icon('paperclip', 'nav-link__icon') ?> Send artwork
      </a>

      <div class="nav-group__label">Your account &amp; help</div>
      <div class="portal-nav-badge-wrap">
        <a class="nav-link <?= $on('/portal/notifications') ? 'is-active' : '' ?>"
           href="<?= url('/portal/notifications') ?>">
          <?= icon('bell', 'nav-link__icon') ?> Notifications
        </a>
        <?php if ($notifUnread > 0): ?>
          <span class="nav-link-badge"><?= $notifUnread > 9 ? '9+' : (int) $notifUnread ?></span>
        <?php endif; ?>
      </div>
      <div class="portal-nav-badge-wrap">
        <a class="nav-link <?= $on('/portal/support') ? 'is-active' : '' ?>"
           href="<?= url('/portal/support') ?>">
          <?= icon('message-circle', 'nav-link__icon') ?> Support
        </a>
        <?php if ($msgUnread > 0): ?>
          <span class="nav-link-badge"><?= $msgUnread > 9 ? '9+' : (int) $msgUnread ?></span>
        <?php endif; ?>
      </div>

      <?php // Only when we actually sell SMS. A link to a product that is
            // switched off is a promise we cannot keep. ?>
      <?php if ($smsOn): ?>
        <div class="nav-group__label">Bulk SMS</div>
        <a class="nav-link <?= $on('/portal/sms') ? 'is-active' : '' ?>" href="<?= url('/portal/sms') ?>">
          <?= icon('send', 'nav-link__icon') ?> Send a message
        </a>
        <a class="nav-link <?= $in('/portal/sms/campaigns') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/campaigns') ?>">
          <?= icon('layers', 'nav-link__icon') ?> Campaigns
        </a>
        <a class="nav-link <?= $on('/portal/sms/send-file') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/send-file') ?>">
          <?= icon('file-text', 'nav-link__icon') ?> Send from a file
        </a>
        <a class="nav-link <?= $on('/portal/sms/scheduled') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/scheduled') ?>">
          <?= icon('clock', 'nav-link__icon') ?> Scheduled
        </a>
        <a class="nav-link <?= $in('/portal/sms/contacts') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/contacts') ?>">
          <?= icon('users', 'nav-link__icon') ?> Contacts
        </a>
        <a class="nav-link <?= $on('/portal/sms/groups') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/groups') ?>">
          <?= icon('list', 'nav-link__icon') ?> Contact lists
        </a>
        <a class="nav-link <?= $on('/portal/sms/templates') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/templates') ?>">
          <?= icon('file-text', 'nav-link__icon') ?> Saved messages
        </a>
        <a class="nav-link <?= $on('/portal/sms/reports') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/reports') ?>">
          <?= icon('activity', 'nav-link__icon') ?> Delivery reports
        </a>
        <a class="nav-link <?= $on('/portal/sms/senders') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/senders') ?>">
          <?= icon('shield', 'nav-link__icon') ?> Sender IDs
        </a>
        <a class="nav-link <?= $in('/portal/sms/buy') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/buy') ?>">
          <?= icon('credit-card', 'nav-link__icon') ?> Buy units
        </a>
        <a class="nav-link <?= $in('/portal/sms/api') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/api') ?>">
          <?= icon('code', 'nav-link__icon') ?> Developer API
        </a>
        <a class="nav-link <?= $on('/portal/sms/settings') ? 'is-active' : '' ?>" href="<?= url('/portal/sms/settings') ?>">
          <?= icon('sliders', 'nav-link__icon') ?> SMS settings
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

      <?php // Bell icon — links to notifications list; badge shows unread count. ?>
      <div class="topbar-badge-wrap">
        <a class="icon-btn" href="<?= url('/portal/notifications') ?>"
           title="Notifications" aria-label="Notifications">
          <?= icon('bell') ?>
        </a>
        <?php if ($notifUnread > 0): ?>
          <span class="topbar-badge" aria-label="<?= (int) $notifUnread ?> unread">
            <?= $notifUnread > 9 ? '9+' : (int) $notifUnread ?>
          </span>
        <?php endif; ?>
      </div>

      <button class="icon-btn" type="button" data-theme-toggle
              title="Switch between dark and light" aria-label="Switch between dark and light">
        <span data-theme-icon="dark"><?= icon('moon') ?></span>
        <span data-theme-icon="light" hidden><?= icon('sun') ?></span>
      </button>

      <div class="dropdown">
        <button class="portal-who" type="button" data-dropdown aria-label="Your account">
          <span class="avatar avatar--sm"><?= e(initials($portalUser['name'])) ?></span>
          <span class="portal-who__name"><?= e($portalUser['client_name'] ?: $portalUser['name']) ?></span>
          <?= icon('chevron-down') ?>
        </button>
        <div class="dropdown__menu dropdown__menu--right">
          <div class="dropdown__label"><?= e($portalUser['email']) ?></div>
          <a class="dropdown__item" href="<?= url('/portal/profile') ?>">
            <?= icon('user') ?> My profile
          </a>
          <?php // Out to the company website. It is the front door of this
                // domain and the portal sits behind it, so without this the
                // only way across is to edit the address bar. ?>
          <a class="dropdown__item" href="/" target="_blank" rel="noopener">
            <?= icon('external-link') ?> Visit our website
          </a>
          <div class="dropdown__divider"></div>
          <form method="post" action="<?= url('/portal/logout') ?>">
            <?= csrf_field() ?>
            <button class="dropdown__item" type="submit"><?= icon('log-out') ?> Sign out</button>
          </form>
        </div>
      </div>
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
  </div>
</div>

<?php endif; ?>

<?= js_tag() ?>
</body>
</html>
