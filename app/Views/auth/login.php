<?php
/**
 * The staff door. The brand, the photograph and the footer all come from
 * layouts/auth.php, which the customer portal uses too — this file is
 * only the part that differs.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$rememberOn   = \App\Core\Settings::bool('remember_me_enabled', true);
$rememberDays = \App\Core\Settings::int('remember_me_days', 30);
$portalOn     = \App\Core\Settings::bool('portal_enabled', true);
?>

<h1 class="login__title">Sign in</h1>
<p class="login__intro">Welcome back. Enter your details to continue.</p>

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

<?php // A customer arriving here has no account that will work in it and
      // no way to know that, so the way to their own is said plainly
      // rather than left to be guessed at or asked about on the phone. ?>
<?php if ($portalOn): ?>
  <div class="login__switch">
    <div class="login__switch-line"><span>Are you a customer?</span></div>

    <a class="btn btn--outline btn--block" href="<?= url('/portal/login') ?>">
      <?= icon('users') ?> Go to the customer portal
    </a>

    <p class="login__switch-help">
      See your quotations, invoices and statements, pay by M-Pesa and send
      us artwork.
      <a href="<?= url('/portal/start') ?>">Set up your access</a>
      if this is your first time.
    </p>
  </div>
<?php endif; ?>
