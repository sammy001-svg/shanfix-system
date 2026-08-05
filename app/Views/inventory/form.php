<?php
require_once APP_PATH . '/Views/partials/icons.php';

$editing = $item !== null;
$action  = $editing ? url('/inventory/' . $item['id']) : url('/inventory');

/** Prefer the value the user just typed (after a validation failure). */
$val = static function (string $key, $fallback = '') use ($item) {
    $old = \App\Core\Session::old($key, null);
    if ($old !== null && $old !== '') {
        return $old;
    }
    return $item[$key] ?? $fallback;
};
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/inventory') ?>">Inventory</a> <span>/</span>
      <?= $editing ? e($item['name']) : 'New item' ?>
    </div>
    <h1><?= $editing ? 'Edit item' : 'New inventory item' ?></h1>
    <div class="page-head__sub">Items added here can be pulled straight into quotations and invoices.</div>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">Item details</div></div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="name">Item name <span class="req">*</span></label>
              <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>" id="name" name="name"
                     value="<?= e($val('name')) ?>" required maxlength="180"
                     placeholder="e.g. Roll-up Banner 800x2000mm">
              <?= error_for($errors ?? [], 'name') ?>
            </div>

            <div class="field">
              <label class="label" for="sku">SKU <span class="req">*</span></label>
              <input class="input <?= isset($errors['sku']) ? 'has-error' : '' ?>" id="sku" name="sku"
                     value="<?= e($val('sku')) ?>" required maxlength="60"
                     placeholder="e.g. PRN-BAN-001" style="text-transform:uppercase">
              <span class="field-hint">Unique code used on stock lists.</span>
              <?= error_for($errors ?? [], 'sku') ?>
            </div>

            <div class="field">
              <label class="label" for="category_id">Category</label>
              <select class="select" id="category_id" name="category_id">
                <option value="">— None —</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int) $c['id'] ?>" <?= (int) $val('category_id') === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label class="label" for="unit">Unit of measure</label>
              <select class="select" id="unit" name="unit">
                <?php foreach ($units as $u): ?>
                  <option value="<?= e($u) ?>" <?= $val('unit', 'pcs') === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field field--full">
              <label class="label" for="description">Description</label>
              <textarea class="textarea" id="description" name="description" rows="3"
                        placeholder="Specification, finish, size — this text is suggested on quotations."><?= e($val('description')) ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Pricing</div>
            <div class="card__sub">Selling price is what appears on client documents.</div>
          </div>
        </div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="cost_price">Cost price</label>
              <div class="input-group">
                <span class="input-group__addon input-group__addon--pre"><?= e(setting('currency', 'KES')) ?></span>
                <input class="input <?= isset($errors['cost_price']) ? 'has-error' : '' ?>" type="number" step="0.01" min="0"
                       id="cost_price" name="cost_price" value="<?= e($val('cost_price', '0.00')) ?>">
              </div>
              <span class="field-hint">What you pay. Used for margin reporting only.</span>
              <?= error_for($errors ?? [], 'cost_price') ?>
            </div>

            <div class="field">
              <label class="label" for="selling_price">Selling price <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group__addon input-group__addon--pre"><?= e(setting('currency', 'KES')) ?></span>
                <input class="input <?= isset($errors['selling_price']) ? 'has-error' : '' ?>" type="number" step="0.01" min="0"
                       id="selling_price" name="selling_price" value="<?= e($val('selling_price', '0.00')) ?>" required>
              </div>
              <?= error_for($errors ?? [], 'selling_price') ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Stock</div></div>
        <div class="card__body">
          <div class="field mb-16">
            <label class="label" for="quantity">
              <?= $editing ? 'Current quantity' : 'Opening quantity' ?>
            </label>
            <input class="input" type="number" step="0.01" min="0" id="quantity" name="quantity"
                   value="<?= e($val('quantity', '0')) ?>" <?= $editing ? 'readonly' : '' ?>>
            <?php if ($editing): ?>
              <span class="field-hint">Use “Adjust stock” on the item page to change this — it keeps the movement ledger accurate.</span>
            <?php endif; ?>
            <?= error_for($errors ?? [], 'quantity') ?>
          </div>

          <div class="field">
            <label class="label" for="reorder_level">Reorder level</label>
            <input class="input" type="number" step="0.01" min="0" id="reorder_level" name="reorder_level"
                   value="<?= e($val('reorder_level', '0')) ?>">
            <span class="field-hint">Flag the item as low stock at or below this figure.</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><div class="card__title">Availability</div></div>
        <div class="card__body">
          <label class="check">
            <input type="checkbox" name="is_active" value="1" <?= (int) $val('is_active', 1) === 1 ? 'checked' : '' ?>>
            <span class="check__text">
              <strong>Active</strong>
              <span>Inactive items stay on past documents but are hidden when creating new ones.</span>
            </span>
          </label>
        </div>
      </div>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Create item' ?>
          </button>
          <a class="btn btn--ghost btn--block mt-8"
             href="<?= $editing ? url('/inventory/' . $item['id']) : url('/inventory') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
