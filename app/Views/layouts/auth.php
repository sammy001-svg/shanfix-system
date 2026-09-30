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

// ── The welcome intro ───────────────────────────────────────────────
//
// Plays on a fresh arrival at any of the four doors, every time, and is
// skipped when the page is carrying something the person needs to read.
// Somebody whose password was refused, or who has just been signed out
// by a timeout, is being told something — holding that behind four
// seconds of animation is making them wait to find out what went wrong.
//
// A POST is never a fresh arrival: it is the form coming back, and the
// form coming back means it did not work.
$showIntro = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
             && empty($errors)
             && empty($flashes);

// What it says. The company name is spelled out letter by letter, so it
// wants to be a name and not a sentence; the tagline carries the rest.
$introName    = $brand['name'];
$introTagline = $brand['tagline'] !== ''
    ? $brand['tagline']
    : 'Welcome to ' . $brand['name'];

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
<?php // If the intro is going to run, the form underneath starts hidden
      // so it does not show through. Which means: with no JavaScript,
      // nothing would ever un-hide it. This undoes the whole arrangement
      // for a browser that cannot run the animation — a sign-in page is
      // not somewhere to find out that a script did not load. ?>
<?php if ($showIntro): ?>
  <noscript><style>
    .intro { display: none !important; }
    body.has-intro .login-stage { opacity: 1 !important; }
  </style></noscript>
<?php endif; ?>
</head>
<body class="auth-body <?= $showIntro ? 'has-intro' : '' ?>">

<?php if ($showIntro): ?>
  <?php // Hidden from assistive technology entirely. A screen reader
        // announces the page, and the page is the sign-in form; an
        // animation of the company name is decoration it should walk
        // straight past. The name and tagline are both said again in
        // the panel below. ?>
  <div class="intro" id="intro" aria-hidden="true">
    <div class="intro__field"></div>

    <h1 class="intro__name" id="introName" data-name="<?= e($introName) ?>"></h1>
    <div class="intro__rule"></div>
    <p class="intro__tagline"><?= e($introTagline) ?></p>
  </div>

  <?php // Outside the overlay and not hidden, because this one is for
        // using rather than for looking at. ?>
  <button type="button" class="intro__skip" id="introSkip">
    Skip <?= icon('chevron-right') ?>
  </button>
<?php endif; ?>

<?php // Keyboard users should not have to tab through a decorative
      // photograph and a brand panel to reach the form. ?>
<a class="skip-link" href="#main">Skip to the form</a>

<div class="login-stage" id="loginStage">
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
</div><?php // .login-stage ?>

<?= js_tag() ?>

<?php if ($showIntro): ?>
<?php // The sequence, matching the one on the Mascardi system: letters
      // rise out of a blur one after another, the rule draws itself
      // while the tagline settles, a light glints across the name, then
      // it hands over to the form.
      //
      // Inline because it runs once on one page and needs the overlay
      // to already be in the document; it carries the CSP nonce like
      // every other inline script here. ?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  'use strict';

  var intro = document.getElementById('intro');
  var nameEl = document.getElementById('introName');
  var stage = document.getElementById('loginStage');
  var skip  = document.getElementById('introSkip');

  if (!intro || !nameEl || !stage) { return; }

  var reduced = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var done = false;

  // Build the name one letter at a time, grouped by word. The grouping
  // is what stops a two-word name breaking through the middle of a
  // word when it is too wide for the screen.
  var letters = [];

  (nameEl.getAttribute('data-name') || '').split(/\s+/).forEach(function (word) {
    if (!word) { return; }

    var wrap = document.createElement('span');
    wrap.className = 'intro__word';

    word.split('').forEach(function (ch) {
      var span = document.createElement('span');
      span.textContent = ch;
      wrap.appendChild(span);
      letters.push(span);
    });

    nameEl.appendChild(wrap);
  });

  function reveal() {
    if (done) { return; }
    done = true;

    intro.classList.add('is-done');
    stage.classList.add('show');

    if (skip) { skip.style.display = 'none'; }

    // Put the cursor where the person was going anyway.
    setTimeout(function () {
      var first = stage.querySelector('input:not([type=hidden])');
      if (first) { try { first.focus(); } catch (e) {} }
    }, 500);

    // Out of the way once it has finished fading, so it can never
    // swallow a click.
    setTimeout(function () { intro.style.display = 'none'; }, 1100);
  }

  // Every way out of it. Somebody who has seen this before should not
  // have to hunt for the button.
  if (skip) {
    skip.addEventListener('click', function (e) { e.stopPropagation(); reveal(); });
  }
  intro.addEventListener('click', reveal);
  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.key === 'Enter' || e.key === ' ') { reveal(); }
  });

  // However badly the rest of this goes, the form appears. A sign-in
  // page held shut by a timer that did not fire is an office that
  // cannot work.
  setTimeout(reveal, 9000);

  if (reduced) {
    letters.forEach(function (span) { span.classList.add('is-in'); });
    intro.classList.add('is-open');
    setTimeout(reveal, 1400);
    return;
  }

  // 1. The letters rise and unblur, one after another.
  var step = 95;
  letters.forEach(function (span, i) {
    setTimeout(function () { span.classList.add('is-in'); }, 250 + i * step);
  });

  var settled = 250 + letters.length * step + 900;

  // 2. The rule draws itself and the tagline settles in.
  setTimeout(function () { intro.classList.add('is-open'); }, settled - 350);

  // 3. A light crosses the name.
  setTimeout(function () {
    letters.forEach(function (span, i) {
      setTimeout(function () {
        span.classList.add('is-glint');
        setTimeout(function () { span.classList.remove('is-glint'); }, 340);
      }, i * 55);
    });
  }, settled + 250);

  // 4. Hold, then hand over.
  setTimeout(reveal, settled + 2500);
}());
</script>
<?php endif; ?>
</body>
</html>
