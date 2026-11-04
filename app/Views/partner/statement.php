<?php
/**
 * One month's commission, as a statement.
 *
 * On the print layout and in the printed document's own dress, because it
 * is one: the terms say a partner is paid monthly against an invoice from
 * them, and this is the thing that invoice is written against. Reusing
 * doc-* rather than inventing a parallel set means it looks like every
 * other sheet of paper the business puts out, and stays looking like one
 * when somebody changes the house style.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$t      = strtotime($period . '-01');
$month  = $t ? date('F Y', $t) : $period;
$rateOf = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';
// Only a partner here needs a KRA PIN, and only they should be told that
// the payment is waiting on one. Telling a partner in Kampala the same
// thing asks them for a document they cannot get and will never have.
$noPin = \App\Services\Partners::isLocal((string) ($me['country'] ?? 'KE'))
      && trim((string) $me['kra_pin']) === '';
?>

<div class="print-bar no-print">
  <a class="btn btn--outline btn--sm" href="<?= url('/partners/earnings') ?>">
    <?= icon('arrow-left') ?> Back to earnings
  </a>
  <button class="btn btn--primary btn--sm" type="button" onclick="window.print()">
    <?= icon('printer') ?> Print or save as PDF
  </button>
  <span class="text-sm text-muted">
    Invoice us against this and quote <?= e($me['partner_code'] ?: $me['name']) ?>.
  </span>
</div>

<div class="doc-sheet">

  <header class="doc-head">
    <div>
      <?php if ($company['logo']): ?>
        <img class="doc-head__logo" src="<?= url('/brand/logo') ?>" alt="<?= e($company['name']) ?>">
      <?php endif; ?>
      <div class="doc-head__company"><?= e($company['name']) ?></div>
      <?php if ($company['tagline']): ?>
        <div class="doc-head__tag"><?= e($company['tagline']) ?></div>
      <?php endif; ?>
      <div class="doc-head__lines">
        <?php if ($company['address']): ?><?= e($company['address']) ?><br><?php endif; ?>
        <?php if ($company['phone']): ?><?= e($company['phone']) ?><br><?php endif; ?>
        <?php if ($company['email']): ?><?= e($company['email']) ?><br><?php endif; ?>
        <?php if ($company['kra_pin']): ?>PIN: <?= e($company['kra_pin']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-head__right">
      <div class="doc-head__type">Commission statement</div>
      <div class="doc-head__no"><?= e($month) ?></div>
      <div class="doc-head__dates">
        <?php if ($me['partner_code']): ?>
          <strong>Partner:</strong> <?= e($me['partner_code']) ?><br>
        <?php endif; ?>
        <strong>Issued:</strong> <?= e(fdate(date('Y-m-d'))) ?>
      </div>
    </div>
  </header>

  <section class="doc-parties">
    <div class="doc-party">
      <div class="doc-party__label">Earned by</div>
      <div class="doc-party__name"><?= e($me['company'] ?: $me['name']) ?></div>
      <div class="doc-party__lines">
        <?php if ($me['company']): ?>Attn: <?= e($me['name']) ?><br><?php endif; ?>
        <?= e($me['email']) ?><br>
        <?= e($me['phone']) ?>
        <?php if ($me['kra_pin']): ?><br>PIN: <?= e($me['kra_pin']) ?><?php endif; ?>
      </div>
    </div>

    <div class="doc-party">
      <div class="doc-party__label">For the month of</div>
      <div class="doc-party__name"><?= e($month) ?></div>
      <div class="doc-party__lines">
        Commission is earned when your customer pays us, so every line here
        is money already collected.
      </div>
    </div>
  </section>

  <table class="table doc-table">
    <thead>
      <tr>
        <th style="width:34px" class="doc-table__idx">#</th>
        <th style="width:104px">Earned</th>
        <th>Customer</th>
        <th style="width:140px">Invoice</th>
        <th style="width:120px" class="num">Value</th>
        <th style="width:62px" class="num">Rate</th>
        <th style="width:120px" class="num">Commission</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td class="doc-table__idx"><?= $i + 1 ?></td>
          <td class="text-xs"><?= e(fdate($r['created_at'])) ?></td>
          <td>
            <?= e($r['client_name']) ?>
            <?php if ($r['status'] === 'paid' && $r['payout_ref']): ?>
              <div class="doc-table__unit">Paid &middot; <?= e($r['payout_ref']) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-xs"><?= e($r['doc_number']) ?></td>
          <td class="num"><?= e(money($r['base_amount'], false)) ?></td>
          <td class="num"><?= e($rateOf((float) $r['rate'])) ?></td>
          <td class="num fw-600"><?= e(money($r['amount'], false)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="doc-totals">
    <div class="doc-totals__inner">
      <div class="doc-totals__row">
        <span>Earned in <?= e($month) ?></span>
        <span><?= e(money($total, false)) ?></span>
      </div>
      <?php if ($paid > 0.009): ?>
        <div class="doc-totals__row">
          <span>Already paid</span>
          <span>&minus; <?= e(money($paid, false)) ?></span>
        </div>
      <?php endif; ?>
      <div class="doc-totals__row doc-totals__row--grand">
        <span>Due to you</span>
        <span><?= e(money($due)) ?></span>
      </div>

      <?php // ---- What actually left, where that differs ---------------
            // Commission is earned gross, in our currency, because that
            // is what the customer paid. A partner abroad is paid in
            // theirs, and tax may have been deducted and remitted on
            // their behalf. Both are shown, and the figure they will see
            // on their own bank statement is the last one. ?>
      <?php if ($payout && ((float) $payout['withheld'] > 0.009
                            || $payout['currency'] !== $base)): ?>

        <?php if ((float) $payout['withheld'] > 0.009): ?>
          <div class="doc-totals__row">
            <span>
              Withholding tax
              (<?= e(rtrim(rtrim(number_format((float) $payout['withholding_rate'], 2), '0'), '.')) ?>%),
              paid to KRA on your behalf
            </span>
            <span>&minus; <?= e(money($payout['withheld'], false)) ?></span>
          </div>
          <div class="doc-totals__row">
            <span>Sent to you</span>
            <span><?= e(money($payout['net_amount'], false)) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($payout['currency'] !== $base): ?>
          <div class="doc-totals__row doc-totals__row--grand">
            <span>
              In <?= e($payout['currency']) ?>, at
              <?= e($base) ?>
              <?= e(rtrim(rtrim(number_format((float) $payout['fx_rate'], 6), '0'), '.')) ?>
              per <?= e($payout['currency']) ?>
            </span>
            <span>
              <?= e($payout['currency']) ?>
              <?= e(number_format((float) $payout['net_fx'], 2)) ?>
            </span>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($payout && (float) $payout['withheld'] > 0.009): ?>
    <section class="doc-section">
      <div class="doc-section__label">About the deduction</div>
      <div class="doc-section__body">
        You have earned the full <?= e(money($total, false)) ?>. We are
        required to withhold
        <?= e(rtrim(rtrim(number_format((float) $payout['withholding_rate'], 2), '0'), '.')) ?>%
        of commission and pay it to KRA against your own tax account, so
        it is not money we have kept — it is money already paid on your
        behalf. Ask us for the withholding certificate when you file.
      </div>
    </section>
  <?php endif; ?>

  <?php if ($noPin): ?>
    <section class="doc-section">
      <div class="doc-section__label">Before we can pay this</div>
      <div class="doc-section__body">
        We do not have your KRA PIN. Commission carries on adding up either
        way — it is the payment that waits, not the earning. You can add it
        under Your details in the partner portal.
      </div>
    </section>
  <?php endif; ?>

  <footer class="doc-foot">
    We pay monthly against an invoice from you. Quote
    <strong><?= e($me['partner_code'] ?: $me['name']) ?></strong> and
    <strong><?= e($month) ?></strong> on it, and send it to
    <?= e($company['email']) ?>.
  </footer>
</div>
