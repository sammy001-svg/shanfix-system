<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Database;

$me = auth();

// Sidebar chat badge: unread messages across all conversations.
$unread = 0;
if ($me) {
    $unread = (int) Database::scalar(
        'SELECT COUNT(*)
           FROM chat_messages m
           JOIN chat_participants p ON p.conversation_id = m.conversation_id
          WHERE p.user_id = :uid
            AND m.user_id <> :uid2
            AND m.deleted_at IS NULL
            AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)',
        ['uid' => $me['id'], 'uid2' => $me['id']],
        0
    );
}

// Messages that gave up after all retries — worth chasing.
$failedMessages = 0;
if ($me && can('documents.view')) {
    $failedMessages = (int) Database::scalar(
        "SELECT COUNT(*) FROM notifications WHERE status = 'failed'",
        [],
        0
    );
}

// The bell: things addressed to this person that they have not read.
$myAlerts = 0;
if ($me) {
    $myAlerts = \App\Services\StaffNotifier::unreadCount((int) $me['id']);
}

// Jobs badge: what this user still has to produce.
$openJobs = 0;
if ($me && can('jobs.view')) {
    $openJobs = (int) Database::scalar(
        "SELECT COUNT(*) FROM jobs
          WHERE stage NOT IN ('delivered','cancelled')
            AND (assigned_to = :uid OR assigned_to IS NULL)",
        ['uid' => $me['id']],
        0
    );
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title ?? 'Dashboard') ?> · <?= e($appName ?? 'Shanfix Technology') ?></title>
<?php
  // Runs before the stylesheet is applied and before anything paints, so a
  // user who chose light mode never sees a dark frame flash first. It is
  // deliberately tiny and inline: an external file would be fetched too late
  // to prevent exactly the flash it exists to avoid.
  //
  // Dark is the default, so with nothing stored there is nothing to do.
?>
<script nonce="<?= e(csp_nonce()) ?>">
  (function () {
    try {
      var saved = localStorage.getItem('shanfix-theme');
      if (saved === 'light' || saved === 'dark') {
        document.documentElement.setAttribute('data-theme', saved);
      }
    } catch (e) {
      /* Private browsing can refuse localStorage; the default theme stands. */
    }
  })();
</script>
<?= css_tag() ?>
<link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">

<?php // Installable app. The base path lets offline.js build URLs that work
      // whether the app sits at the domain root or in a sub-folder. ?>
<meta name="app-base" content="<?= e(base_path()) ?>">
<meta name="theme-color" content="#0C2B4A">
<link rel="manifest" href="<?= url('manifest.webmanifest') ?>">

<?php // iOS ignores the manifest and wants its own tags. ?>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(setting('company_name', 'Shanfix')) ?>">
<link rel="apple-touch-icon" href="<?= url('icon-192.png') ?>">
</head>
<body>

<div class="layout">

  <div class="sidebar__scrim"></div>

  <aside class="sidebar">
    <a class="sidebar__brand" href="<?= url('/dashboard') ?>" style="text-decoration:none">
      <?php if (setting('company_logo')): ?>
        <img class="sidebar__logo" src="<?= url('brand/logo') ?>"
             alt="<?= e(setting('company_name', 'Shanfix Technology')) ?>">
      <?php else: ?>
        <span class="sidebar__mark">SF</span>
      <?php endif; ?>
      <span>
        <span class="sidebar__name">Shanfix</span>
        <span class="sidebar__sub">TECHNOLOGY</span>
      </span>
    </a>

    <nav class="sidebar__nav">
      <a class="nav-link <?= is_active_nav('/dashboard') ? 'is-active' : '' ?>" href="<?= url('/dashboard') ?>">
        <?= icon('grid', 'nav-link__icon') ?> Dashboard
      </a>

      <?php if (can('leads.view')): ?>
        <div class="nav-group__label">Sales</div>
        <a class="nav-link <?= is_active_nav('/leads') ? 'is-active' : '' ?>" href="<?= url('/leads') ?>">
          <?= icon('target', 'nav-link__icon') ?> Leads
        </a>
      <?php endif; ?>

      <?php if (can('clients.view')): ?>
        <a class="nav-link <?= is_active_nav('/clients') ? 'is-active' : '' ?>" href="<?= url('/clients') ?>">
          <?= icon('users', 'nav-link__icon') ?> Clients
        </a>
      <?php endif; ?>

      <?php if (can('documents.view')): ?>
        <a class="nav-link <?= is_active_nav('/proposals') ? 'is-active' : '' ?>" href="<?= url('/proposals') ?>">
          <?= icon('briefcase', 'nav-link__icon') ?> Proposals
        </a>
        <a class="nav-link <?= is_active_nav('/quotations') ? 'is-active' : '' ?>" href="<?= url('/quotations') ?>">
          <?= icon('file-text', 'nav-link__icon') ?> Quotations
        </a>
        <a class="nav-link <?= is_active_nav('/invoices') ? 'is-active' : '' ?>" href="<?= url('/invoices') ?>">
          <?= icon('receipt', 'nav-link__icon') ?> Invoices
        </a>
        <a class="nav-link <?= is_active_nav('/receipts') ? 'is-active' : '' ?>" href="<?= url('/receipts') ?>">
          <?= icon('check-circle', 'nav-link__icon') ?> Receipts
        </a>
        <a class="nav-link <?= is_active_nav('/agreements') ? 'is-active' : '' ?>" href="<?= url('/agreements') ?>">
          <?= icon('shield', 'nav-link__icon') ?> Agreements
        </a>
      <?php endif; ?>

      <?php if (can('jobs.view')): ?>
        <div class="nav-group__label">Production</div>
      <?php endif; ?>
      <?php if (can('artwork.view')): ?>
        <a class="nav-link <?= is_active_nav('/artwork') ? 'is-active' : '' ?>" href="<?= url('/artwork') ?>">
          <?= icon('image', 'nav-link__icon') ?> Artwork
        </a>
      <?php endif; ?>
      <?php if (can('jobs.view')): ?>
        <a class="nav-link <?= is_active_nav('/jobs') ? 'is-active' : '' ?>" href="<?= url('/jobs') ?>">
          <?= icon('printer', 'nav-link__icon') ?> Job Board
          <?php if (!empty($openJobs)): ?>
            <span class="nav-link__badge"><?= (int) $openJobs > 99 ? '99+' : (int) $openJobs ?></span>
          <?php endif; ?>
        </a>
        <?php if (can('delivery.view')): ?>
          <a class="nav-link <?= is_active_nav('/delivery-notes') ? 'is-active' : '' ?>" href="<?= url('/delivery-notes') ?>">
            <?= icon('archive', 'nav-link__icon') ?> Delivery Notes
          </a>
        <?php endif; ?>
      <?php endif; ?>

      <div class="nav-group__label">Catalogue</div>
      <?php if (can('inventory.view')): ?>
        <a class="nav-link <?= is_active_nav('/inventory') ? 'is-active' : '' ?>" href="<?= url('/inventory') ?>">
          <?= icon('package', 'nav-link__icon') ?> Inventory
        </a>
      <?php endif; ?>
      <?php if (can('purchases.view')): ?>
        <a class="nav-link <?= is_active_nav('/purchase-orders') ? 'is-active' : '' ?>" href="<?= url('/purchase-orders') ?>">
          <?= icon('inbox', 'nav-link__icon') ?> Purchasing
        </a>
      <?php endif; ?>
      <?php if (can('services.view')): ?>
        <a class="nav-link <?= is_active_nav('/services') ? 'is-active' : '' ?>" href="<?= url('/services') ?>">
          <?= icon('layers', 'nav-link__icon') ?> Services
        </a>
      <?php endif; ?>
      <?php if (can('subscriptions.view')): ?>
        <a class="nav-link <?= is_active_nav('/subscriptions') ? 'is-active' : '' ?>" href="<?= url('/subscriptions') ?>">
          <?= icon('refresh', 'nav-link__icon') ?> Recurring
        </a>
      <?php endif; ?>

      <?php if (can('payments.view') || can('expenses.view')): ?>
        <div class="nav-group__label">Finance</div>
        <?php if (can('payments.view')): ?>
          <a class="nav-link <?= is_active_nav('/payments') ? 'is-active' : '' ?>" href="<?= url('/payments') ?>">
            <?= icon('credit-card', 'nav-link__icon') ?> Payments
          </a>
        <?php endif; ?>
        <?php if (can('expenses.view')): ?>
          <a class="nav-link <?= is_active_nav('/expenses') ? 'is-active' : '' ?>" href="<?= url('/expenses') ?>">
            <?= icon('trending-down', 'nav-link__icon') ?> Expenses
          </a>
        <?php endif; ?>
        <?php if (can('reports.view')): ?>
          <a class="nav-link <?= is_active_nav('/reports') ? 'is-active' : '' ?>" href="<?= url('/reports') ?>">
            <?= icon('bar-chart', 'nav-link__icon') ?> Reports
          </a>
        <?php endif; ?>
      <?php endif; ?>

      <div class="nav-group__label">Workspace</div>
      <?php if (can('whatsapp.view')): ?>
        <a class="nav-link <?= is_active_nav('/whatsapp') ? 'is-active' : '' ?>" href="<?= url('/whatsapp') ?>">
          <?= icon('message', 'nav-link__icon') ?> WhatsApp
          <span id="wa-unread-badge" class="nav-link__badge hidden"
                data-url="<?= url('/whatsapp/unread') ?>"></span>
        </a>
      <?php endif; ?>
      <?php if (can('meetings.view')): ?>
        <a class="nav-link <?= is_active_nav('/meetings') ? 'is-active' : '' ?>" href="<?= url('/meetings') ?>">
          <?= icon('video', 'nav-link__icon') ?> Meetings
        </a>
      <?php endif; ?>
      <?php if (can('chat.use')): ?>
        <a class="nav-link <?= is_active_nav('/chat') ? 'is-active' : '' ?>" href="<?= url('/chat') ?>">
          <?= icon('message', 'nav-link__icon') ?> Team Chat
          <span id="chat-unread-badge"
                class="nav-link__badge <?= $unread ? '' : 'hidden' ?>"
                data-url="<?= url('/chat/unread-count') ?>"><?= $unread > 99 ? '99+' : $unread ?></span>
        </a>
      <?php endif; ?>
      <a class="nav-link <?= is_active_nav('/reminders') ? 'is-active' : '' ?>" href="<?= url('/reminders') ?>">
        <?= icon('bell', 'nav-link__icon') ?> My Reminders
      </a>
      <?php if (can('documents.view')): ?>
        <a class="nav-link <?= is_active_nav('/notifications') ? 'is-active' : '' ?>" href="<?= url('/notifications') ?>">
          <?= icon('send', 'nav-link__icon') ?> Messages
          <?php if (!empty($failedMessages)): ?>
            <span class="nav-link__badge" style="background:var(--red-600)"><?= (int) $failedMessages ?></span>
          <?php endif; ?>
        </a>
      <?php endif; ?>

      <?php if (can('sms.campaign')): ?>
        <a class="nav-link <?= is_active_nav('/sms-campaigns') ? 'is-active' : '' ?>"
           href="<?= url('/sms-campaigns') ?>">
          <?= icon('message', 'nav-link__icon') ?> Bulk SMS
        </a>
      <?php endif; ?>

      <?php if (can('users.view') || can('settings.manage')): ?>
        <div class="nav-group__label">Administration</div>
        <?php if (can('users.view')): ?>
          <a class="nav-link <?= is_active_nav('/users') ? 'is-active' : '' ?>" href="<?= url('/users') ?>">
            <?= icon('shield', 'nav-link__icon') ?> Users &amp; Roles
          </a>
        <?php endif; ?>
        <?php if (can('settings.manage')): ?>
          <a class="nav-link <?= is_active_nav('/settings') ? 'is-active' : '' ?>" href="<?= url('/settings') ?>">
            <?= icon('settings', 'nav-link__icon') ?> Settings
          </a>
        <?php endif; ?>
        <?php if (can('audit.view')): ?>
          <a class="nav-link <?= is_active_nav('/audit') ? 'is-active' : '' ?>" href="<?= url('/audit') ?>">
            <?= icon('activity', 'nav-link__icon') ?> Audit Trail
          </a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn sidebar__toggle" data-sidebar-toggle type="button" aria-label="Toggle navigation">
        <?= icon('menu') ?>
      </button>

      <div class="topbar__title"><?= e($title ?? 'Dashboard') ?></div>

      <div class="topbar__spacer"></div>

      <?php // The bell. Only drawn when there is something unread — an
            // always-visible zero teaches people to stop looking. ?>
      <a class="icon-btn" href="<?= url('/alerts') ?>" title="My alerts"
         style="position:relative">
        <?= icon('bell') ?>
        <?php if (!empty($myAlerts)): ?>
          <span class="nav-link__badge"
                style="position:absolute;top:2px;right:2px;background:var(--red-600)">
            <?= (int) $myAlerts > 9 ? '9+' : (int) $myAlerts ?>
          </span>
        <?php endif; ?>
      </a>

      <form class="topbar__search" action="<?= url('/search') ?>" method="get" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" placeholder="Search clients, invoices, leads…"
               value="<?= e($_GET['q'] ?? '') ?>" aria-label="Search">
      </form>

      <button class="icon-btn" type="button" data-theme-toggle
              title="Switch between dark and light" aria-label="Switch between dark and light">
        <span data-theme-icon="dark"><?= icon('moon') ?></span>
        <span data-theme-icon="light" hidden><?= icon('sun') ?></span>
      </button>

      <a class="icon-btn" href="<?= url('/reminders') ?>" title="Reminders" aria-label="Reminders">
        <?= icon('bell') ?>
        <?php if (!empty($dueReminders)): ?><span class="icon-btn__dot"></span><?php endif; ?>
      </a>

      <div class="dropdown">
        <div class="userchip" data-dropdown tabindex="0" role="button" aria-haspopup="true">
          <span class="avatar" style="background:<?= e($me['avatar_color'] ?? '#0C2B4A') ?>">
            <?= e(initials($me['name'] ?? '')) ?>
          </span>
          <span class="userchip__meta">
            <span class="userchip__name"><?= e($me['name'] ?? '') ?></span>
            <span class="userchip__role"><?= e(label_of($me['role'] ?? '')) ?></span>
          </span>
        </div>

        <div class="dropdown__menu">
          <a class="dropdown__item" href="<?= url('/profile') ?>"><?= icon('user') ?> My Profile</a>
          <?php if (can('settings.manage')): ?>
            <a class="dropdown__item" href="<?= url('/settings') ?>"><?= icon('settings') ?> Settings</a>
          <?php endif; ?>
          <div class="dropdown__divider"></div>
          <form action="<?= url('/logout') ?>" method="post" data-no-guard>
            <?= csrf_field() ?>
            <button class="dropdown__item dropdown__item--danger" type="submit">
              <?= icon('log-out') ?> Sign out
            </button>
          </form>
        </div>
      </div>
    </header>

    <main class="content">
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
  </div>
</div>

<?= js_tag() ?>
<?= js_tag('js/offline.js') ?>
</body>
</html>
