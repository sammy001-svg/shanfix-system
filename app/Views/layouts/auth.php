<?php
/**
 * The sign-in shell, used by both doors.
 *
 * Staff and customers arrive at different addresses and cannot use each
 * other's accounts, but they are signing in to the same company, and a
 * customer who has just been sent a link has no reason to trust a page
 * that looks nothing like the one the staff use. So both are dressed the
 * same — same brand, same photograph, same contact details underneath —
 * and told apart by a single labelled badge rather than by being built
 * twice.
 *
 * Set $authKind to 'client' for the portal side; anything else is staff.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$brand   = \App\Core\Settings::company();
$isGuest = ($authKind ?? 'staff') === 'client';

// The photo is optional — the navy ground underneath stands on its own,
// so a missing file degrades to a plain dark page rather than a broken
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
<body>

<div class="login">
  <?php if ($bg !== null): ?>
    <div class="login__bg" style="background-image:url('<?= e($bg) ?>')"></div>
  <?php endif; ?>
  <div class="login__veil"></div>

  <main class="login__panel">
    <?php // The brand sits outside the card and is placed by the grid: to
          // the left of the form on a wide screen, above it on a phone.
          // One copy in the markup, so it cannot drift between the two. ?>
    <div class="login__aside">
      <?php if ($brand['logo']): ?>
        <?php // A wordmark already carries the company name, so repeating
              // it beside the image reads as a mistake. Logo alone. ?>
        <img class="login__logo" src="<?= e($logoSrc) ?>" alt="<?= e($brand['name']) ?>">
      <?php else: ?>
        <div class="login__lockup">
          <span class="login__mark">SF</span>
          <span class="login__company"><?= e($brand['name']) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($brand['tagline']): ?>
        <p class="login__tagline"><?= e($brand['tagline']) ?></p>
      <?php endif; ?>

      <?php // What is behind the door, for the person deciding whether they
            // are at the right one. Only shown where there is room for it,
            // and only on the customer side: staff know what this is. ?>
      <?php if ($isGuest): ?>
        <ul class="login__points">
          <li><?= icon('file-text') ?><span>Your quotations, invoices and statement</span></li>
          <li><?= icon('credit-card') ?><span>Pay an invoice by M-Pesa</span></li>
          <li><?= icon('paperclip') ?><span>Send us artwork for printing</span></li>
          <li><?= icon('repeat') ?><span>See what renews, and when</span></li>
        </ul>
      <?php endif; ?>
    </div>

    <div class="login__card">

      <?php // Which door this is. Two accounts that look alike and do not
            // work in each other's page is the confusion worth heading off,
            // and one badge does it without a paragraph of explanation. ?>
      <p class="login__kind <?= $isGuest ? 'login__kind--client' : 'login__kind--staff' ?>">
        <?= $isGuest ? icon('user') : icon('briefcase') ?>
        <?= $isGuest ? 'Customer portal' : 'Staff sign-in' ?>
      </p>

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
    </div>

    <footer class="login__foot">
      <?php // Somebody who cannot get in needs a way to reach a person.
            // On the customer side especially, "contact your administrator"
            // means nothing — these are the details they actually need. ?>
      <?php if ($brand['phone'] || $brand['email']): ?>
        <div class="login__contact">
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

      <div>
        &copy; <?= date('Y') ?> <?= e($brand['name']) ?>
        <span aria-hidden="true">·</span>
        <?= $isGuest ? 'Customer portal' : 'Business Management System' ?>
      </div>
    </footer>
  </main>
</div>

<?= js_tag() ?>
</body>
</html>
