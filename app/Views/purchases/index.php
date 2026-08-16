<?php
/**
 * @var array $orders
 * @var array $pager
 * @var array $summary
 * @var array $filters
 */
require_once APP_PATH . '/Views/partials/icons.php';

$badge = static fn(string $s): string => match ($s) {
    'received'  => 'badge--green',
    'partial'   => 'badge--amber',
    'ordered'   => 'badge--blue',
    'cancelled' => 'badge--red',
    default     => 'badge--grey',
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Purchase orders</h1>
    <div class="page-head__sub">What you have ordered, and what has arrived.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/suppliers') ?>">
      <?= icon('package') ?> Suppliers
    </a>
    <?php if (can('purchases.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/purchase-orders/create') ?>">
        <?= icon('plus') ?> New order
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat">
    <div class="stat__label">Open orders</div>
    <div class="stat__value"><?= number_format((int) $summary['open_orders']) ?></div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Value on order</div>
    <div class="stat__value"><?= e(money_short($summary['on_order'])) ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Received, last 30 days</div>
    <div class="stat__value"><?= e(money_short($summary['received_30d'])) ?></div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/purchase-orders') ?>">
    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="input" id="status" name="status">
        <option value="">All</option>
        <?php foreach (['draft','ordered','partial','received','cancelled'] as $s): ?>
          <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>>
            <?= e(label_of($s)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <button class="btn btn--outline" type="submit"><?= icon('filter') ?> Filter</button>
    </div>
  </form>

  <?php if (!$orders): ?>
    <div class="card__body">
      <div class="empty">
        <div class="empty__icon"><?= icon('file-text') ?></div>
        <div class="empty__title">No purchase orders</div>
        <p class="empty__text">
          Raise an order, then book the goods in when they arrive. Stock goes up
          by what actually turned up, and the cost price is averaged with what
          you already hold.
        </p>
        <?php if (can('purchases.manage')): ?>
          <a class="btn btn--primary mt-12" href="<?= url('/purchase-orders/create') ?>">
            <?= icon('plus') ?> New order
          </a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Order</th>
            <th>Supplier</th>
            <th style="width:110px">Ordered</th>
            <th style="width:110px">Expected</th>
            <th style="width:130px">Total</th>
            <th style="width:110px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <?php
            $late = $o['expected_date']
                 && in_array($o['status'], ['ordered', 'partial'], true)
                 && strtotime($o['expected_date']) < strtotime('today');
            ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/purchase-orders/' . $o['id']) ?>">
                  <?= e($o['po_number']) ?>
                </a>
                <?php if ($o['reference']): ?>
                  <div class="text-xs text-muted">Ref <?= e($o['reference']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-sm"><?= e($o['supplier_name']) ?></td>
              <td class="text-sm"><?= e(fdate($o['order_date'])) ?></td>
              <td class="text-sm <?= $late ? 'text-red fw-600' : '' ?>">
                <?= $o['expected_date'] ? e(fdate($o['expected_date'])) : '—' ?>
                <?php if ($late): ?><div class="text-xs">overdue</div><?php endif; ?>
              </td>
              <td><?= e(money($o['total'])) ?></td>
              <td><span class="badge <?= $badge($o['status']) ?>"><?= e(label_of($o['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($orders) ?> of <?= number_format($pager['total']) ?></span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
