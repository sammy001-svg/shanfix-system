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
<?php // The system shares a domain with the company website now, so a
      // crawler that finds its way in would otherwise index the sign-in
      // page, the portals and every URL it can reach from them. None of
      // this belongs in a search result: it is a business system, not
      // publishing. robots.txt asks crawlers not to fetch these; this
      // says not to list them even if a URL turns up elsewhere. ?>
<meta name="robots" content="noindex, nofollow">
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
<?php // The first tab stop on every page. Without it, anyone working by
      // keyboard tabs through the whole sidebar before reaching the page
      // they actually opened — on every single navigation. ?>
<a class="skip-link" href="#main">Skip to content</a>

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

    <?php
      // Sidebar groups.
      //
      // Every group is a <details>, so the open/closed state is the
      // browser's own — it works with no JavaScript, it is keyboard
      // reachable, and screen readers announce it. Two things decide
      // whether a group starts open:
      //
      //   1. the group holding the page you are on always starts open,
      //      worked out here on the server so nothing flashes shut and
      //      then back open after the scripts load;
      //   2. anything else you opened or closed by hand, remembered in
      //      localStorage by the group key.
      //
      // $navOpen() answers (1). Pass it the same path prefixes the links
      // inside the group use.
      $navOpen = static function (string ...$paths): bool {
          foreach ($paths as $p) {
              if (is_active_nav($p)) {
                  return true;
              }
          }
          return false;
      };
    ?>

    <nav class="sidebar__nav">
      <?php // Above the groups: the two pages that belong to whoever is
            // signed in rather than to a department, and that everybody
            // can reach. They are never buried inside a dropdown. ?>
      <a class="nav-link <?= is_active_nav('/dashboard') ? 'is-active' : '' ?>" href="<?= url('/dashboard') ?>">
        <?= icon('grid', 'nav-link__icon') ?> Dashboard
      </a>
      <a class="nav-link <?= is_active_nav('/reminders') ? 'is-active' : '' ?>" href="<?= url('/reminders') ?>">
        <?= icon('bell', 'nav-link__icon') ?> My Reminders
      </a>

      <?php
        // ── Sales ──────────────────────────────────────────────────────────
        $showSales = can('leads.view') || can('clients.view')
                  || can('requests.view') || can('letters.view');
      ?>
      <?php if ($showSales): ?>
        <details class="nav-group" data-nav-group="sales"
                 <?= $navOpen('/leads', '/clients', '/requests', '/letters') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('target', 'nav-group__icon') ?>
            <span class="nav-group__text">Sales</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

            <?php if (can('leads.view')): ?>
              <a class="nav-link <?= is_active_nav('/leads') ? 'is-active' : '' ?>" href="<?= url('/leads') ?>">
                <?= icon('target', 'nav-link__icon') ?> Leads
              </a>
            <?php endif; ?>

            <?php if (can('clients.view')): ?>
              <a class="nav-link <?= is_active_nav('/clients') ? 'is-active' : '' ?>" href="<?= url('/clients') ?>">
                <?= icon('users', 'nav-link__icon') ?> Clients
              </a>
            <?php endif; ?>

            <?php if (can('requests.view')): ?>
              <a class="nav-link <?= is_active_nav('/requests') ? 'is-active' : '' ?>" href="<?= url('/requests') ?>">
                <?= icon('inbox', 'nav-link__icon') ?> Job Briefs
              </a>
            <?php endif; ?>

            <?php if (can('letters.view')): ?>
              <a class="nav-link <?= is_active_nav('/letters') ? 'is-active' : '' ?>" href="<?= url('/letters') ?>">
                <?= icon('mail', 'nav-link__icon') ?> Letters
              </a>
            <?php endif; ?>

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── Documents ──────────────────────────────────────────────────────
      ?>
      <?php if (can('documents.view')): ?>
        <details class="nav-group" data-nav-group="documents"
                 <?= $navOpen('/proposals', '/quotations', '/invoices', '/receipts', '/agreements') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('file-text', 'nav-group__icon') ?>
            <span class="nav-group__text">Documents</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

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

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── Production ─────────────────────────────────────────────────────
        $showProduction = can('jobs.view') || can('artwork.view');
      ?>
      <?php if ($showProduction): ?>
        <details class="nav-group" data-nav-group="production"
                 <?= $navOpen('/artwork', '/jobs', '/delivery-notes') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('printer', 'nav-group__icon') ?>
            <span class="nav-group__text">Production</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

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

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── Stock & Services ───────────────────────────────────────────────
        // What we sell and what we buy to make it: the price list, the
        // shelf it comes off, and the orders that refill that shelf.
        $showCatalogue = can('inventory.view') || can('purchases.view')
                      || can('services.view') || can('subscriptions.view');
      ?>
      <?php if ($showCatalogue): ?>
        <details class="nav-group" data-nav-group="catalogue"
                 <?= $navOpen('/services', '/subscriptions', '/inventory', '/purchase-orders') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('list', 'nav-group__icon') ?>
            <span class="nav-group__text">Stock &amp; Services</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

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

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── Finance ────────────────────────────────────────────────────────
        $showFinance = can('payments.view') || can('expenses.view') || can('reports.view');
      ?>
      <?php if ($showFinance): ?>
        <details class="nav-group" data-nav-group="finance"
                 <?= $navOpen('/payments', '/expenses', '/reports') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('credit-card', 'nav-group__icon') ?>
            <span class="nav-group__text">Finance</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

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

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── Partners ───────────────────────────────────────────────────────
        // The reseller programme. It used to sit under Sales, but partners
        // are not our leads — they bring their own, and what we owe them
        // is settled here rather than in Finance.
      ?>
      <?php if (can('partners.view')): ?>
        <?php $partnersWaiting = (int) \App\Core\Database::scalar(
            "SELECT COUNT(*) FROM partners WHERE status = 'pending'", [], 0
        ); ?>
        <details class="nav-group" data-nav-group="partners"
                 <?= $navOpen('/partners-admin', '/payouts') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('users', 'nav-group__icon') ?>
            <span class="nav-group__text">Partners</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

            <a class="nav-link <?= is_active_nav('/partners-admin') ? 'is-active' : '' ?>"
               href="<?= url('/partners-admin') ?>">
              <?= icon('users', 'nav-link__icon') ?> Partners
              <?php if ($partnersWaiting > 0): ?>
                <span class="nav-link__badge"><?= $partnersWaiting ?></span>
              <?php endif; ?>
            </a>
            <a class="nav-link <?= is_active_nav('/payouts') ? 'is-active' : '' ?>"
               href="<?= url('/payouts') ?>">
              <?= icon('dollar', 'nav-link__icon') ?> Payouts
            </a>

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── Communication ──────────────────────────────────────────────────
        // Every inbox in one place: the ones strangers and clients write
        // into, and the ones we use among ourselves.
        $showComms = can('mail.use') || can('livechat.use') || can('whatsapp.view')
                  || can('documents.view') || can('chat.use') || can('meetings.view');
      ?>
      <?php if ($showComms): ?>
        <details class="nav-group" data-nav-group="comms"
                 <?= $navOpen('/mail', '/livechat', '/whatsapp', '/notifications', '/chat', '/meetings') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('message', 'nav-group__icon') ?>
            <span class="nav-group__text">Communication</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

            <?php if (can('mail.use')): ?>
              <a class="nav-link <?= is_active_nav('/mail') ? 'is-active' : '' ?>" href="<?= url('/mail') ?>">
                <?= icon('mail', 'nav-link__icon') ?> Email
                <span id="mail-unread-badge" class="nav-link__badge hidden" data-url="<?= url('/mail/unread') ?>"></span>
              </a>
            <?php endif; ?>

            <?php // Live chat with people on the website. Distinct from Team
                  // Chat below, which is colleagues talking to each other —
                  // this one is a stranger on the marketing site who wants an
                  // answer now, so the badge counts only those still waiting
                  // for a human rather than everything unread. ?>
            <?php if (can('livechat.use')): ?>
              <a class="nav-link <?= is_active_nav('/livechat') ? 'is-active' : '' ?>" href="<?= url('/livechat') ?>">
                <?= icon('inbox', 'nav-link__icon') ?> Live Chat
                <span id="livechat-waiting-badge"
                      class="nav-link__badge hidden"
                      data-url="<?= url('/livechat/waiting') ?>"></span>
              </a>
            <?php endif; ?>

            <?php if (can('whatsapp.view')): ?>
              <a class="nav-link <?= is_active_nav('/whatsapp') ? 'is-active' : '' ?>" href="<?= url('/whatsapp') ?>">
                <?= icon('message', 'nav-link__icon') ?> WhatsApp
                <span id="wa-unread-badge" class="nav-link__badge hidden"
                      data-url="<?= url('/whatsapp/unread') ?>"></span>
              </a>
            <?php endif; ?>

            <?php if (can('documents.view')): ?>
              <a class="nav-link <?= is_active_nav('/notifications') ? 'is-active' : '' ?>" href="<?= url('/notifications') ?>">
                <?= icon('send', 'nav-link__icon') ?> Messages
                <?php if (!empty($failedMessages)): ?>
                  <span class="nav-link__badge" style="background:var(--red-600)"><?= (int) $failedMessages ?></span>
                <?php endif; ?>
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

            <?php if (can('meetings.view')): ?>
              <a class="nav-link <?= is_active_nav('/meetings') ? 'is-active' : '' ?>" href="<?= url('/meetings') ?>">
                <?= icon('video', 'nav-link__icon') ?> Meetings
              </a>
            <?php endif; ?>

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── Marketing ──────────────────────────────────────────────────────
        // Us reaching out, rather than someone reaching us.
        $showMarketing = can('sms.campaign') || can('newsletter.view')
                      || can('testimonials.manage');
      ?>
      <?php if ($showMarketing): ?>
        <details class="nav-group" data-nav-group="marketing"
                 <?= $navOpen('/sms-campaigns', '/newsletter', '/testimonials') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('send', 'nav-group__icon') ?>
            <span class="nav-group__text">Marketing</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

            <?php // Two different things that both involve texting. This one is
                  // us messaging our own clients; the SMS platform further down
                  // is the product customers and partners send their own texts
                  // through — which is why they are no longer neighbours. ?>
            <?php if (can('sms.campaign')): ?>
              <a class="nav-link <?= is_active_nav('/sms-campaigns') ? 'is-active' : '' ?>"
                 href="<?= url('/sms-campaigns') ?>">
                <?= icon('message', 'nav-link__icon') ?> Text our clients
              </a>
            <?php endif; ?>

            <?php if (can('newsletter.view')): ?>
              <a class="nav-link <?= is_active_nav('/newsletter') ? 'is-active' : '' ?>" href="<?= url('/newsletter') ?>">
                <?= icon('mail', 'nav-link__icon') ?> Newsletter
              </a>
            <?php endif; ?>

            <?php if (can('testimonials.manage')): ?>
              <a class="nav-link <?= is_active_nav('/testimonials') ? 'is-active' : '' ?>" href="<?= url('/testimonials') ?>">
                <?= icon('star', 'nav-link__icon') ?> Testimonials
              </a>
            <?php endif; ?>

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── Products ───────────────────────────────────────────────────────
        // Things we run for other people to use, rather than tools we use
        // ourselves. Only the SMS platform so far; WhatsApp and USSD land
        // beside it.
      ?>
      <?php if (can('bulksms.view')): ?>
        <?php $smsWaiting = (int) \App\Core\Database::scalar(
            "SELECT (SELECT COUNT(*) FROM bulk_sender_ids WHERE status = 'pending')
                  + (SELECT COUNT(*) FROM bulk_purchases p JOIN bulk_accounts s ON s.id = p.seller_account_id
                      WHERE p.status = 'pending' AND s.owner_type = 'house')", [], 0); ?>
        <details class="nav-group" data-nav-group="products"
                 <?= $navOpen('/bulk-sms') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('zap', 'nav-group__icon') ?>
            <span class="nav-group__text">Products</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

            <a class="nav-link <?= is_active_nav('/bulk-sms') ? 'is-active' : '' ?>"
               href="<?= url('/bulk-sms') ?>">
              <?= icon('smartphone', 'nav-link__icon') ?> SMS platform
              <?php if ($smsWaiting > 0): ?>
                <span class="nav-link__badge"><?= $smsWaiting ?></span>
              <?php endif; ?>
            </a>

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── People ─────────────────────────────────────────────────────────
        $showHr = can('hr.view') || can('payroll.view') || can('equipment.view');
      ?>
      <?php if ($showHr): ?>
        <details class="nav-group" data-nav-group="people"
                 <?= $navOpen('/hr', '/staff', '/payroll', '/equipment') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('user', 'nav-group__icon') ?>
            <span class="nav-group__text">People</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

            <a class="nav-link <?= is_active_nav('/hr') ? 'is-active' : '' ?>"
               href="<?= url('/hr') ?>">
              <?= icon('grid', 'nav-link__icon') ?> HR Overview
            </a>

            <?php if (can('hr.view')): ?>
              <a class="nav-link <?= is_active_nav('/staff') ? 'is-active' : '' ?>"
                 href="<?= url('/staff') ?>">
                <?= icon('user', 'nav-link__icon') ?> Staff
              </a>
            <?php endif; ?>

            <?php if (can('payroll.view')): ?>
              <a class="nav-link <?= is_active_nav('/payroll') ? 'is-active' : '' ?>"
                 href="<?= url('/payroll') ?>">
                <?= icon('briefcase', 'nav-link__icon') ?> Payroll
              </a>
            <?php endif; ?>

            <?php if (can('equipment.view')): ?>
              <?php $equipmentDue = (int) \App\Core\Database::scalar(
                  "SELECT COUNT(*) FROM equipment
                    WHERE status <> 'disposed' AND next_service_on IS NOT NULL
                      AND next_service_on <= :h",
                  ['h' => date('Y-m-d', strtotime('+' . (int) setting('equipment_service_warn_days', 14) . ' days'))],
                  0
              ); ?>
              <a class="nav-link <?= is_active_nav('/equipment') ? 'is-active' : '' ?>"
                 href="<?= url('/equipment') ?>">
                <?= icon('package', 'nav-link__icon') ?> Equipment
                <?php if ($equipmentDue > 0): ?>
                  <span class="nav-link__badge"><?= $equipmentDue ?></span>
                <?php endif; ?>
              </a>
            <?php endif; ?>

          </div>
        </details>
      <?php endif; ?>

      <?php
        // ── Administration ─────────────────────────────────────────────────
        $showAdmin = can('users.view') || can('settings.manage') || can('audit.view');
      ?>
      <?php if ($showAdmin): ?>
        <details class="nav-group" data-nav-group="admin"
                 <?= $navOpen('/users', '/settings', '/audit') ? 'open' : '' ?>>
          <summary class="nav-group__label">
            <?= icon('settings', 'nav-group__icon') ?>
            <span class="nav-group__text">Administration</span>
            <span class="nav-group__dot" aria-hidden="true"></span>
            <?= icon('chevron-down', 'nav-group__caret') ?>
          </summary>
          <div class="nav-group__items">

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

          </div>
        </details>
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
        <?php // A shortcut nobody knows about is worth nothing, so it says so
              // here. aria-hidden because it is a hint to the eye — the input
              // already carries its own label. ?>
        <kbd class="topbar__kbd" aria-hidden="true">Ctrl K</kbd>
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
          <?php // Out to the company website. It is the front door of this
                // domain and the system sits behind it, so without this the
                // only way across is to edit the address bar. ?>
          <a class="dropdown__item" href="/" target="_blank" rel="noopener">
            <?= icon('external-link') ?> Visit our website
          </a>
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

    <main class="content" id="main" tabindex="-1">
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
