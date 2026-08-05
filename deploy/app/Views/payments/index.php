<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Payments</h1>
    <div class="page-head__sub">Every shilling collected — M-Pesa, bank, cash and cheque.</div>
  </div>
  <div class="page-head__actions">
    <?php if (can('payments.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/payments/create') ?>"><?= icon('plus') ?> Record payment</a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--green">
    <div class="stat__label">Collected today</div>
    <div class="stat__value"><?= e(money_short($summary['collected_today'])) ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Collected this month</div>
    <div class="stat__value"><?= e(money_short($summary['collected_month'])) ?></div>
    <div class="stat__meta"><?= e(date('F Y')) ?></div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Collected all time</div>
    <div class="stat__value"><?= e(money_short($summary['collected_all'])) ?></div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Pending</div>
    <div class="stat__value"><?= (int) $summary['pending_count'] ?></div>
    <div class="stat__meta">Awaiting confirmation</div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/payments') ?>">
    <div class="field" style="min-width:230px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Receipt no., M-Pesa code or client" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="method">Method</label>
      <select class="select" id="method" name="method" data-auto-submit>
        <option value="">All methods</option>
        <?php foreach ($methods as $m): ?>
          <option value="<?= e($m) ?>" <?= $filters['method'] === $m ? 'selected' : '' ?>><?= e(label_of($m)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="select" id="status" name="status" data-auto-submit>
        <option value="">All</option>
        <?php foreach (['completed', 'pending', 'failed', 'cancelled'] as $s): ?>
          <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(label_of($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="from">From</label>
      <input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>" data-auto-submit>
    </div>

    <div class="field">
      <label class="label" for="to">To</label>
      <input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>" data-auto-submit>
    </div>

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/payments') ?>">Clear</a>
  </form>

  <?php if (!$payments): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('credit-card') ?></div>
      <div class="empty__title">No payments found</div>
      <p class="empty__text">Payments recorded manually or received through KopoKopo STK Push will be listed here.</p>
      <?php if (can('payments.manage')): ?>
        <a class="btn btn--primary" href="<?= url('/payments/create') ?>"><?= icon('plus') ?> Record payment</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Receipt no.</th><th>Date</th><th>Client</th><th>Invoice</th>
            <th>Method</th><th>Reference</th><th class="num">Amount</th>
            <th>Status</th><th class="actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td class="table__primary"><?= e($p['payment_number']) ?></td>
              <td class="text-sm"><?= e(fdate($p['paid_at'] ?: $p['created_at'])) ?></td>
              <td>
                <a href="<?= url('/clients/' . $p['client_id']) ?>"><?= e($p['client_name']) ?></a>
              </td>
              <td>
                <?php if ($p['doc_number']): ?>
                  <a href="<?= url('/invoices/' . $p['document_id']) ?>"><?= e($p['doc_number']) ?></a>
                <?php else: ?>
                  <span class="text-muted text-sm">On account</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= str_starts_with($p['method'], 'mpesa') ? 'badge--green' : 'badge--navy' ?>">
                  <?= e(label_of($p['method'])) ?>
                </span>
              </td>
              <td class="text-sm text-muted"><?= e($p['reference'] ?: '—') ?></td>
              <td class="num fw-700 <?= $p['status'] === 'completed' ? 'text-green' : 'text-muted' ?>">
                <?= e(money($p['amount'], false)) ?>
              </td>
              <td><span class="badge <?= status_badge($p['status']) ?>"><?= e(label_of($p['status'])) ?></span></td>
              <td class="actions">
                <?php if (can('payments.manage') && $p['status'] === 'completed'): ?>
                  <form method="post" action="<?= url('/payments/' . $p['id'] . '/reverse') ?>" style="display:inline"
                        data-confirm="Reverse <?= e($p['payment_number']) ?> (<?= e(money($p['amount'])) ?>)? The invoice balance will be restored.">
                    <?= csrf_field() ?>
                    <button class="btn btn--danger-soft btn--sm" type="submit" title="Reverse payment">
                      <?= icon('repeat') ?>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($payments) ?> of <?= number_format($pager['total']) ?> payment(s)</span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
