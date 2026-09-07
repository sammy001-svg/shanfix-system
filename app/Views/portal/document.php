<?php
/**
 * One document, as the client reads it.
 *
 * Ordered for the reason they opened it: what it is and what is owed, then
 * how to pay it, then the detail behind the figure. The payment panel used
 * to sit under the line items, which is the wrong way round for somebody
 * who arrived by pressing "Pay".
 */
require_once APP_PATH . '/Views/partials/icons.php';

$isInvoice = $type === 'invoice';
$balance   = (float) $doc['balance'];
$owing     = $isInvoice && $balance > 0.009;
$backTo    = $isInvoice ? '/portal/invoices' : '/portal/quotations';

$tone = static fn(string $s): string => match ($s) {
    'paid', 'accepted'                 => 'green',
    'overdue'                          => 'red',
    'partial'                          => 'amber',
    'rejected', 'expired', 'cancelled' => 'grey',
    default                            => 'navy',
};
?>

<div class="portal-wrap">
  <p class="portal-back">
    <a href="<?= url($backTo) ?>">
      <?= icon('arrow-left') ?> <?= $isInvoice ? 'All invoices' : 'All quotations' ?>
    </a>
  </p>

  <section class="portal-card portal-doc">
    <div class="portal-doc__id">
      <div class="portal-doc__kind">
        <?= $isInvoice ? 'Invoice' : 'Quotation' ?>
        <span class="badge badge--<?= e($tone((string) $doc['status'])) ?>">
          <?= e(label_of((string) $doc['status'])) ?>
        </span>
      </div>
      <h1 class="portal-doc__number"><?= e($doc['doc_number']) ?></h1>
      <?php if ($doc['title']): ?>
        <p class="portal-doc__title"><?= e($doc['title']) ?></p>
      <?php endif; ?>
      <div class="portal-doc__dates">
        Raised <?= e(fdate($doc['issue_date'])) ?>
        <?php if ($isInvoice && $doc['due_date']): ?>
          &middot; due <?= e(fdate($doc['due_date'])) ?>
        <?php elseif (!$isInvoice && $doc['valid_until']): ?>
          &middot; valid to <?= e(fdate($doc['valid_until'])) ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="portal-doc__figures">
      <div class="portal-doc__fig">
        <span class="portal-doc__fig-label">Total</span>
        <span class="portal-doc__fig-value"><?= e(money($doc['total'])) ?></span>
      </div>
      <?php if ($isInvoice): ?>
        <div class="portal-doc__fig <?= $owing ? 'portal-doc__fig--owing' : 'portal-doc__fig--clear' ?>">
          <span class="portal-doc__fig-label"><?= $owing ? 'Outstanding' : 'Settled' ?></span>
          <span class="portal-doc__fig-value">
            <?= $owing ? e(money($balance)) : e(money($doc['amount_paid'])) . ' paid' ?>
          </span>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php // The action, before the detail behind it. ?>
  <?php if (!empty($payable)): ?>
    <section class="portal-card portal-pay">
      <div class="portal-pay__head">
        <?= icon('smartphone') ?>
        <div>
          <div class="fw-600">Pay by M-Pesa</div>
          <div class="text-sm text-muted">
            We send a prompt to your phone. Enter your PIN and it is done.
          </div>
        </div>
        <div class="portal-pay__amount"><?= e(money($doc['balance'], false)) ?></div>
      </div>

      <form method="post" action="<?= url('/portal/invoices/' . $doc['id'] . '/pay') ?>"
            class="portal-pay__form">
        <?= csrf_field() ?>
        <div class="field mb-0 flex-1">
          <label class="label" for="phone">Your M-Pesa number</label>
          <input class="input" type="tel" id="phone" name="phone" required
                 value="<?= e($me['phone'] ?? '') ?>" placeholder="07XX XXX XXX"
                 inputmode="tel" autocomplete="tel">
        </div>
        <button class="btn btn--primary btn--lg" type="submit">
          Send me the prompt
        </button>
      </form>

      <?php // The amount is taken from the invoice, never from this form —
            // there is no field for it, and the server would ignore one. ?>
      <p class="text-xs text-muted mb-0">
        You will be asked for <?= e(money($doc['balance'], false)) ?>, the full
        outstanding amount on this invoice.
      </p>

      <div class="portal-pay__watch" data-pay-status="<?= url('/portal/invoices/' . $doc['id'] . '/pay/status') ?>"></div>
    </section>
  <?php elseif ($owing): ?>
    <?php // Owed, but not payable here — say why rather than leave them
          // looking for a button that is not going to appear. ?>
    <section class="portal-card portal-card--quiet">
      <div class="fw-600 mb-4">Paying this invoice</div>
      <p class="text-sm text-muted mb-0">
        Online payment is not available on this invoice. Call us on
        <?= e($company['phone']) ?> and we will take it from there.
      </p>
    </section>
  <?php endif; ?>

  <section class="portal-card portal-card--flush">
    <header class="portal-card__head">
      <h2 class="portal-card__title">What this is for</h2>
    </header>

    <div class="portal-doc__body">
      <?php if ($items): ?>
        <div class="table-wrap">
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
        <div class="portal-doc__prose">
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
          <dt class="<?= $owing ? 'text-red' : '' ?>">Outstanding</dt>
          <dd class="<?= $owing ? 'text-red fw-700' : '' ?>"><?= e(money($balance, false)) ?></dd>
        <?php endif; ?>
      </dl>
    </div>
  </section>

  <?php if ($payments): ?>
    <section class="portal-card portal-card--flush">
      <header class="portal-card__head">
        <h2 class="portal-card__title">What you have paid</h2>
      </header>
      <ul class="portal-list portal-list--tight">
        <?php foreach ($payments as $p): ?>
          <li>
            <span class="portal-list__row">
              <span class="portal-list__main">
                <span class="portal-list__title"><?= e(label_of((string) $p['method'])) ?></span>
                <span class="portal-list__meta">
                  <?= e(fdate($p['paid_at'] ?: $p['created_at'])) ?>
                  <?php if ($p['reference']): ?> &middot; <?= e($p['reference']) ?><?php endif; ?>
                </span>
              </span>
              <span class="portal-list__side">
                <span class="portal-list__amount"><?= e(money($p['amount'], false)) ?></span>
              </span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if ($doc['notes'] || $doc['terms']): ?>
    <section class="portal-card portal-card--quiet">
      <?php if ($doc['notes']): ?>
        <div class="fw-600 mb-4">Notes</div>
        <p class="text-sm" style="white-space:pre-line"><?= e($doc['notes']) ?></p>
      <?php endif; ?>
      <?php if ($doc['terms']): ?>
        <div class="fw-600 mb-4 mt-12">Terms</div>
        <p class="text-sm text-muted mb-0" style="white-space:pre-line"><?= e($doc['terms']) ?></p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <p class="portal-help">
    Something not right on this <?= $isInvoice ? 'invoice' : 'quotation' ?>? Call us on
    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $company['phone'])) ?>"><?= e($company['phone']) ?></a>.
  </p>
</div>
