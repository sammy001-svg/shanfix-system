<?php
/**
 * One of a partner's customers, and everything we have done for them.
 *
 * Read-only throughout. A partner introduces and earns; we quote, invoice
 * and collect. There is nothing on this page that raises a document or
 * records a payment, and there is no route behind it that would.
 *
 * The four figures at the top are the ones they ring up about: what we
 * have billed, what has come in, what is still owing, and what of it is
 * theirs.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$docTone = static function (array $doc): array {
    if ($doc['doc_type'] === 'quotation') {
        return match ((string) $doc['status']) {
            'accepted' => ['green', 'Accepted'],
            'rejected' => ['red',   'Turned down'],
            'expired'  => ['grey',  'Expired'],
            default    => ['blue',  'Sent'],
        };
    }

    return match ((string) $doc['status']) {
        'paid'      => ['green', 'Paid'],
        'partial'   => ['amber', 'Part paid'],
        'overdue'   => ['red',   'Overdue'],
        'cancelled' => ['grey',  'Cancelled'],
        default     => ['navy',  'Unpaid'],
    };
};
?>

<div class="portal-wrap">
  <p class="text-sm mb-12">
    <a href="<?= url('/partners/customers') ?>">&larr; Your customers</a>
  </p>

  <div class="portal-hello">
    <h1 class="portal-h1"><?= e($client['name']) ?></h1>
    <p class="portal-lede">
      <?php if ($client['contact_person']): ?>
        <?= e($client['contact_person']) ?> &middot;
      <?php endif; ?>
      <?php if ($client['city']): ?>
        <?= e($client['city']) ?> &middot;
      <?php endif; ?>
      Registered to you<?= $client['partner_linked_at']
        ? ' on ' . e(fdate($client['partner_linked_at'])) : '' ?>
    </p>
  </div>

  <div class="portal-stats">
    <div class="portal-stat">
      <span class="portal-stat__figure"><?= e(money($invoiced, false)) ?></span>
      <span class="portal-stat__label">Invoiced</span>
    </div>
    <div class="portal-stat">
      <span class="portal-stat__figure"><?= e(money($paid, false)) ?></span>
      <span class="portal-stat__label">They have paid</span>
    </div>
    <div class="portal-stat">
      <span class="portal-stat__figure"><?= e(money($owing, false)) ?></span>
      <span class="portal-stat__label">Still owing</span>
    </div>
    <div class="portal-stat">
      <span class="portal-stat__figure"><?= e(money($commission['total'], false)) ?></span>
      <span class="portal-stat__label">Your commission</span>
    </div>
  </div>

  <?php // The split matters more than the total: one figure is money on
        // its way to them, the other is money that has arrived. ?>
  <?php if ((float) $commission['total'] > 0.009): ?>
    <div class="portal-card mb-16">
      <p class="text-sm mb-0">
        Of the <strong><?= e(money($commission['total'], false)) ?></strong> this
        customer has earned you,
        <strong><?= e(money($commission['paid'], false)) ?></strong> has been paid
        out to you and
        <strong><?= e(money($commission['due'], false)) ?></strong> is still to
        come. Commission is earned when they pay us, and paid to you monthly.
      </p>
    </div>
  <?php endif; ?>

  <?php // What they told us this customer wanted. Kept in front of them
        // because it is the one thing on the page they wrote. ?>
  <?php if (!empty($briefs)): ?>
    <div class="portal-card mb-16">
      <h2 class="portal-card__title mb-12">What you told us they wanted</h2>
      <?php foreach ($briefs as $brief): ?>
        <p class="text-xs text-muted mb-4">
          <?= e($brief['lead_number']) ?> &middot; <?= e(fdate($brief['created_at'])) ?>
          &middot; <?= e(label_of((string) $brief['stage'])) ?>
        </p>
        <p class="text-sm" style="white-space:pre-line"><?= e($brief['requirement']) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php // ---- Quotations and invoices ------------------------------------
        // Drafts are left out on purpose: a quotation still being written
        // is not something a partner should be relaying to their customer,
        // and a figure that moves before it is sent only causes arguments. ?>
  <div class="portal-card portal-card--flush mb-16">
    <header class="portal-card__head">
      <h2 class="portal-card__title">Quotations and invoices</h2>
      <span class="text-xs text-muted">We raise these, not you</span>
    </header>

    <?php if (!$documents): ?>
      <div class="portal-empty">
        <div class="portal-empty__icon"><?= icon('file-text') ?></div>
        <div class="portal-empty__title">Nothing yet</div>
        <p class="portal-empty__note">
          As soon as we quote or invoice <?= e($client['name']) ?>, it will
          appear here and we will email you.
        </p>
      </div>
    <?php else: ?>
      <ul class="portal-list portal-list--tight">
        <?php foreach ($documents as $doc): ?>
          <?php [$tone, $label] = $docTone($doc); ?>
          <li>
            <div class="portal-list__row">
              <span class="portal-list__main">
                <span class="portal-list__title">
                  <?= $doc['doc_type'] === 'quotation' ? 'Quotation' : 'Invoice' ?>
                  <?= e($doc['doc_number']) ?>
                </span>
                <span class="portal-list__meta">
                  <?= e(fdate($doc['issue_date'])) ?>
                  <?php if ($doc['doc_type'] === 'invoice' && (float) $doc['balance'] > 0.009): ?>
                    &middot; <?= e(money($doc['balance'], false)) ?> still owing
                  <?php elseif ($doc['doc_type'] === 'invoice' && (float) $doc['amount_paid'] > 0.009): ?>
                    &middot; paid in full
                  <?php endif; ?>
                </span>
              </span>
              <span class="portal-list__side">
                <span class="portal-list__amount"><?= e(money($doc['total'], false)) ?></span>
                <span class="badge badge--<?= e($tone) ?>"><?= e($label) ?></span>
              </span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="alert alert--info">
    <?= icon('info') ?>
    <div class="alert__body">
      <strong>Anything to add or change?</strong>
      Quotations, invoices and payments are ours to raise, so tell your
      account manager and we will do it. You will see it here, and be
      emailed, as soon as it is done.
    </div>
  </div>
</div>
