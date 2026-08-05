<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>
<div class="card" style="max-width:520px;margin:60px auto">
  <div class="card__body">
    <div class="empty" style="padding:24px 8px">
      <div class="empty__icon" style="background:var(--red-100);color:var(--red-700)">
        <?= icon('alert-triangle') ?>
      </div>
      <div class="empty__title" style="font-size:19px">Error <?= e($status) ?></div>
      <p class="empty__text"><?= e($message) ?></p>
      <div class="flex gap-8" style="justify-content:center">
        <a class="btn btn--outline" href="javascript:history.back()">Go back</a>
        <a class="btn btn--primary" href="<?= url('/dashboard') ?>">Dashboard</a>
      </div>
    </div>
  </div>
</div>
