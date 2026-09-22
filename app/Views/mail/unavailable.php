<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text"><h1>Email</h1></div>
</div>

<div class="card">
  <div class="empty">
    <div class="empty__icon"><?= icon('alert-triangle') ?></div>
    <div class="empty__title">The mail server could not be reached</div>
    <p class="text-sm text-muted"><?= e($error) ?></p>
    <p class="text-sm text-muted">Your email is safe on the server. Try again in a moment.</p>
    <div class="mt-16">
      <a class="btn btn--primary" href="<?= e(url('/mail')) ?>">Try again</a>
      <a class="btn btn--ghost" href="<?= e(url('/mail/setup')) ?>">Mailbox settings</a>
    </div>
  </div>
</div>
