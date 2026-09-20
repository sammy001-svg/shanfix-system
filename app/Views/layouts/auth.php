<?php
/**
 * The sign-in shell, used by all four doors.
 *
 * A split page: the photograph on one side, the form on the other. It
 * used to be a card floating in the middle of a photograph that had been
 * dimmed almost to black to keep the words on top of it legible — so the
 * picture was both the background and the problem.
 *
 * Splitting it solves that properly. The photograph is a photograph,
 * bright enough to be worth having; every piece of text sits on solid
 * colour, so contrast is a property of the layout rather than something
 * bought by ruining the image.
 *
 * Staff, customers and partners arrive at different addresses and cannot
 * use each other's accounts, but they are signing in to the same company:
 * one shell, told apart by a labelled badge rather than built three times.
 *
 * Set $authKind to 'client', 'partner' or 'staff'; 'none' is a real
 * answer for the chooser, which is not a door.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$brand = \App\Core\Settings::company();
$kind  = $authKind ?? 'staff';

$kinds = [
    'staff'   => ['Staff sign-in',   'briefcase', 'login__kind--staff'],
    'client'  => ['Customer portal', 'user',      'login__kind--client'],
    'partner' => ['Partner portal',  'users',     'login__kind--partner'],
];

$badge   = $kinds[$kind] ?? null;
$isGuest = $kind !== 'staff';

// The photo is optional — the navy ground underneath stands on its own,
// so a missing file degrades to a plain dark panel rather than a broken
// one. Preference order: whatever was uploaded in Settings (no server
// access needed), then a file dropped into public/assets/img/ by hand.
$bg     = null;
$bgFile = null;

$uploaded = (string) setting('login_background', '');

if ($uploaded !== '' && is_file(STORAGE_PATH . '/' . $uploaded)) {
    // Timestamp in the URL so replacing the photo shows up at once rather
    // than after the week-long asset cache expires.
    $bgFile = STORAGE_PATH . '/' . $uploaded;
    $bg     = url('/brand/login-bg') . '?v=' . filemtime($bgFile);
} else {
    foreach (['jpg', 'jpeg', 'png', 'webp', 'svg'] as $ext) {
        if (is_file(PUBLIC_PATH . '/assets/img/login-bg.' . $ext)) {
            $bgFile = PUBLIC_PATH . '/assets/img/login-bg.' . $ext;
            $bg     = asset('img/login-bg.' . $ext);   // asset() adds its own ?v=
            break;
        }
    }
}

// Embed the photo in the page when inline mode is on. Generous limit:
// this is one image on one page, and it is the whole look of the screen.
$bg = inline_image($bgFile, 1_200_000) ?? $bg;

$logoFile = $brand['logo'] !== '' && is_file(STORAGE_PATH . '/' . $brand['logo'])
    ? STORAGE_PATH . '/' . $brand['logo']
    : null;

// /brand/logo rather than /files — nobody is signed in on this page.
$logoSrc = inline_image($logoFile) ?? url('/brand/logo');
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
<meta name="app-base" content="<?= e(base_path()) ?>">
<title><?= e($title ?? 'Sign in') ?> · <?= e($brand['name']) ?></title>
<?php // Applies the saved theme before anything paints — see layouts/app.php. ?>
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
<meta name="theme-color" content="#0C2B4A">
</head>
<body class="auth-body">

<div class="auth">

  <?php // ── The photograph, and what we say over it ───────────────────
        // Hidden from assistive technology: it is decoration plus a
        // repetition of what the form side already says. ?>
  <section class="auth__stage" <?= $bg !== null ? 'style="background-image:url(\'' . e($bg) . '\')"' : '' ?>>
    <div class="auth__scrim"></div>

    <div class="auth__stageInner">
      <div class="auth__lockup">
        <?php if ($brand['logo']): ?>
          <img class="auth__logo" src="<?= e($logoSrc) ?>" alt="<?= e($brand['name']) ?>">
        <?php else: ?>
          <span class="auth__mark">SF</span>
          <span class="auth__company"><?= e($brand['name']) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($brand['tagline']): ?>
        <p class="auth__tagline"><?= e($brand['tagline']) ?></p>
      <?php endif; ?>

      <?php // What is behind the door, for the person deciding whether
            // they are at the right one. Customers and partners only:
            // staff know what this is.
            //
            // Each line is checked against the setting that turns it on.
            // Promising a customer they can pay here, when payments are
            // switched off, is a broken promise made before they have
            // even signed in. ?>
      <?php if ($isGuest): ?>
        <ul class="auth__points">
          <li><?= icon('file-text') ?><span>Your quotations, invoices and statement</span></li>
          <?php if (\App\Core\Settings::bool('kopokopo_enabled')): ?>
            <li><?= icon('credit-card') ?><span>Pay an invoice by M-Pesa</span></li>
          <?php endif; ?>
          <?php if (\App\Core\Settings::bool('portal_uploads_enabled', true)): ?>
            <li><?= icon('paperclip') ?><span>Send us artwork for printing</span></li>
          <?php endif; ?>
          <?php if (\App\Core\Settings::bool('bulk_sms_enabled', true)): ?>
            <li><?= icon('message') ?><span>Send bulk SMS to your own customers</span></li>
          <?php endif; ?>
          <li><?= icon('repeat') ?><span>See what renews, and when</span></li>
        </ul>
      <?php endif; ?>
    </div>

    <?php // The way out, kept on the picture side where it cannot be
          // mistaken for part of the form. ?>
    <a class="auth__back" href="/">
      <?= icon('arrow-left') ?> <span>Back to <?= e($brand['name']) ?></span>
    </a>
  </section>

  <?php // ── The form side ─────────────────────────────────────────── ?>
  <main class="auth__panel" id="main" tabindex="-1">
    <div class="auth__form">

      <?php // On a narrow screen the picture side shrinks to a strip, so
            // the brand is repeated here where it can be read. ?>
      <div class="auth__smallbrand">
        <?php if ($brand['logo']): ?>
          <img src="<?= e($logoSrc) ?>" alt="<?= e($brand['name']) ?>">
        <?php else: ?>
          <span class="auth__mark auth__mark--sm">SF</span>
          <span><?= e($brand['name']) ?></span>
        <?php endif; ?>
      </div>

      <?php if ($badge !== null): ?>
        <p class="login__kind <?= e($badge[2]) ?>">
          <?= icon($badge[1]) ?> <?= e($badge[0]) ?>
        </p>
      <?php endif; ?>

      <?php foreach (($flashes ?? []) as $flash): ?>
        <div class="alert alert--<?= e($flash['type']) ?>">
          <?= icon(match ($flash['type']) {
              'success' => 'check-circle',
              'error'   => 'x-circle',
              'warning' => 'alert-triangle',
              default   => 'info',
          }) ?>
          <div class="alert__body"><?= e($flash['message']) ?></div>
        </div>
      <?php endforeach; ?>

      <?= $content ?>

      <footer class="auth__foot">
        <?php // Somebody who cannot get in needs a way to reach a person.
              // On the customer side especially, "contact your
              // administrator" means nothing — these are the details they
              // actually need. ?>
        <?php if ($brand['phone'] || $brand['email']): ?>
          <div class="auth__contact">
            <?php if ($brand['phone']): ?>
              <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $brand['phone'])) ?>">
                <?= icon('phone') ?><?= e($brand['phone']) ?>
              </a>
            <?php endif; ?>
            <?php if ($brand['email']): ?>
              <a href="mailto:<?= e($brand['email']) ?>">
                <?= icon('mail') ?><?= e($brand['email']) ?>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="auth__legal">
          &copy; <?= date('Y') ?> <?= e($brand['name']) ?>
          <span aria-hidden="true">·</span>
          <?= $isGuest ? 'Customer portal' : 'Business Management System' ?>
        </div>

        <?php // Repeated for small screens, where the picture side — and
              // the link on it — is only a strip. ?>
        <a class="auth__backsm" href="/"><?= icon('arrow-left') ?> Back to our website</a>
      </footer>
    </div>
  </main>
</div>

<?= js_tag() ?>
</body>
</html>
