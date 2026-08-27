<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="portal-narrow">
  <div class="portal-card">
    <h1 class="portal-h1">Set up your access</h1>
    <p class="portal-lede">
      Give us your email address and we will send a code to prove it is you.
      <?php if ($selfSignup): ?>
        It works whether you are already a customer or this is your first time.
      <?php endif; ?>
    </p>

    <form method="post" action="<?= url('/portal/start') ?>">
      <?= csrf_field() ?>

      <div class="field">
        <label class="label" for="email">Your email address</label>
        <input class="input" type="email" id="email" name="email"
               value="<?= old('email') ?>" required autocomplete="email" autofocus>
        <span class="field-hint">
          If you are already a customer, use the address we send your invoices to.
        </span>
      </div>

      <button class="btn btn--primary btn--block btn--lg" type="submit">Send me a code</button>
    </form>
  </div>

  <div class="portal-card portal-card--quiet">
    <div class="fw-600 mb-4">We do not have your email?</div>
    <p class="text-sm text-muted mb-0">
      If we have only ever had your phone number,
      <a href="<?= url('/portal/request-access') ?>">ask us to set your access up</a>.
      We will check our records and text you.
    </p>
  </div>

  <p class="text-xs text-muted text-center mt-16">
    <a href="<?= url('/portal/login') ?>">Back to sign in</a>
  </p>
</div>
