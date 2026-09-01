<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<h1 class="login__title">Set up your access</h1>
<p class="login__intro">
  Give us your email address and we will send a code to prove it is you.
  <?php if ($selfSignup): ?>
    It works whether you are already a customer or this is your first time.
  <?php endif; ?>
</p>

<form method="post" action="<?= url('/portal/start') ?>">
  <?= csrf_field() ?>

  <div class="field">
    <label class="label" for="email">Your email address</label>
    <div class="input-icon">
      <?= icon('mail') ?>
      <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
             type="email" id="email" name="email"
             value="<?= old('email') ?>" required
             autocomplete="email" inputmode="email" autofocus>
    </div>
    <?= error_for($errors ?? [], 'email') ?>
    <span class="field-hint">
      If you are already a customer, use the address we send your invoices to.
    </span>
  </div>

  <button class="btn btn--primary btn--block btn--lg login__submit" type="submit">
    Send me a code
    <?= icon('arrow-right') ?>
  </button>
</form>

<div class="login__switch">
  <div class="login__switch-line"><span>We do not have your email?</span></div>

  <a class="btn btn--outline btn--block" href="<?= url('/portal/request-access') ?>">
    <?= icon('phone') ?> Ask us using my phone number
  </a>

  <p class="login__switch-help">
    If we have only ever had your phone number, we will check our records
    and text you.
  </p>
</div>

<p class="login__help">
  <a href="<?= url('/portal/login') ?>"><?= icon('arrow-left') ?> Back to sign in</a>
</p>
