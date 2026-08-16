<?php
/**
 * @var array $supplier
 * @var array $orders
 */
require_once APP_PATH . '/Views/partials/icons.php';

$badge = static fn(string $s): string => match ($s) {
    'received'  => 'badge--green',
    'partial'   => 'badge--amber',
    'ordered'   => 'badge--blue',
    'cancelled' => 'badge--red',
    default     => 'badge--grey',
};

$onOrder = 0.0;
$spent   = 0.0;
foreach ($orders as $o) {
    if ($o['status'] === 'cancelled') continue;
    $spent += (float) $o['total'];
    if (in_array($o['status'], ['ordered', 'partial'], true)) $onOrder += (float) $o['total'];
}
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($supplier['name']) ?></h1>
    <div class="page-head__sub">
      <?= e($supplier['supplier_code']) ?>
      · <?= (int) $supplier['payment_terms'] ?> day terms
      <?php if ($supplier['status'] === 'inactive'): ?>
        · <span class="badge badge--grey">Inactive</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <?php if (can('purchases.manage')): ?>
      <a class="btn btn--outline" href="<?= url('/suppliers/' . $supplier['id'] . '/edit') ?>">
        <?= icon('edit') ?> Edit
      </a>
      <a class="btn btn--primary"
         href="<?= url('/purchase-orders/create?supplier_id=' . $supplier['id']) ?>">
        <?= icon('plus') ?> New order
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat">
    <div class="stat__label">Orders</div>
    <div class="stat__value"><?= number_format(count($orders)) ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Total spend</div>
    <div class="stat__value"><?= e(money_short($spent)) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Currently on order</div>
    <div class="stat__value"><?= e(money_short($onOrder)) ?></div>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card__head"><div class="card__title">Contact</div></div>
    <div class="card__body">
      <dl class="dl">
        <dt>Contact person</dt><dd><?= e($supplier['contact_person'] ?: '—') ?></dd>
        <dt>Phone</dt><dd><?= e($supplier['phone'] ?: '—') ?></dd>
        <dt>Email</dt><dd><?= e($supplier['email'] ?: '—') ?></dd>
        <dt>KRA PIN</dt><dd><?= e($supplier['kra_pin'] ?: '—') ?></dd>
        <dt>Address</dt>
        <dd>
          <?= e($supplier['address'] ?: '—') ?>
          <?php if ($supplier['city']): ?><br><?= e($supplier['city']) ?><?php endif; ?>
        </dd>
      </dl>
    </div>
  </div>

  <?php if ($supplier['notes']): ?>
    <div class="card">
      <div class="card__head"><div class="card__title">Notes</div></div>
      <div class="card__body">
        <p class="text-sm" style="white-space:pre-wrap"><?= e($supplier['notes']) ?></p>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card__head">
    <?= icon('file-text') ?>
    <div><div class="card__title">Purchase orders</div></div>
  </div>

  <?php if (!$orders): ?>
    <div class="card__body">
      <div class="empty">
        <div class="empty__title">No orders yet</div>
        <p class="empty__text">Nothing has been ordered from this supplier.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Order</th><th style="width:110px">Date</th>
            <th style="width:130px">Total</th><th style="width:110px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/purchase-orders/' . $o['id']) ?>">
                  <?= e($o['po_number']) ?>
                </a>
                <?php if ($o['reference']): ?>
                  <div class="text-xs text-muted">Ref <?= e($o['reference']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-sm"><?= e(fdate($o['order_date'])) ?></td>
              <td><?= e(money($o['total'])) ?></td>
              <td><span class="badge <?= $badge($o['status']) ?>"><?= e(label_of($o['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
