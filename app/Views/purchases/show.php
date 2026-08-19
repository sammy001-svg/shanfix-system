<?php
/**
 * A purchase order, and the screen for booking goods in against it.
 *
 * @var array $order
 * @var array $items
 * @var array $movements
 */
require_once APP_PATH . '/Views/partials/icons.php';

$badge = match ($order['status']) {
    'received'  => 'badge--green',
    'partial'   => 'badge--amber',
    'ordered'   => 'badge--blue',
    'cancelled' => 'badge--red',
    default     => 'badge--grey',
};

$canReceive = in_array($order['status'], ['ordered', 'partial'], true) && can('purchases.receive');
$editable   = in_array($order['status'], ['draft', 'ordered'], true);

$outstanding = 0.0;
foreach ($items as $it) {
    $outstanding += max(0, (float) $it['quantity'] - (float) $it['quantity_received']);
}
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($order['po_number']) ?></h1>
    <div class="page-head__sub">
      <a href="<?= url('/suppliers/' . $order['supplier_id']) ?>"><?= e($order['supplier_name']) ?></a>
      · ordered <?= e(fdate($order['order_date'])) ?>
      <?php if ($order['expected_date']): ?>
        · expected <?= e(fdate($order['expected_date'])) ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <span class="badge <?= $badge ?>"><?= e(label_of($order['status'])) ?></span>

    <?php if ($editable && can('purchases.manage')): ?>
      <a class="btn btn--outline" href="<?= url('/purchase-orders/' . $order['id'] . '/edit') ?>">
        <?= icon('edit') ?> Edit
      </a>
    <?php endif; ?>

    <?php if ($order['status'] === 'draft' && can('purchases.manage')): ?>
      <form method="post" action="<?= url('/purchase-orders/' . $order['id'] . '/status') ?>"
            style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="status" value="ordered">
        <button class="btn btn--primary" type="submit"><?= icon('check') ?> Mark as ordered</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($order['status'] === 'draft'): ?>
  <div class="alert alert--info">
    <?= icon('info') ?>
    <div class="alert__body">
      This is still a draft. Mark it as ordered once it has gone to the supplier —
      goods can only be booked in against an order that has been placed.
    </div>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat">
    <div class="stat__label">Subtotal</div>
    <div class="stat__value"><?= e(money_short($order['subtotal'])) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">VAT<?= $order['vat_mode'] === 'exempt' ? ' (exempt)' : '' ?></div>
    <div class="stat__value"><?= e(money_short($order['vat_amount'])) ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Total</div>
    <div class="stat__value"><?= e(money_short($order['total'])) ?></div>
  </div>
  <div class="stat <?= $outstanding > 0 ? 'stat--amber' : '' ?>">
    <div class="stat__label">Still to arrive</div>
    <div class="stat__value"><?= e(qty($outstanding)) ?> items</div>
  </div>
</div>

<form method="post" action="<?= url('/purchase-orders/' . $order['id'] . '/receive') ?>">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card__head">
      <?= icon('list') ?>
      <div>
        <div class="card__title">Lines</div>
        <?php if ($canReceive): ?>
          <div class="card__sub">
            Enter what actually arrived. Stock goes up by that amount and the
            cost price is averaged with what you already hold.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Item</th>
            <th style="width:90px">Ordered</th>
            <th style="width:90px">Received</th>
            <th style="width:120px">Unit cost</th>
            <th style="width:120px">Line total</th>
            <?php if ($canReceive): ?><th style="width:130px">Arriving now</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <?php
            $out  = max(0, (float) $it['quantity'] - (float) $it['quantity_received']);
            $done = $out <= 0.0001;
            ?>
            <tr>
              <td>
                <div class="fw-600"><?= e($it['description']) ?></div>
                <div class="text-xs text-muted">
                  <?= $it['item_type'] === 'inventory' ? 'Adds to stock' : 'Cost only, not stock' ?>
                </div>
              </td>
              <td><?= e(qty($it['quantity'])) ?> <span class="text-muted"><?= e($it['unit'] ?? '') ?></span></td>
              <td class="<?= $done ? 'text-green fw-600' : '' ?>"><?= e(qty($it['quantity_received'])) ?></td>
              <td><?= e(money($it['unit_cost'], false)) ?></td>
              <td><?= e(money($it['line_total'], false)) ?></td>
              <?php if ($canReceive): ?>
                <td>
                  <?php if ($done): ?>
                    <span class="text-muted text-sm">Complete</span>
                  <?php else: ?>
                    <input class="input" type="number" step="0.01" min="0" max="<?= e($out) ?>"
                           name="receive[<?= (int) $it['id'] ?>]" placeholder="<?= e(qty($out)) ?>">
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($canReceive): ?>
      <div class="card__body" style="border-top:1px solid var(--border)">
        <button class="btn btn--primary" type="submit">
          <?= icon('check-circle') ?> Receive these goods
        </button>
        <span class="field-hint" style="display:inline-block;margin-left:10px">
          Leave a line blank if none of it arrived.
        </span>
      </div>
    <?php endif; ?>
  </div>
</form>

<div class="grid-2">
  <?php if ($movements): ?>
    <div class="card">
      <div class="card__head">
        <?= icon('activity') ?>
        <div><div class="card__title">Stock added by this order</div></div>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Item</th><th style="width:90px">Quantity</th><th style="width:150px">When</th></tr>
          </thead>
          <tbody>
            <?php foreach ($movements as $m): ?>
              <tr>
                <td>
                  <?php if ($m['item_id']): ?>
                    <a href="<?= url('/inventory/' . $m['item_id']) ?>"><?= e($m['item_name'] ?: '—') ?></a>
                  <?php else: ?>
                    <?= e($m['item_name'] ?: '—') ?>
                  <?php endif; ?>
                </td>
                <td class="text-green fw-600">+<?= e(qty($m['quantity'])) ?></td>
                <td class="text-sm text-muted"><?= e(time_ago($m['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card__head"><div class="card__title">Order</div></div>
    <div class="card__body">
      <dl class="dl">
        <dt>Supplier</dt><dd><?= e($order['supplier_name']) ?></dd>
        <dt>Their reference</dt><dd><?= e($order['reference'] ?: '—') ?></dd>
        <dt>Payment terms</dt><dd><?= (int) $order['payment_terms'] ?> days</dd>
        <dt>Raised by</dt><dd><?= e($order['created_by_name'] ?: '—') ?></dd>
        <?php if ($order['received_at']): ?>
          <dt>Completed</dt><dd><?= e(fdate($order['received_at'], 'd M Y H:i')) ?></dd>
        <?php endif; ?>
      </dl>

      <?php if ($order['notes']): ?>
        <hr>
        <p class="text-sm" style="white-space:pre-wrap"><?= e($order['notes']) ?></p>
      <?php endif; ?>

      <?php if ($order['status'] === 'draft' && can('purchases.delete')): ?>
        <hr>
        <form method="post" action="<?= url('/purchase-orders/' . $order['id'] . '/delete') ?>"
              onsubmit="return confirm('Delete this draft order?')">
          <?= csrf_field() ?>
          <button class="btn btn--outline btn--sm" type="submit"><?= icon('trash') ?> Delete draft</button>
        </form>
      <?php elseif (in_array($order['status'], ['draft','ordered'], true) && can('purchases.manage')): ?>
        <hr>
        <form method="post" action="<?= url('/purchase-orders/' . $order['id'] . '/status') ?>"
              onsubmit="return confirm('Cancel this order?')">
          <?= csrf_field() ?>
          <input type="hidden" name="status" value="cancelled">
          <button class="btn btn--outline btn--sm" type="submit"><?= icon('x') ?> Cancel order</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
