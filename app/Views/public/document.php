<?php
require_once APP_PATH . '/Views/partials/icons.php';

$type      = $doc['doc_type'];
$isInvoice = $type === 'invoice';
$isReceipt = $type === 'receipt';
$isQuote   = $type === 'quotation';

$balance = (float) $doc['balance'];
$paid    = (float) $doc['amount_paid'];

$overdue = $isInvoice && $doc['due_date']
    && strtotime($doc['due_date']) < strtotime('today')
    && $balance > 0.009;

// The public route, not /files — whoever opens this share link has no session.
$logoPath = $company['logo'] ? url('brand/logo') : null;

$label = ucfirst($type);

// Can this be settled by M-Pesa from here? Decided by the controller so the
// page and the endpoint that acts on it can never disagree.
$canPay = \App\Controllers\PublicDocumentController::payable($doc);

// A prompt already on its way, from the request this visitor just made.
$watching = (int) \App\Core\Session::get('public_stk_id', 0);
?>

<div class="print-bar no-print" style="justify-content:space-between">
  <div class="flex items-center gap-8">
    <span class="sidebar__mark" style="width:30px;height:30px;flex-basis:30px;font-size:12px">SF</span>
    <span class="fw-600"><?= e($company['name']) ?></span>
  </div>
  <div class="flex gap-8">
    <button class="btn btn--outline btn--sm" type="button" data-print>
      <?= icon('printer') ?> Print
    </button>
    <button class="btn btn--primary btn--sm" type="button" data-print>
      <?= icon('download') ?> Save as PDF
    </button>
  </div>
</div>

<div class="print-bar no-print" style="margin-bottom:14px">
  <div class="alert alert--info mb-0" style="width:100%">
    <?= icon('info') ?>
    <div class="alert__body text-sm">
      To save a copy, choose <strong>Print</strong> and then select
      <strong>“Save as PDF”</strong> as the destination.
    </div>
  </div>
</div>

<?php if ($overdue): ?>
  <div class="print-bar no-print" style="margin-bottom:14px">
    <div class="alert alert--error mb-0" style="width:100%">
      <?= icon('alert-triangle') ?>
      <div class="alert__body">
        This invoice was due on <strong><?= e(fdate($doc['due_date'])) ?></strong>.
        <?= e(money($balance)) ?> remains outstanding.
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canPay): ?>
  <div class="paybox no-print">
    <div class="paybox__head">
      <span class="paybox__mark"><?= icon('smartphone') ?></span>
      <div class="paybox__intro">
        <div class="paybox__title">Pay by M-Pesa</div>
        <div class="paybox__sub">
          We will send a request to your phone. Enter your M-Pesa PIN to confirm.
        </div>
      </div>
      <div class="paybox__amount">
        <span class="paybox__amount-label">Amount due</span>
        <span class="paybox__amount-value"><?= e(money($balance)) ?></span>
      </div>
    </div>

    <?php if ($watching): ?>
      <?php
        // The same markup the staff page uses, so a single piece of polling
        // code serves both. It replaces itself with the outcome.
      ?>
      <div id="stk-poll"
           data-stk-id="<?= (int) $watching ?>"
           data-poll-url="<?= e(url('/view/' . $doc['public_token'] . '/pay/status')) ?>">
        <div class="stk-status stk-status--pending">
          <div class="stk-status__icon"><div class="spinner"></div></div>
          <div class="stk-status__title">Check your phone</div>
          <div class="stk-status__text">
            Enter your M-Pesa PIN to complete the payment. This page updates
            by itself once it goes through.
          </div>
        </div>
      </div>
    <?php else: ?>
      <form class="paybox__form" method="post"
            action="<?= e(url('/view/' . $doc['public_token'] . '/pay')) ?>">
        <?= csrf_field() ?>

        <div class="paybox__field">
          <label class="label" for="phone">M-Pesa number</label>
          <input class="input" type="tel" id="phone" name="phone" required
                 inputmode="numeric" autocomplete="tel"
                 value="<?= e($doc['client_phone'] ?? '') ?>"
                 placeholder="07XX XXX XXX">
        </div>

        <button class="btn btn--primary paybox__go" type="submit">
          <?= icon('smartphone') ?> Pay <?= e(money($balance, false)) ?>
        </button>
      </form>

      <div class="paybox__note">
        Paying from another number is fine — enter whichever phone holds the M-Pesa account.
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>


<?php if ($doc['doc_type'] === 'agreement'): ?>
  <?php if (!empty($doc['accepted_at'])): ?>
    <div class="print-bar no-print" style="margin-bottom:14px">
      <div class="alert alert--success mb-0" style="width:100%">
        <?= icon('check-circle') ?>
        <div class="alert__body">
          <strong>You accepted this agreement.</strong>
          <?= e($doc['accepted_name']) ?> on <?= e(fdate($doc['accepted_at'], 'd M Y \a\t H:i')) ?>.
          Keep a copy using <strong>Print</strong> above.
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="print-bar no-print" style="margin-bottom:14px">
      <div class="card mb-0" style="width:100%">
        <div class="card__body">
          <div class="text-xs uppercase fw-700 text-muted mb-8">Accept this agreement</div>
          <p class="text-sm text-muted">
            Please read the terms below. Typing your name and accepting has the
            same effect as signing it.
          </p>

          <form method="post" action="<?= url('view/' . $token . '/accept') ?>">
            <?= csrf_field() ?>

            <div class="field">
              <label class="label" for="accepted_name">Your full name</label>
              <input class="input" id="accepted_name" name="accepted_name"
                     maxlength="160" required
                     value="<?= e($doc['client_contact'] ?? '') ?>">
            </div>

            <label class="check">
              <input type="checkbox" name="confirm" value="1" required>
              <span class="check__text">
                <span>
                  I have read and agree to the terms of
                  <strong><?= e($doc['doc_number']) ?></strong>, on behalf of
                  <strong><?= e($doc['client_name']) ?></strong>.
                </span>
              </span>
            </label>

            <button class="btn btn--primary btn--block mt-12" type="submit">
              <?= icon('check') ?> Accept and proceed
            </button>
            <p class="field-hint mt-8 mb-0" style="text-align:center">
              The date, time and your network address are recorded with your acceptance.
            </p>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>
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
      <div class="doc-head__type"><?= e($label) ?></div>
      <div class="doc-head__no"><?= e($doc['doc_number']) ?></div>
      <div class="doc-head__dates">
        <strong>Date:</strong> <?= e(fdate($doc['issue_date'])) ?><br>
        <?php if ($isInvoice && $doc['due_date']): ?>
          <strong>Due:</strong>
          <span style="<?= $overdue ? 'color:var(--red-700);font-weight:700' : '' ?>">
            <?= e(fdate($doc['due_date'])) ?>
          </span><br>
        <?php endif; ?>
        <?php if ($isQuote && $doc['valid_until']): ?>
          <strong>Valid until:</strong> <?= e(fdate($doc['valid_until'])) ?><br>
        <?php endif; ?>
        <?php if ($isInvoice): ?>
          <strong>Status:</strong> <?= e(label_of($overdue ? 'overdue' : $doc['status'])) ?>
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
      <?php if ($isReceipt || ($isInvoice && $balance <= 0.009)): ?>
        <div style="margin-top:14px"><span class="doc-paid-stamp">Paid</span></div>
      <?php endif; ?>
    </div>
  </section>

  <?php // A proposal or agreement is mostly prose; it prints above the pricing. ?>
  <?php foreach (($sections ?? []) as $section): ?>
    <section class="doc-section doc-section--narrative">
      <div class="doc-section__label"><?= e($section['heading']) ?></div>
      <?php if (!empty($section['body'])): ?>
        <div class="doc-section__body"><?= e($section['body']) ?></div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

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
          <td><div class="doc-table__desc"><?= nl2br(e($item['description'])) ?></div></td>
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
        <span>Subtotal</span><span><?= e(money($doc['subtotal'])) ?></span>
      </div>

      <?php if ((float) $doc['discount_amount'] > 0): ?>
        <div class="doc-totals__row">
          <span>Discount<?= $doc['discount_type'] === 'percent' ? ' (' . qty($doc['discount_value']) . '%)' : '' ?></span>
          <span>&minus; <?= e(money($doc['discount_amount'])) ?></span>
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

  <?php if ($payments && ($isReceipt || $paid > 0)): ?>
    <section class="doc-section">
      <div class="doc-section__label">Payments received</div>
      <table class="doc-table" style="margin-top:6px">
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead>
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

  <?php if ($isInvoice && $balance > 0.009): ?>
    <?php
      // Built as a list rather than inline markup: PHP eats the newline that
      // follows a closing tag, which silently ran these lines together.
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
    <span style="font-size:10.5px"><?= e($doc['doc_number']) ?></span>
  </footer>
</div>

<div class="print-bar no-print" style="margin-top:16px;justify-content:center">
  <p class="text-xs text-muted text-center mb-0">
    Questions about this <?= e(strtolower($label)) ?>?
    <?php if ($company['phone']): ?>
      Call <a href="tel:<?= e($company['phone']) ?>"><?= e($company['phone']) ?></a>
    <?php endif; ?>
    <?php if ($company['email']): ?>
      or email <a href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a>
    <?php endif; ?>
  </p>
</div>
