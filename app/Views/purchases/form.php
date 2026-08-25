<?php
/**
 * Raise or edit a purchase order.
 *
 * @var array|null $order
 * @var array      $existingItems
 * @var array      $suppliers
 * @var array      $stockItems
 * @var float      $vatRate
 * @var string     $nextNumber
 */
require_once APP_PATH . '/Views/partials/icons.php';

$o        = $order ?? [];
$action   = $order ? url('/purchase-orders/' . $order['id']) : url('/purchase-orders');
$preSelect = (int) ($_GET['supplier_id'] ?? ($o['supplier_id'] ?? 0));

// Always leave a blank row so a line can be added without hunting for a button.
$rows = $existingItems;
$rows[] = ['item_type'=>'inventory','ref_id'=>null,'description'=>'','quantity'=>'','unit'=>'','unit_cost'=>''];
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= $order ? 'Edit ' . e($order['po_number']) : 'New purchase order' ?></h1>
    <div class="page-head__sub"><?= e($nextNumber) ?></div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline"
       href="<?= url($order ? '/purchase-orders/' . $order['id'] : '/purchase-orders') ?>">Cancel</a>
  </div>
</div>

<?php if (!$suppliers): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <strong>No active suppliers.</strong>
      <a href="<?= url('/suppliers/create') ?>">Add a supplier</a> before raising an order.
    </div>
  </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card__head">
      <?= icon('file-text') ?>
      <div><div class="card__title">Order details</div></div>
    </div>
    <div class="card__body">
      <div class="form-grid form-grid--3">

        <div class="field">
          <label class="label" for="supplier_id">Supplier <span class="req">*</span></label>
          <select class="input <?= isset($errors['supplier_id']) ? 'has-error' : '' ?>"
                  id="supplier_id" name="supplier_id" required>
            <option value="">Choose…</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= $preSelect === (int) $s['id'] ? 'selected' : '' ?>>
                <?= e($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?= error_for($errors ?? [], 'supplier_id') ?>
        </div>

        <div class="field">
          <label class="label" for="order_date">Order date <span class="req">*</span></label>
          <input class="input <?= isset($errors['order_date']) ? 'has-error' : '' ?>"
                 type="date" id="order_date" name="order_date" required
                 value="<?= old('order_date', $o['order_date'] ?? date('Y-m-d')) ?>">
          <?= error_for($errors ?? [], 'order_date') ?>
        </div>

        <div class="field">
          <label class="label" for="expected_date">Expected</label>
          <input class="input" type="date" id="expected_date" name="expected_date"
                 value="<?= old('expected_date', $o['expected_date'] ?? '') ?>">
        </div>

        <div class="field">
          <label class="label" for="reference">Supplier reference</label>
          <input class="input" id="reference" name="reference" maxlength="120"
                 value="<?= old('reference', $o['reference'] ?? '') ?>"
                 placeholder="Their quote or invoice number">
        </div>

        <div class="field">
          <label class="label" for="vat_mode">VAT</label>
          <select class="input" id="vat_mode" name="vat_mode">
            <?php foreach ([
              'exclusive' => 'Added to the prices below',
              'inclusive' => 'Already in the prices below',
              'exempt'    => 'No VAT',
            ] as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= ($o['vat_mode'] ?? 'exclusive') === $k ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="field-hint">Rate <?= e(qty($vatRate)) ?>%, from Settings.</span>
        </div>

        <div class="field">
          <label class="label" for="status">Save as</label>
          <select class="input" id="status" name="status">
            <option value="draft"   <?= ($o['status'] ?? 'draft') === 'draft'   ? 'selected' : '' ?>>Draft</option>
            <option value="ordered" <?= ($o['status'] ?? '')      === 'ordered' ? 'selected' : '' ?>>Ordered</option>
          </select>
          <span class="field-hint">Goods can only be received against an order that has been placed.</span>
        </div>

      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <?= icon('list') ?>
      <div>
        <div class="card__title">What you are ordering</div>
        <div class="card__sub">
          Pick a stock item to have it added to inventory when it arrives.
          Leave it blank for anything that is a cost rather than stock, such
          as delivery.
        </div>
      </div>
    </div>

    <?= error_for($errors ?? [], 'items') ?>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:210px">Stock item</th>
            <th>Description</th>
            <th style="width:100px">Quantity</th>
            <th style="width:90px">Unit</th>
            <th style="width:120px">Unit cost</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $row): ?>
            <tr>
              <td>
                <select class="input" name="items[<?= $i ?>][ref_id]" aria-label="Catalogue item" data-po-item>
                  <option value="">— not stock —</option>
                  <?php foreach ($stockItems as $it): ?>
                    <option value="<?= (int) $it['id'] ?>"
                            data-name="<?= e($it['name']) ?>"
                            data-unit="<?= e($it['unit'] ?? '') ?>"
                            data-cost="<?= e($it['cost_price']) ?>"
                            <?= (int) ($row['ref_id'] ?? 0) === (int) $it['id'] ? 'selected' : '' ?>>
                      <?= e($it['name']) ?><?= $it['sku'] ? ' (' . e($it['sku']) . ')' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <input class="input" name="items[<?= $i ?>][description]" aria-label="Description"
                       value="<?= e($row['description'] ?? '') ?>" placeholder="What it is">
              </td>
              <td>
                <input class="input" type="number" step="0.01" min="0"
                       name="items[<?= $i ?>][quantity]" aria-label="Quantity" value="<?= e($row['quantity'] ?? '') ?>">
              </td>
              <td>
                <input class="input" name="items[<?= $i ?>][unit]" aria-label="Unit"
                       value="<?= e($row['unit'] ?? '') ?>" placeholder="ea">
              </td>
              <td>
                <input class="input" type="number" step="0.01" min="0"
                       name="items[<?= $i ?>][unit_cost]" value="<?= e($row['unit_cost'] ?? '') ?>">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card__body">
      <div class="field">
        <label class="label" for="notes">Notes</label>
        <textarea class="textarea" id="notes" name="notes" rows="2"><?= old('notes', $o['notes'] ?? '') ?></textarea>
      </div>

      <div class="form-actions">
        <button class="btn btn--primary" type="submit" <?= $suppliers ? '' : 'disabled' ?>>
          <?= icon('save') ?> <?= $order ? 'Save changes' : 'Create order' ?>
        </button>
      </div>
    </div>
  </div>
</form>

<?php // Choosing a stock item fills the line in, so nobody retypes it. ?>
<script nonce="<?= e(csp_nonce()) ?>">
  document.querySelectorAll('[data-po-item]').forEach(function (select) {
    select.addEventListener('change', function () {
      var opt = select.options[select.selectedIndex];
      var row = select.closest('tr');
      if (!opt || !opt.value || !row) return;

      var desc = row.querySelector('[name$="[description]"]');
      var unit = row.querySelector('[name$="[unit]"]');
      var cost = row.querySelector('[name$="[unit_cost]"]');

      if (desc && !desc.value) desc.value = opt.dataset.name || '';
      if (unit && !unit.value) unit.value = opt.dataset.unit || '';
      if (cost && !cost.value) cost.value = opt.dataset.cost || '';
    });
  });
</script>
