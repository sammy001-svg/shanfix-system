<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>
<div class="auth">

  <div class="auth__aside">
    <div class="auth__brand">
      <span class="sidebar__mark">SF</span>
      <span>
        <span class="sidebar__name" style="font-size:16px">Shanfix Technology</span>
        <span class="sidebar__sub">BUSINESS MANAGEMENT SYSTEM</span>
      </span>
    </div>

    <div>
      <h2>Run the whole business<br>from one place.</h2>
      <p>Printing, branding, software and web projects — quoted, invoiced, paid and tracked without leaving the system.</p>

      <ul class="auth__points">
        <li><?= icon('check') ?> <span>Quotations, invoices and receipts with automatic VAT</span></li>
        <li><?= icon('check') ?> <span>M-Pesa STK Push collections through KopoKopo</span></li>
        <li><?= icon('check') ?> <span>Lead pipeline with follow-up reminders and activity logs</span></li>
        <li><?= icon('check') ?> <span>Live inventory, service rate card and expense tracking</span></li>
      </ul>
    </div>

    <div class="auth__foot">
      &copy; <?= date('Y') ?> Shanfix Technology. All rights reserved.
    </div>
  </div>

  <div class="auth__panel">
    <form class="auth__form" method="post" action="<?= url('/login') ?>">
      <?= csrf_field() ?>

      <h1>Sign in</h1>
      <p>Enter your work email and password to continue.</p>

      <?php foreach (($flashes ?? []) as $flash): ?>
        <div class="alert alert--<?= e($flash['type']) ?>">
          <?= icon($flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'error' ? 'x-circle' : 'info')) ?>
          <div class="alert__body"><?= e($flash['message']) ?></div>
        </div>
      <?php endforeach; ?>

      <div class="field">
        <label class="label" for="email">Email address</label>
        <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
               type="email" id="email" name="email"
               value="<?= old('email') ?>"
               autocomplete="username" required autofocus
               placeholder="you@shanfix.co.ke">
        <?= error_for($errors ?? [], 'email') ?>
      </div>

      <div class="field">
        <label class="label" for="password">Password</label>
        <input class="input <?= isset($errors['password']) ? 'has-error' : '' ?>"
               type="password" id="password" name="password"
               autocomplete="current-password" required
               placeholder="••••••••">
        <?= error_for($errors ?? [], 'password') ?>
      </div>

      <button class="btn btn--primary btn--block btn--lg mt-16" type="submit">
        <?= icon('log-out') ?> Sign in
      </button>

      <p class="text-xs text-muted text-center mt-24">
        Trouble signing in? Contact your system administrator.
      </p>
    </form>
  </div>
</div>
