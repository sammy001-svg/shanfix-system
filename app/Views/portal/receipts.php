<?php
/**
 * Receipts list — every receipt issued against this client.
 *
 * A receipt is a document the system generates once a payment is
 * recorded. Showing them here means a client can always find proof of
 * what they paid without having to ring and ask.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">Your receipts</h1>
    <p class="portal-lede">
      A record of every payment we have acknowledged. Click any receipt to see the full details.
    </p>
  </div>

  <?php if (!$rows): ?>
    <div class="portal-card text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('credit-card') ?></div>
      <div class="card__title mt-8">No receipts yet</div>
      <p class="text-sm text-muted mb-0">
        When we record a payment from you, the receipt will appear here.
      </p>
    </div>
  <?php else: ?>
    <div class="portal-card portal-card--flush">
      <ul class="portal-list">
        <?php foreach ($rows as $r): ?>
          <li>
            <a class="portal-list__row" href="<?= url('/portal/receipts/' . (int) $r['id']) ?>">
              <span class="portal-list__main">
                <span class="portal-list__title">
                  <?= e($r['title'] ?: 'Receipt') ?>
                </span>
                <span class="portal-list__meta">
                  <?= e($r['doc_number']) ?> &middot; <?= e(fdate($r['issue_date'])) ?>
                </span>
              </span>
              <span class="portal-list__side">
                <span class="portal-list__amount"><?= e(money($r['total'], false)) ?></span>
                <span class="badge badge--green">Paid</span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <p class="portal-help">
    Missing a receipt? Call us on
    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $company['phone'])) ?>"><?= e($company['phone']) ?></a>
    or email <a href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a>.
  </p>
</div>
