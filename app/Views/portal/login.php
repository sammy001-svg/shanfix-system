<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="portal-narrow">
  <div class="portal-card">
    <h1 class="portal-h1">Sign in</h1>
    <p class="portal-lede">
      See your quotations, invoices and statements, and pay by M-Pesa.
    </p>

    <form method="post" action="<?= url('/portal/login') ?>">
      <?= csrf_field() ?>

      <div class="field">
        <label class="label" for="email">Email address</label>
        <input class="input" type="email" id="email" name="email"
               value="<?= old('email') ?>" required autocomplete="email" autofocus>
      </div>

      <div class="field">
        <label class="label" for="password">Password</label>
        <input class="input" type="password" id="password" name="password"
               required autocomplete="current-password">
      </div>

      <button class="btn btn--primary btn--block btn--lg" type="submit">Sign in</button>
    </form>
  </div>

  <div class="portal-card portal-card--quiet">
    <div class="fw-600 mb-4">First time here?</div>
    <p class="text-sm text-muted">
      <?php if ($selfSignup): ?>
        Whether you are already a customer or brand new, start with your email
        address and we will send you a code.
      <?php else: ?>
        If you are already a customer, start with the email address we have for
        you and we will send you a code.
      <?php endif; ?>
    </p>
    <a class="btn btn--outline btn--block" href="<?= url('/portal/start') ?>">
      Set up my access
    </a>

    <p class="text-xs text-muted mt-12 mb-0">
      Not sure we have your email address?
      <a href="<?= url('/portal/request-access') ?>">Ask us to set it up</a>.
    </p>
  </div>
</div>
