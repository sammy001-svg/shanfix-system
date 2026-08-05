<?php
require_once APP_PATH . '/Views/partials/icons.php';

$maxCategory = 0.0;
foreach ($byCategory as $c) {
    $maxCategory = max($maxCategory, (float) $c['total']);
}
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Expenses</h1>
    <div class="page-head__sub">What the business is spending — materials, rent, transport, subscriptions.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/expenses/export' . query_string()) ?>">
      <?= icon('download') ?> Export CSV
    </a>
    <?php if (can('expenses.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/expenses/create') ?>"><?= icon('plus') ?> Record expense</a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--red">
    <div class="stat__label">Selected period</div>
    <div class="stat__value"><?= e(money_short($summary['period_total'])) ?></div>
    <div class="stat__meta"><?= (int) $summary['period_count'] ?> expense(s)</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">This month</div>
    <div class="stat__value"><?= e(money_short($monthTotal)) ?></div>
    <div class="stat__meta"><?= e(date('F Y')) ?></div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Input VAT (period)</div>
    <div class="stat__value"><?= e(money_short($summary['period_vat'])) ?></div>
    <div class="stat__meta">Reclaimable on VAT returns</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Average expense</div>
    <div class="stat__value">
      <?= e(money_short((int) $summary['period_count'] > 0
          ? (float) $summary['period_total'] / (int) $summary['period_count'] : 0)) ?>
    </div>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <form class="filters" method="get" action="<?= url('/expenses') ?>">
        <div class="field" style="min-width:210px">
          <label class="label" for="q">Search</label>
          <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
                 placeholder="Description, vendor or reference" data-debounce-submit>
        </div>

        <div class="field">
          <label class="label" for="category">Category</label>
          <select class="select" id="category" name="category" data-auto-submit>
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= $filters['category'] === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?>
              </option>
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
        <a class="btn btn--ghost btn--sm" href="<?= url('/expenses') ?>">Reset</a>
      </form>

      <?php if (!$expenses): ?>
        <div class="empty">
          <div class="empty__icon"><?= icon('trending-down') ?></div>
          <div class="empty__title">No expenses in this period</div>
          <p class="empty__text">Record every business cost so your profit figures mean something.</p>
          <?php if (can('expenses.manage')): ?>
            <a class="btn btn--primary" href="<?= url('/expenses/create') ?>"><?= icon('plus') ?> Record expense</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>Number</th><th>Date</th><th>Description</th><th>Category</th>
                  <th>Method</th><th class="num">Amount</th><th class="actions"></th></tr>
            </thead>
            <tbody>
              <?php foreach ($expenses as $ex): ?>
                <tr>
                  <td class="table__primary"><?= e($ex['expense_number']) ?></td>
                  <td class="text-sm"><?= e(fdate($ex['expense_date'])) ?></td>
                  <td>
                    <div><?= e(str_excerpt($ex['description'], 56)) ?></div>
                    <?php if ($ex['vendor']): ?>
                      <div class="table__muted"><?= e($ex['vendor']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-sm text-muted"><?= e($ex['category_name'] ?: '—') ?></td>
                  <td class="text-sm"><?= e(label_of($ex['payment_method'])) ?></td>
                  <td class="num fw-700 text-red">
                    <?= e(money($ex['amount'], false)) ?>
                    <?php if ((float) $ex['vat_amount'] > 0): ?>
                      <div class="table__muted">VAT <?= e(money($ex['vat_amount'], false)) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="actions">
                    <?php if ($ex['receipt_file']): ?>
                      <a class="btn btn--outline btn--sm" target="_blank" rel="noopener"
                         href="<?= url('storage/' . $ex['receipt_file']) ?>" title="View receipt">
                        <?= icon('paperclip') ?>
                      </a>
                    <?php endif; ?>
                    <?php if (can('expenses.manage')): ?>
                      <a class="btn btn--outline btn--sm" href="<?= url('/expenses/' . $ex['id'] . '/edit') ?>">
                        <?= icon('edit') ?>
                      </a>
                      <form method="post" action="<?= url('/expenses/' . $ex['id'] . '/delete') ?>" style="display:inline"
                            data-confirm="Delete expense <?= e($ex['expense_number']) ?>?">
                        <?= csrf_field() ?>
                        <button class="btn btn--danger-soft btn--sm" type="submit"><?= icon('trash') ?></button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="table-foot">
          <span>Showing <?= count($expenses) ?> of <?= number_format($pager['total']) ?></span>
          <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Where the money went</div>
          <div class="card__sub">Selected period, by category</div>
        </div>
      </div>
      <div class="card__body">
        <?php if (!$byCategory): ?>
          <p class="text-sm text-muted mb-0">No data for this period.</p>
        <?php else: ?>
          <div class="bars">
            <?php foreach ($byCategory as $c):
                $pct = $maxCategory > 0 ? ((float) $c['total'] / $maxCategory) * 100 : 0;
            ?>
              <div class="bar-row">
                <span class="bar-row__label" title="<?= e($c['name']) ?>"><?= e($c['name']) ?></span>
                <span class="bar-row__track">
                  <span class="bar-row__fill bar-row__fill--red" style="width:<?= number_format($pct, 2) ?>%"></span>
                </span>
                <span class="bar-row__value"><?= e(money($c['total'], false)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </aside>
</div>
