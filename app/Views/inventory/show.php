<?php
require_once APP_PATH . '/Views/partials/icons.php';

$q      = (float) $item['quantity'];
$r      = (float) $item['reorder_level'];
$cost   = (float) $item['cost_price'];
$sell   = (float) $item['selling_price'];
$margin = $sell - $cost;
$marginPct = $sell > 0 ? ($margin / $sell) * 100 : 0;
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/inventory') ?>">Inventory</a> <span>/</span> <?= e($item['sku']) ?>
    </div>
    <h1><?= e($item['name']) ?></h1>
    <div class="page-head__sub">
      <?= e($item['category_name'] ?: 'Uncategorised') ?> ·
      <code><?= e($item['sku']) ?></code>
      <?php if (!$item['is_active']): ?>
        · <span class="badge badge--grey">Inactive</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <?php if (can('inventory.manage')): ?>
      <button class="btn btn--outline" type="button" data-modal-open="stock-modal">
        <?= icon('repeat') ?> Adjust stock
      </button>
      <a class="btn btn--outline" href="<?= url('/inventory/' . $item['id'] . '/edit') ?>"><?= icon('edit') ?> Edit</a>
      <form method="post" action="<?= url('/inventory/' . $item['id'] . '/delete') ?>" style="display:inline"
            data-confirm="Delete &quot;<?= e($item['name']) ?>&quot;? Items used on documents will be deactivated instead.">
        <?= csrf_field() ?>
        <button class="btn btn--danger-soft" type="submit"><?= icon('trash') ?> Delete</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat <?= $q <= 0 ? 'stat--red' : ($q <= $r ? 'stat--amber' : 'stat--green') ?>">
    <div class="stat__label">Stock on hand</div>
    <div class="stat__value"><?= e(qty($q)) ?> <span class="text-sm text-muted"><?= e($item['unit']) ?></span></div>
    <div class="stat__meta">Reorder at <?= e(qty($r)) ?></div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Selling price</div>
    <div class="stat__value"><?= e(money($sell)) ?></div>
    <div class="stat__meta">per <?= e($item['unit']) ?></div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Cost price</div>
    <div class="stat__value"><?= e(money($cost)) ?></div>
    <div class="stat__meta">
      Margin: <span class="<?= $margin >= 0 ? 'stat__delta--up' : 'stat__delta--down' ?>">
        <?= e(money($margin)) ?> (<?= number_format($marginPct, 1) ?>%)
      </span>
    </div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Stock value</div>
    <div class="stat__value"><?= e(money_short($q * $cost)) ?></div>
    <div class="stat__meta">Retail: <?= e(money_short($q * $sell)) ?></div>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Stock movements</div>
          <div class="card__sub">Every change to this item's quantity.</div>
        </div>
      </div>

      <?php if (!$movements): ?>
        <div class="empty">
          <div class="empty__icon"><?= icon('repeat') ?></div>
          <div class="empty__title">No movements yet</div>
          <p class="empty__text">Stock receipts, issues and stock-takes will appear here.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th class="num">Quantity</th>
                <th class="num">Balance after</th>
                <th>Note</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($movements as $m):
                  $mq = (float) $m['quantity'];
              ?>
                <tr>
                  <td class="text-sm"><?= e(fdatetime($m['created_at'])) ?></td>
                  <td>
                    <span class="badge <?= $m['movement_type'] === 'in' ? 'badge--green' : ($m['movement_type'] === 'out' ? 'badge--red' : 'badge--navy') ?>">
                      <?= e(label_of($m['movement_type'])) ?>
                    </span>
                  </td>
                  <td class="num fw-600 <?= $mq >= 0 ? 'text-green' : 'text-red' ?>">
                    <?= $mq >= 0 ? '+' : '' ?><?= e(qty($mq)) ?>
                  </td>
                  <td class="num"><?= e(qty($m['balance_after'])) ?></td>
                  <td class="text-sm text-muted"><?= e($m['note'] ?: '—') ?></td>
                  <td class="text-sm"><?= e($m['user_name'] ?: 'System') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($recentSales): ?>
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Recently invoiced</div>
            <div class="card__sub">Where this item has been sold.</div>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead>
              <tr>
                <th>Invoice</th><th>Client</th><th>Date</th>
                <th class="num">Qty</th><th class="num">Value</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentSales as $s): ?>
                <tr>
                  <td><a class="table__primary" href="<?= url('/invoices/' . $s['id']) ?>"><?= e($s['doc_number']) ?></a></td>
                  <td class="text-sm"><?= e($s['client_name']) ?></td>
                  <td class="text-sm"><?= e(fdate($s['issue_date'])) ?></td>
                  <td class="num"><?= e(qty($s['quantity'])) ?></td>
                  <td class="num fw-600"><?= e(money($s['line_total'], false)) ?></td>
                  <td><span class="badge <?= status_badge($s['status']) ?>"><?= e(label_of($s['status'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <aside>
    <div class="card">
      <div class="card__head"><div class="card__title">Details</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>SKU</dt><dd><code><?= e($item['sku']) ?></code></dd>
          <dt>Category</dt><dd><?= e($item['category_name'] ?: '—') ?></dd>
          <dt>Unit</dt><dd><?= e($item['unit']) ?></dd>
          <dt>Status</dt>
          <dd><span class="badge <?= $item['is_active'] ? 'badge--green' : 'badge--grey' ?>">
            <?= $item['is_active'] ? 'Active' : 'Inactive' ?></span></dd>
          <dt>Created</dt><dd><?= e(fdate($item['created_at'])) ?></dd>
          <dt>Updated</dt><dd><?= e(fdate($item['updated_at'])) ?></dd>
        </dl>

        <?php if ($item['description']): ?>
          <hr>
          <div class="text-xs uppercase text-muted fw-600 mb-4">Description</div>
          <p class="text-sm"><?= nl2br(e($item['description'])) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </aside>
</div>

<?php if (can('inventory.manage')): ?>
<div class="modal-backdrop" id="stock-modal">
  <div class="modal">
    <form method="post" action="<?= url('/inventory/' . $item['id'] . '/stock') ?>">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="modal__title">Adjust stock — <?= e($item['name']) ?></div>
        <button class="modal__close" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
      </div>

      <div class="modal__body">
        <p class="text-sm text-muted mb-16">
          Current balance: <strong class="text-navy"><?= e(qty($q)) ?> <?= e($item['unit']) ?></strong>
        </p>

        <div class="field mb-16">
          <label class="label">Movement type</label>
          <div class="radio-cards">
            <label class="radio-card">
              <input type="radio" name="movement_type" value="in" checked>
              <span class="radio-card__title">Stock in</span>
              <span class="radio-card__desc">Received from supplier</span>
            </label>
            <label class="radio-card">
              <input type="radio" name="movement_type" value="out">
              <span class="radio-card__title">Stock out</span>
              <span class="radio-card__desc">Issued or damaged</span>
            </label>
            <label class="radio-card">
              <input type="radio" name="movement_type" value="adjustment">
              <span class="radio-card__title">Stock take</span>
              <span class="radio-card__desc">Set the exact counted figure</span>
            </label>
          </div>
        </div>

        <div class="field mb-16">
          <label class="label" for="stock-qty">Quantity <span class="req">*</span></label>
          <div class="input-group">
            <input class="input" type="number" step="0.01" min="0" id="stock-qty" name="quantity" required
                   placeholder="0.00">
            <span class="input-group__addon"><?= e($item['unit']) ?></span>
          </div>
          <span class="field-hint">For a stock take, enter the total counted, not the difference.</span>
        </div>

        <div class="field">
          <label class="label" for="stock-note">Note</label>
          <input class="input" id="stock-note" name="note" maxlength="255"
                 placeholder="e.g. Delivery from Zenith Supplies, LPO 4421">
        </div>
      </div>

      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit"><?= icon('check') ?> Apply</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
