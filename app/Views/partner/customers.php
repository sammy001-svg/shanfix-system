<?php
/**
 * The customers a partner introduced.
 *
 * Deliberately narrow: a name, when they became theirs, and what they
 * have earned. Not the invoices, not the line items, not the contact's
 * phone number. The customer is our client, not theirs.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Your customers</h1>
    <p class="portal-lede">
      Everyone you introduced. Every invoice they pay earns you commission —
      the first job and every one after it.
    </p>
    <p>
      <a class="btn btn--primary" href="<?= url('/partners/clients/new') ?>">
        <?= icon('user-plus') ?> Register a customer
      </a>
    </p>
  </div>

  <?php if (!$rows): ?>
    <div class="portal-card portal-empty">
      <span class="portal-empty__icon"><?= icon('users') ?></span>
      <div class="portal-empty__title">Nobody yet</div>
      <p class="text-sm text-muted mb-0">
        <a href="<?= url('/partners/clients/new') ?>">Register one</a> and
        they are yours from that moment — or
        <a href="<?= url('/partners/refer') ?>">introduce someone</a> and we
        will take it from there.
      </p>
    </div>
  <?php else: ?>
    <div class="portal-card portal-card--flush">
      <ul class="portal-list">
        <?php foreach ($rows as $c): ?>
          <li>
            <a class="portal-list__row" href="<?= url('/partners/customers/' . (int) $c['id']) ?>">
              <span class="portal-list__main">
                <span class="portal-list__title"><?= e($c['name']) ?></span>
                <span class="portal-list__meta">
                  Yours since <?= e(fdate($c['partner_linked_at'])) ?>
                  <?php if ($c['city']): ?> &middot; <?= e($c['city']) ?><?php endif; ?>
                  &middot; <?= (int) $c['invoices'] ?> paid invoice<?= (int) $c['invoices'] === 1 ? '' : 's' ?>
                </span>
              </span>
              <span class="portal-list__side">
                <span class="portal-list__figures">
                  <span class="portal-list__amount"><?= e(money($c['earned'], false)) ?></span>
                  <span class="partner-cut">earned from them</span>
                </span>
                <?php if ($c['status'] !== 'active'): ?>
                  <span class="badge badge--grey"><?= e(label_of((string) $c['status'])) ?></span>
                <?php endif; ?>
              </span>
              <span class="portal-list__chev"><?= icon('chevron-right') ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
