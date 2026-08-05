<?php
require_once APP_PATH . '/Views/partials/icons.php';

$isInvoice   = $type === 'invoice';
$isQuotation = $type === 'quotation';
$balance     = (float) $doc['balance'];
$paid        = (float) $doc['amount_paid'];
$total       = (float) $doc['total'];

$overdue = $isInvoice
    && $doc['due_date']
    && strtotime($doc['due_date']) < strtotime('today')
    && $balance > 0.009
    && !in_array($doc['status'], ['cancelled', 'paid', 'draft'], true);

$paidPct = $total > 0 ? min(100, ($paid / $total) * 100) : 0;
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url($meta['path']) ?>"><?= e($meta['plural']) ?></a> <span>/</span> <?= e($doc['doc_number']) ?>
    </div>
    <h1>
      <?= e($doc['doc_number']) ?>
      <span class="badge <?= status_badge($overdue ? 'overdue' : $doc['status']) ?>" style="vertical-align:middle;margin-left:6px">
        <?= e(label_of($overdue ? 'overdue' : $doc['status'])) ?>
      </span>
    </h1>
    <div class="page-head__sub">
      <a href="<?= url('/clients/' . $doc['client_id']) ?>"><?= e($doc['client_name']) ?></a>
      · Issued <?= e(fdate($doc['issue_date'])) ?>
      <?= $doc['title'] ? ' · ' . e($doc['title']) : '' ?>
    </div>
  </div>

  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url($meta['path'] . '/' . $doc['id'] . '/print') ?>"
       target="_blank" rel="noopener">
      <?= icon('printer') ?> Print / PDF
    </a>

    <?php if (can('documents.manage')): ?>
      <?php if ($isQuotation && !in_array($doc['status'], ['cancelled', 'rejected'], true)): ?>
        <form method="post" action="<?= url('/quotations/' . $doc['id'] . '/convert') ?>" style="display:inline"
              data-confirm="Convert <?= e($doc['doc_number']) ?> into an invoice?">
          <?= csrf_field() ?>
          <button class="btn btn--primary" type="submit"><?= icon('arrow-right') ?> Convert to invoice</button>
        </form>
      <?php endif; ?>

      <?php if ($isInvoice && $paid > 0): ?>
        <form method="post" action="<?= url('/invoices/' . $doc['id'] . '/receipt') ?>" style="display:inline">
          <?= csrf_field() ?>
          <button class="btn btn--primary" type="submit"><?= icon('check-circle') ?> Issue receipt</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (can('jobs.manage') && $type !== 'receipt' && $doc['status'] !== 'cancelled'): ?>
      <?php if ($job): ?>
        <a class="btn btn--outline" href="<?= url('/jobs/' . $job['id']) ?>">
          <?= icon('printer') ?> <?= e($job['job_number']) ?>
        </a>
      <?php else: ?>
        <form method="post" action="<?= url('/documents/' . $doc['id'] . '/job') ?>" style="display:inline">
          <?= csrf_field() ?>
          <button class="btn btn--outline" type="submit"><?= icon('printer') ?> Raise job card</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <div class="dropdown">
      <button class="btn btn--outline" type="button" data-dropdown><?= icon('more') ?></button>
      <div class="dropdown__menu">
        <?php if (can('documents.manage') && $type !== 'receipt' && $doc['status'] !== 'paid'): ?>
          <a class="dropdown__item" href="<?= url($meta['path'] . '/' . $doc['id'] . '/edit') ?>">
            <?= icon('edit') ?> Edit document
          </a>
        <?php endif; ?>

        <?php if (can('documents.manage') && $type !== 'receipt'): ?>
          <form method="post" action="<?= url($meta['path'] . '/' . $doc['id'] . '/duplicate') ?>">
            <?= csrf_field() ?>
            <button class="dropdown__item" type="submit"><?= icon('copy') ?> Duplicate</button>
          </form>
        <?php endif; ?>

        <?php if ($doc['client_email']): ?>
          <a class="dropdown__item"
             href="mailto:<?= e($doc['client_email']) ?>?subject=<?= e($meta['label'] . ' ' . $doc['doc_number']) ?>">
            <?= icon('mail') ?> Email client
          </a>
        <?php endif; ?>

        <?php if (can('documents.delete')): ?>
          <div class="dropdown__divider"></div>
          <form method="post" action="<?= url($meta['path'] . '/' . $doc['id'] . '/delete') ?>"
                data-confirm="Delete <?= e($doc['doc_number']) ?>? Documents with payments are cancelled instead.">
            <?= csrf_field() ?>
            <button class="dropdown__item dropdown__item--danger" type="submit"><?= icon('trash') ?> Delete</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($overdue): ?>
  <div class="alert alert--error">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <strong>Overdue by <?= (int) floor((time() - strtotime($doc['due_date'])) / 86400) ?> day(s).</strong>
      <?= e(money($balance)) ?> is still outstanding — payment was due <?= e(fdate($doc['due_date'])) ?>.
    </div>
  </div>
<?php endif; ?>

<?php if ($pendingStk): ?>
  <div class="card">
    <div id="stk-poll"
         data-stk-id="<?= (int) $pendingStk['id'] ?>"
         data-poll-url="<?= url('/payments/stk/status') ?>">
      <div class="stk-status stk-status--pending">
        <div class="stk-status__icon"><div class="spinner"></div></div>
        <div class="stk-status__title">Waiting for the client to pay</div>
        <div class="stk-status__text">
          An M-Pesa prompt for <?= e(money($pendingStk['amount'])) ?> was sent to
          <?= e($pendingStk['phone']) ?>. This page updates automatically once the payment is confirmed.
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Line items</div>
          <div class="card__sub"><?= count($items) ?> line(s)</div>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:32px">#</th>
              <th>Description</th>
              <th class="num">Qty</th>
              <th class="num">Unit price</th>
              <th class="num">Line total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $i => $item): ?>
              <tr>
                <td class="text-muted"><?= $i + 1 ?></td>
                <td>
                  <div class="table__primary"><?= nl2br(e($item['description'])) ?></div>
                  <?php if ($item['item_type'] !== 'custom'): ?>
                    <div class="table__muted"><?= e(label_of($item['item_type'])) ?> item</div>
                  <?php endif; ?>
                </td>
                <td class="num"><?= e(qty($item['quantity'])) ?> <span class="text-xs text-muted"><?= e($item['unit']) ?></span></td>
                <td class="num"><?= e(money($item['unit_price'], false)) ?></td>
                <td class="num fw-600"><?= e(money($item['line_total'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card__foot" style="display:block">
        <div class="totals">
          <div class="totals__row">
            <span class="totals__label">Subtotal</span>
            <span class="totals__value"><?= e(money($doc['subtotal'])) ?></span>
          </div>

          <?php if ((float) $doc['discount_amount'] > 0): ?>
            <div class="totals__row">
              <span class="totals__label">
                Discount
                <?= $doc['discount_type'] === 'percent' ? '(' . qty($doc['discount_value']) . '%)' : '' ?>
              </span>
              <span class="totals__value">− <?= e(money($doc['discount_amount'])) ?></span>
            </div>
          <?php endif; ?>

          <?php if ($doc['vat_mode'] !== 'exempt'): ?>
            <div class="totals__row">
              <span class="totals__label">
                VAT (<?= e(qty($doc['vat_rate'])) ?>%)<?= $doc['vat_mode'] === 'inclusive' ? ' — included' : '' ?>
              </span>
              <span class="totals__value"><?= e(money($doc['vat_amount'])) ?></span>
            </div>
          <?php else: ?>
            <div class="totals__row">
              <span class="totals__label">VAT</span>
              <span class="totals__value text-muted">Exempt</span>
            </div>
          <?php endif; ?>

          <div class="totals__row totals__row--grand">
            <span class="totals__label">Total</span>
            <span class="totals__value"><?= e(money($doc['total'])) ?></span>
          </div>

          <?php if ($isInvoice): ?>
            <div class="totals__row totals__row--paid">
              <span class="totals__label">Amount paid</span>
              <span class="totals__value"><?= e(money($paid)) ?></span>
            </div>
            <div class="totals__row totals__row--due">
              <span class="totals__label">Balance due</span>
              <span class="totals__value"><?= e(money($balance)) ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($doc['notes'] || $doc['terms']): ?>
      <div class="card">
        <div class="card__body">
          <?php if ($doc['notes']): ?>
            <div class="text-xs uppercase fw-700 text-green mb-4">Notes</div>
            <p class="text-sm mb-16" style="white-space:pre-line"><?= e($doc['notes']) ?></p>
          <?php endif; ?>
          <?php if ($doc['terms']): ?>
            <div class="text-xs uppercase fw-700 text-green mb-4">Terms &amp; conditions</div>
            <p class="text-sm text-muted mb-0" style="white-space:pre-line"><?= e($doc['terms']) ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($payments): ?>
      <div class="card">
        <div class="card__head">
          <div class="card__title">Payments received</div>
          <div class="card__sub"><?= count($payments) ?> payment(s)</div>
        </div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead>
              <tr><th>Receipt no.</th><th>Date</th><th>Method</th><th>Reference</th>
                  <th class="num">Amount</th><th>Status</th><th>Recorded by</th></tr>
            </thead>
            <tbody>
              <?php foreach ($payments as $p): ?>
                <tr>
                  <td class="table__primary"><?= e($p['payment_number']) ?></td>
                  <td class="text-sm"><?= e(fdate($p['paid_at'] ?: $p['created_at'])) ?></td>
                  <td class="text-sm"><?= e(label_of($p['method'])) ?></td>
                  <td class="text-sm text-muted"><?= e($p['reference'] ?: '—') ?></td>
                  <td class="num fw-700 text-green"><?= e(money($p['amount'], false)) ?></td>
                  <td><span class="badge <?= status_badge($p['status']) ?>"><?= e(label_of($p['status'])) ?></span></td>
                  <td class="text-sm"><?= e($p['recorded_by_name'] ?: 'System') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <aside>
    <?php if ($isInvoice && $balance > 0.009 && !in_array($doc['status'], ['cancelled', 'draft'], true)): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('dollar') ?>
          <div class="card__title">Collect payment</div>
        </div>
        <div class="card__body">
          <div class="mb-12">
            <div class="flex justify-between text-sm mb-4">
              <span class="text-muted">Paid</span>
              <span class="fw-600"><?= number_format($paidPct, 0) ?>%</span>
            </div>
            <div class="progress"><div class="progress__bar" style="width:<?= number_format($paidPct, 2) ?>%"></div></div>
          </div>

          <?php if (can('payments.stk') && $stkEnabled && $doc['client_phone'] && !$pendingStk): ?>
            <form method="post" action="<?= url('/payments/stk') ?>" class="mb-12">
              <?= csrf_field() ?>
              <input type="hidden" name="client_id" value="<?= (int) $doc['client_id'] ?>">
              <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">

              <div class="field mb-8">
                <label class="label" for="stk_phone">M-Pesa number</label>
                <input class="input" id="stk_phone" name="phone" value="<?= e($doc['client_phone']) ?>" required>
              </div>

              <div class="field mb-12">
                <label class="label" for="stk_amount">Amount</label>
                <div class="input-group">
                  <span class="input-group__addon input-group__addon--pre"><?= e($doc['currency']) ?></span>
                  <input class="input" type="number" step="1" min="1" id="stk_amount" name="amount"
                         value="<?= (int) ceil($balance) ?>" required>
                </div>
              </div>

              <button class="btn btn--primary btn--block" type="submit">
                <?= icon('smartphone') ?> Send STK Push
              </button>
            </form>
          <?php elseif (can('payments.stk') && !$stkEnabled): ?>
            <div class="alert alert--info">
              <?= icon('info') ?>
              <div class="alert__body text-sm">
                Enable KopoKopo in
                <?php if (can('settings.manage')): ?>
                  <a href="<?= url('/settings?tab=payments') ?>">Settings</a>
                <?php else: ?>Settings<?php endif; ?>
                to send STK Push requests.
              </div>
            </div>
          <?php endif; ?>

          <?php if (can('payments.manage')): ?>
            <a class="btn btn--outline btn--block"
               href="<?= url('/payments/create?document_id=' . $doc['id']) ?>">
              <?= icon('plus') ?> Record payment manually
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><div class="card__title">Details</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Number</dt><dd><code><?= e($doc['doc_number']) ?></code></dd>
          <dt>Client</dt>
          <dd><a href="<?= url('/clients/' . $doc['client_id']) ?>"><?= e($doc['client_name']) ?></a></dd>
          <dt>Issued</dt><dd><?= e(fdate($doc['issue_date'])) ?></dd>
          <?php if ($isInvoice): ?>
            <dt>Due</dt><dd class="<?= $overdue ? 'text-red fw-600' : '' ?>"><?= e(fdate($doc['due_date'])) ?></dd>
          <?php elseif ($isQuotation): ?>
            <dt>Valid until</dt><dd><?= e(fdate($doc['valid_until'])) ?></dd>
          <?php endif; ?>
          <dt>VAT</dt>
          <dd>
            <?= $doc['vat_mode'] === 'exempt'
                ? 'Exempt'
                : e(qty($doc['vat_rate'])) . '% ' . e($doc['vat_mode']) ?>
          </dd>
          <dt>Created by</dt><dd><?= e($doc['created_by_name'] ?: 'System') ?></dd>
          <dt>Created</dt><dd><?= e(fdatetime($doc['created_at'])) ?></dd>
          <?php if ($doc['sent_at']): ?>
            <dt>Sent</dt><dd><?= e(fdatetime($doc['sent_at'])) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <?php if (can('documents.manage') && $type !== 'receipt'): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Change status</div></div>
        <div class="card__body">
          <form method="post" action="<?= url($meta['path'] . '/' . $doc['id'] . '/status') ?>">
            <?= csrf_field() ?>
            <div class="field mb-12">
              <select class="select" name="status">
                <?php foreach ($statuses as $s): ?>
                  <?php if ($isInvoice && in_array($s, ['paid', 'partial'], true)) continue; ?>
                  <option value="<?= e($s) ?>" <?= $doc['status'] === $s ? 'selected' : '' ?>>
                    <?= e(label_of($s)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn--navy btn--block" type="submit"><?= icon('check') ?> Update status</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($related): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Related documents</div></div>
        <div class="card__body--flush">
          <?php foreach ($related as $r):
              $path = $r['doc_type'] === 'quotation' ? '/quotations/' : ($r['doc_type'] === 'invoice' ? '/invoices/' : '/receipts/');
          ?>
            <a class="conv" href="<?= url($path . $r['id']) ?>">
              <span class="conv__hash"><?= icon($r['doc_type'] === 'quotation' ? 'file-text' : ($r['doc_type'] === 'invoice' ? 'receipt' : 'check-circle')) ?></span>
              <span class="conv__meta">
                <span class="conv__name"><?= e($r['doc_number']) ?></span>
                <span class="conv__preview"><?= e(label_of($r['doc_type'])) ?> · <?= e(fdate($r['issue_date'])) ?></span>
              </span>
              <span class="conv__right">
                <span class="badge <?= status_badge($r['status']) ?>"><?= e(label_of($r['status'])) ?></span>
                <span class="conv__time"><?= e(money_short($r['total'])) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>
