<?php
require_once APP_PATH . '/Views/partials/icons.php';

$isInvoice = $type === 'invoice';
$isReceipt = $type === 'receipt';
$isQuote   = $type === 'quotation';

$balance = (float) $doc['balance'];
$paid    = (float) $doc['amount_paid'];

$logoPath = $company['logo'] ? url('files/' . $company['logo']) : null;
?>

<div class="print-bar no-print">
  <a class="btn btn--outline btn--sm" href="<?= url($meta['path'] . '/' . $doc['id']) ?>">
    <?= icon('arrow-left') ?> Back
  </a>
  <a class="btn btn--primary btn--sm" href="<?= url($meta['path'] . '/' . $doc['id'] . '/print?auto=1') ?>">
    <?= icon('printer') ?> Print
  </a>
  <span class="text-sm text-muted">
    Use your browser's “Save as PDF” option in the print dialog to produce a PDF.
  </span>
</div>

<div class="doc-sheet">

  <header class="doc-head">
    <div>
      <?php if ($logoPath): ?>
        <img class="doc-head__logo" src="<?= e($logoPath) ?>" alt="<?= e($company['name']) ?>">
      <?php endif; ?>
      <div class="doc-head__company"><?= e($company['name']) ?></div>
      <?php if ($company['tagline']): ?>
        <div class="doc-head__tag"><?= e($company['tagline']) ?></div>
      <?php endif; ?>
      <div class="doc-head__lines">
        <?php if ($company['address']): ?><?= e($company['address']) ?><br><?php endif; ?>
        <?php if ($company['phone']): ?><?= e($company['phone']) ?><br><?php endif; ?>
        <?php if ($company['email']): ?><?= e($company['email']) ?><br><?php endif; ?>
        <?php if ($company['website']): ?><?= e($company['website']) ?><br><?php endif; ?>
        <?php if ($company['kra_pin']): ?>PIN: <?= e($company['kra_pin']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-head__right">
      <div class="doc-head__type"><?= e($meta['label']) ?></div>
      <div class="doc-head__no"><?= e($doc['doc_number']) ?></div>
      <div class="doc-head__dates">
        <strong>Date:</strong> <?= e(fdate($doc['issue_date'])) ?><br>
        <?php if ($isInvoice && $doc['due_date']): ?>
          <strong>Due:</strong> <?= e(fdate($doc['due_date'])) ?><br>
        <?php endif; ?>
        <?php if ($isQuote && $doc['valid_until']): ?>
          <strong>Valid until:</strong> <?= e(fdate($doc['valid_until'])) ?><br>
        <?php endif; ?>
        <?php if ($isInvoice): ?>
          <strong>Status:</strong> <?= e(label_of($doc['status'])) ?>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <section class="doc-parties">
    <div class="doc-party">
      <div class="doc-party__label"><?= $isQuote ? 'Quotation for' : 'Bill to' ?></div>
      <div class="doc-party__name"><?= e($doc['client_name']) ?></div>
      <div class="doc-party__lines">
        <?php if ($doc['client_contact']): ?>Attn: <?= e($doc['client_contact']) ?><br><?php endif; ?>
        <?php if ($doc['client_address']): ?><?= e($doc['client_address']) ?><br><?php endif; ?>
        <?php if ($doc['client_city']): ?><?= e($doc['client_city']) ?><br><?php endif; ?>
        <?php if ($doc['client_phone']): ?><?= e($doc['client_phone']) ?><br><?php endif; ?>
        <?php if ($doc['client_email']): ?><?= e($doc['client_email']) ?><br><?php endif; ?>
        <?php if ($doc['client_kra_pin']): ?>PIN: <?= e($doc['client_kra_pin']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-party">
      <?php if ($doc['title']): ?>
        <div class="doc-party__label">Reference</div>
        <div class="doc-party__lines" style="font-weight:600;color:var(--ink)"><?= e($doc['title']) ?></div>
      <?php endif; ?>

      <?php if ($isReceipt): ?>
        <div style="margin-top:14px"><span class="doc-paid-stamp">Paid</span></div>
      <?php endif; ?>
    </div>
  </section>

  <table class="doc-table">
    <thead>
      <tr>
        <th class="doc-table__idx">#</th>
        <th>Description</th>
        <th class="num" style="width:82px">Qty</th>
        <th class="num" style="width:105px">Unit price</th>
        <th class="num" style="width:115px">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $item): ?>
        <tr>
          <td class="doc-table__idx"><?= $i + 1 ?></td>
          <td>
            <div class="doc-table__desc"><?= nl2br(e($item['description'])) ?></div>
          </td>
          <td class="num">
            <?= e(qty($item['quantity'])) ?>
            <?php if ($item['unit']): ?>
              <span class="doc-table__unit"><?= e($item['unit']) ?></span>
            <?php endif; ?>
          </td>
          <td class="num"><?= e(money($item['unit_price'], false)) ?></td>
          <td class="num"><?= e(money($item['line_total'], false)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="doc-totals">
    <div class="doc-totals__inner">
      <div class="doc-totals__row">
        <span>Subtotal</span>
        <span><?= e(money($doc['subtotal'])) ?></span>
      </div>

      <?php if ((float) $doc['discount_amount'] > 0): ?>
        <div class="doc-totals__row">
          <span>Discount<?= $doc['discount_type'] === 'percent' ? ' (' . qty($doc['discount_value']) . '%)' : '' ?></span>
          <span>− <?= e(money($doc['discount_amount'])) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($doc['vat_mode'] === 'exempt'): ?>
        <div class="doc-totals__row"><span>VAT</span><span>Exempt / zero-rated</span></div>
      <?php else: ?>
        <div class="doc-totals__row">
          <span>VAT @ <?= e(qty($doc['vat_rate'])) ?>%<?= $doc['vat_mode'] === 'inclusive' ? ' (incl.)' : '' ?></span>
          <span><?= e(money($doc['vat_amount'])) ?></span>
        </div>
      <?php endif; ?>

      <div class="doc-totals__row doc-totals__row--grand">
        <span><?= $isReceipt ? 'Total paid' : 'Total' ?></span>
        <span><?= e(money($doc['total'])) ?></span>
      </div>

      <?php if ($isInvoice && $paid > 0): ?>
        <div class="doc-totals__row doc-totals__row--paid">
          <span>Amount paid</span><span><?= e(money($paid)) ?></span>
        </div>
        <div class="doc-totals__row doc-totals__row--due">
          <span>Balance due</span><span><?= e(money($balance)) ?></span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isReceipt && $payments): ?>
    <section class="doc-section">
      <div class="doc-section__label">Payment details</div>
      <table class="doc-table" style="margin-top:6px">
        <thead>
          <tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= e(fdate($p['paid_at'] ?: $p['created_at'])) ?></td>
              <td><?= e(label_of($p['method'])) ?></td>
              <td><?= e($p['reference'] ?: '—') ?></td>
              <td class="num"><?= e(money($p['amount'], false)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>

  <?php if ($doc['notes']): ?>
    <section class="doc-section">
      <div class="doc-section__label">Notes</div>
      <div class="doc-section__body"><?= e($doc['notes']) ?></div>
    </section>
  <?php endif; ?>

  <?php if ($isInvoice && !$isReceipt): ?>
    <?php
      // Assembled as a list rather than inline markup: PHP swallows the newline
      // that follows a closing tag, which silently ran these lines together.
      $payLines = [];
      if (setting('mpesa_till')) {
          $payLines[] = 'M-Pesa Buy Goods Till: ' . setting('mpesa_till');
      }
      if (setting('bank_details')) {
          $payLines[] = (string) setting('bank_details');
      }
      $payLines[] = 'Please quote ' . $doc['doc_number'] . ' as your payment reference.';
    ?>
    <section class="doc-section">
      <div class="doc-section__label">How to pay</div>
      <div class="doc-section__body"><?= e(implode("\n", $payLines)) ?></div>
    </section>
  <?php endif; ?>

  <?php if ($doc['terms']): ?>
    <section class="doc-section">
      <div class="doc-section__label">Terms &amp; conditions</div>
      <div class="doc-section__body"><?= e($doc['terms']) ?></div>
    </section>
  <?php endif; ?>

  <?php if ($isQuote): ?>
    <div class="doc-sign">
      <div class="doc-sign__box">
        <div class="doc-sign__line"></div>
        <div class="doc-sign__label">For <?= e($company['name']) ?></div>
      </div>
      <div class="doc-sign__box">
        <div class="doc-sign__line"></div>
        <div class="doc-sign__label">Client acceptance &amp; date</div>
      </div>
    </div>
  <?php endif; ?>

  <footer class="doc-foot">
    <?php if ($isReceipt): ?>
      <strong>Thank you for your business.</strong><br>
    <?php elseif ($isQuote): ?>
      <strong>We look forward to working with you.</strong><br>
    <?php endif; ?>
    <?= e($company['name']) ?><?= $company['phone'] ? ' · ' . e($company['phone']) : '' ?><?= $company['email'] ? ' · ' . e($company['email']) : '' ?>
    <br>
    <span style="font-size:10.5px">
      This is a computer-generated document. <?= e($doc['doc_number']) ?> ·
      Generated <?= e(date('d M Y, H:i')) ?>
    </span>
  </footer>
</div>
