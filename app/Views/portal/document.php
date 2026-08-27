<?php
require_once APP_PATH . '/Views/partials/icons.php';

$isInvoice = $type === 'invoice';
$balance   = (float) $doc['balance'];
$backTo    = $isInvoice ? '/portal/invoices' : '/portal/quotations';
?>

<div class="portal-wrap">
  <p class="text-sm mb-12">
    <a href="<?= url($backTo) ?>">&larr; <?= $isInvoice ? 'All invoices' : 'All quotations' ?></a>
  </p>

  <div class="portal-card">
    <div class="portal-doc__head">
      <div>
        <h1 class="portal-h1"><?= e($doc['doc_number']) ?></h1>
        <?php if ($doc['title']): ?>
          <p class="portal-lede mb-0"><?= e($doc['title']) ?></p>
        <?php endif; ?>
      </div>
      <div class="portal-doc__meta">
        <div><strong>Date:</strong> <?= e(fdate($doc['issue_date'])) ?></div>
        <?php if ($isInvoice && $doc['due_date']): ?>
          <div><strong>Due:</strong> <?= e(fdate($doc['due_date'])) ?></div>
        <?php endif; ?>
        <?php if (!$isInvoice && $doc['valid_until']): ?>
          <div><strong>Valid to:</strong> <?= e(fdate($doc['valid_until'])) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($items): ?>
      <div class="table-wrap mt-16">
        <table class="table">
          <thead>
            <tr>
              <th>Item</th>
              <th style="width:90px" class="num">Qty</th>
              <th style="width:120px" class="num">Each</th>
              <th style="width:130px" class="num">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td><?= nl2br(e($it['description'])) ?></td>
                <td class="num"><?= e(rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.')) ?><?= $it['unit'] ? ' ' . e($it['unit']) : '' ?></td>
                <td class="num"><?= e(money($it['unit_price'], false)) ?></td>
                <td class="num fw-600"><?= e(money($it['line_total'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php // Proposals and agreements carry prose rather than line items. ?>
    <?php if ($sections): ?>
      <div class="mt-16">
        <?php foreach ($sections as $sec): ?>
          <?php if ($sec['heading']): ?>
            <div class="fw-600 mt-16 mb-4"><?= e($sec['heading']) ?></div>
          <?php endif; ?>
          <p class="text-sm" style="white-space:pre-line"><?= e($sec['body']) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <dl class="portal-totals">
      <dt>Subtotal</dt><dd><?= e(money($doc['subtotal'], false)) ?></dd>

      <?php if ((float) $doc['discount_amount'] > 0): ?>
        <dt>Discount</dt><dd>&minus; <?= e(money($doc['discount_amount'], false)) ?></dd>
      <?php endif; ?>

      <?php if ($doc['vat_mode'] !== 'exempt' && (float) $doc['vat_amount'] > 0): ?>
        <dt>VAT (<?= e(rtrim(rtrim(number_format((float) $doc['vat_rate'], 2), '0'), '.')) ?>%)</dt>
        <dd><?= e(money($doc['vat_amount'], false)) ?></dd>
      <?php endif; ?>

      <dt class="portal-totals__grand">Total</dt>
      <dd class="portal-totals__grand"><?= e(money($doc['total'], false)) ?></dd>

      <?php if ($isInvoice): ?>
        <dt>Paid</dt><dd><?= e(money($doc['amount_paid'], false)) ?></dd>
        <dt class="<?= $balance > 0.009 ? 'text-red' : '' ?>">Outstanding</dt>
        <dd class="<?= $balance > 0.009 ? 'text-red fw-700' : '' ?>"><?= e(money($balance, false)) ?></dd>
      <?php endif; ?>
    </dl>
  </div>

  <?php if ($payments): ?>
    <div class="portal-card">
      <div class="fw-600 mb-8">What you have paid</div>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:120px">Date</th>
              <th>Method</th>
              <th>Reference</th>
              <th style="width:130px" class="num">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payments as $p): ?>
              <tr>
                <td class="text-xs text-muted"><?= e(fdate($p['paid_at'] ?: $p['created_at'])) ?></td>
                <td><?= e(label_of($p['method'])) ?></td>
                <td class="text-xs"><?= e($p['reference'] ?: '—') ?></td>
                <td class="num fw-600"><?= e(money($p['amount'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($doc['notes'] || $doc['terms']): ?>
    <div class="portal-card portal-card--quiet">
      <?php if ($doc['notes']): ?>
        <div class="fw-600 mb-4">Notes</div>
        <p class="text-sm" style="white-space:pre-line"><?= e($doc['notes']) ?></p>
      <?php endif; ?>
      <?php if ($doc['terms']): ?>
        <div class="fw-600 mb-4 mt-12">Terms</div>
        <p class="text-sm text-muted mb-0" style="white-space:pre-line"><?= e($doc['terms']) ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
