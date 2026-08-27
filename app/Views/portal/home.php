<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Hello, <?= e(explode(' ', trim($me['name']))[0]) ?>.</h1>
    <p class="portal-lede">
      <?php if ($me['client_name']): ?>
        You are signed in to <strong><?= e($me['client_name']) ?></strong>.
      <?php else: ?>
        Your account is set up.
      <?php endif; ?>
    </p>
  </div>

  <div class="portal-grid">
    <div class="portal-tile">
      <div class="portal-tile__figure"><?= e($counts['quotations']) ?></div>
      <div class="portal-tile__label">Quotations</div>
    </div>
    <div class="portal-tile">
      <div class="portal-tile__figure"><?= e($counts['invoices']) ?></div>
      <div class="portal-tile__label">Invoices</div>
    </div>
    <div class="portal-tile <?= $counts['owing_raw'] > 0 ? 'portal-tile--owing' : '' ?>">
      <div class="portal-tile__figure"><?= e($counts['owing']) ?></div>
      <div class="portal-tile__label">Outstanding</div>
    </div>
  </div>

  <div class="portal-card mt-16">
    <div class="fw-600 mb-4">More is on the way</div>
    <p class="text-sm text-muted mb-0">
      Your quotations, invoices and statements, your recurring services and
      their renewal dates, our catalogue with prices, and paying by M-Pesa are
      all being added here. Signing in is the part that had to come first.
    </p>
  </div>
</div>
