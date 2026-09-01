<?php
/**
 * The customer door. Everything around this — brand, photograph, contact
 * details — comes from layouts/auth.php, the same shell the staff sign-in
 * uses, so a client who has been sent a link sees the company they expect.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<h1 class="login__title">Sign in</h1>
<p class="login__intro">
  <?php // Same rule as the list beside it: only say we take payment here
        // if we actually do. ?>
  Your quotations, invoices and statements<?php
    if (\App\Core\Settings::bool('kopokopo_enabled')):
  ?> — and you can pay by M-Pesa here<?php endif; ?>.
</p>

<form method="post" action="<?= url('/portal/login') ?>">
  <?= csrf_field() ?>

  <div class="field">
    <label class="label" for="email">Email address</label>
    <div class="input-icon">
      <?= icon('mail') ?>
      <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
             type="email" id="email" name="email"
             value="<?= old('email') ?>"
             autocomplete="email" inputmode="email"
             required autofocus
             placeholder="The address we invoice you at">
    </div>
    <?= error_for($errors ?? [], 'email') ?>
  </div>

  <div class="field">
    <label class="label" for="password">Password</label>
    <div class="input-icon">
      <?= icon('lock') ?>
      <input class="input <?= isset($errors['password']) ? 'has-error' : '' ?>"
             type="password" id="password" name="password"
             required autocomplete="current-password"
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

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Sign in
    <?= icon('arrow-right') ?>
  </button>
</form>

<?php // The same route serves a first-time set-up and a forgotten
      // password, because both end in "send me a code". Saying so here
      // saves the client working out which of the two they are. ?>
<div class="login__switch">
  <div class="login__switch-line"><span>First time, or forgotten your password?</span></div>

  <a class="btn btn--outline btn--block" href="<?= url('/portal/start') ?>">
    <?= icon('key') ?> Set up or reset my access
  </a>

  <p class="login__switch-help">
    <?php if ($selfSignup): ?>
      Whether you are already a customer or brand new, start with your email
      address and we will send you a code.
    <?php else: ?>
      Start with the email address we have for you and we will send you a code.
    <?php endif; ?>
    Not sure we have it?
    <a href="<?= url('/portal/request-access') ?>">Ask us to set it up</a>.
  </p>
</div>

<?php // Somebody who works here and followed the wrong link would
      // otherwise be stuck, since their staff account will not sign in
      // on this side. ?>
<p class="login__help">
  Work at <?= e($company['name']) ?>?
  <a href="<?= url('/login') ?>">Staff sign-in is here</a>.
</p>
