<?php
require_once APP_PATH . '/Views/partials/icons.php';

$company   = setting('company_name', 'Shanfix Technology');
$tagline   = setting('company_tagline', 'Printing, Branding & Software Solutions');
$logo      = setting('company_logo', '');
$rememberOn = \App\Core\Settings::bool('remember_me_enabled', true);
$rememberDays = \App\Core\Settings::int('remember_me_days', 30);

// The photo is optional — the navy ground underneath stands on its own, so
// a missing file degrades to a plain dark page rather than a broken one.
//
// Preference order:
//   1. whatever was uploaded in Settings (no server access needed)
//   2. a file dropped into public/assets/img/ by hand
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

// Embed the photo in the page when inline mode is on. Generous limit: this
// is one image on one page, and it is the whole look of the sign-in screen.
$bg = inline_image($bgFile, 1_200_000) ?? $bg;

$logoFile   = $logo !== '' && is_file(STORAGE_PATH . '/' . $logo) ? STORAGE_PATH . '/' . $logo : null;
$logoSrc    = inline_image($logoFile) ?? url('/brand/logo');
?>

<div class="login">
  <?php if ($bg !== null): ?>
    <div class="login__bg" style="background-image:url('<?= e($bg) ?>')"></div>
  <?php endif; ?>
  <div class="login__veil"></div>

  <main class="login__panel">
    <div class="login__card">

      <?php if ($logo): ?>
        <?php
          // A wordmark already carries the company name, so repeating it beside
          // the image reads as a mistake. Logo alone, centred.
          // /brand/logo rather than /files — nobody is signed in on this page.
        ?>
        <header class="login__brand login__brand--logo">
          <img class="login__logo" src="<?= e($logoSrc) ?>" alt="<?= e($company) ?>">
          <?php if ($tagline): ?>
            <div class="login__tagline"><?= e($tagline) ?></div>
          <?php endif; ?>
        </header>
      <?php else: ?>
        <header class="login__brand">
          <span class="login__mark">SF</span>
          <div>
            <div class="login__company"><?= e($company) ?></div>
            <?php if ($tagline): ?>
              <div class="login__tagline"><?= e($tagline) ?></div>
            <?php endif; ?>
          </div>
        </header>
      <?php endif; ?>

      <h1 class="login__title">Sign in</h1>
      <p class="login__intro">Welcome back. Enter your details to continue.</p>

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

      <form method="post" action="<?= url('/login') ?>" id="login-form">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="email">Email address</label>
          <div class="input-icon">
            <?= icon('mail') ?>
            <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
                   type="email" id="email" name="email"
                   value="<?= old('email') ?>"
                   autocomplete="username"
                   inputmode="email"
                   required autofocus
                   placeholder="you@shanfix.co.ke">
          </div>
          <?= error_for($errors ?? [], 'email') ?>
        </div>

        <div class="field">
          <label class="label" for="password">Password</label>
          <div class="input-icon">
            <?= icon('lock') ?>
            <input class="input <?= isset($errors['password']) ? 'has-error' : '' ?>"
                   type="password" id="password" name="password"
                   autocomplete="current-password"
                   required
                   placeholder="Enter your password">
            <button class="input-icon__toggle" type="button"
                    data-toggle-password="#password"
                    aria-label="Show password" aria-pressed="false" tabindex="0">
              <span data-icon-show><?= icon('eye') ?></span>
              <span data-icon-hide hidden><?= icon('eye-off') ?></span>
            </button>
          </div>
          <?= error_for($errors ?? [], 'password') ?>
        </div>

        <?php if ($rememberOn): ?>
          <label class="check login__remember">
            <input type="checkbox" name="remember" value="1">
            <span class="check__text">
              <strong>Keep me signed in</strong>
              <span>Stay signed in on this device for <?= (int) $rememberDays ?> days.
                    Don't use this on a shared computer.</span>
            </span>
          </label>
        <?php endif; ?>

        <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
          Sign in
          <?= icon('arrow-right') ?>
        </button>
      </form>

      <p class="login__help">
        Trouble signing in? Contact your system administrator.
      </p>
    </div>

    <footer class="login__foot">
      &copy; <?= date('Y') ?> <?= e($company) ?>
      <span aria-hidden="true">·</span>
      Business Management System
    </footer>
  </main>
</div>
