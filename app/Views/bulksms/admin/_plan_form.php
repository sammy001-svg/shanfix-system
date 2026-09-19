<?php
/**
 * One plan's fields. $plan is the plan being edited, or null for a new one.
 * $planBase is where plans live — the partner portal shares this form and
 * sets its own. Worked out afresh on every include: this form is included
 * once per plan on one page, and a variable kept from the first include
 * would send every edit to the first plan's address.
 */
$formAction = ($planBase ?? '/bulk-sms/plans') . ($plan ? '/' . (int) $plan['id'] : '');
$pid = $plan ? (int) $plan['id'] : 'new';
?>
<form method="post" action="<?= url($formAction) ?>">
  <?= csrf_field() ?>
  <div class="field">
    <label class="label" for="pl-name-<?= $pid ?>">Name</label>
    <input class="input" id="pl-name-<?= $pid ?>" name="name" maxlength="80" required value="<?= e($plan['name'] ?? '') ?>" placeholder="e.g. Business">
  </div>
  <div class="grid-2">
    <div class="field">
      <label class="label" for="pl-units-<?= $pid ?>">Units</label>
      <input class="input" id="pl-units-<?= $pid ?>" name="units" type="number" min="1" step="1" required value="<?= e($plan['units'] ?? '') ?>">
    </div>
    <div class="field">
      <label class="label" for="pl-price-<?= $pid ?>">Price (KES)</label>
      <input class="input" id="pl-price-<?= $pid ?>" name="price" type="number" min="1" step="0.01" required value="<?= e($plan['price'] ?? '') ?>">
    </div>
  </div>
  <div class="grid-2">
    <div class="field">
      <label class="label" for="pl-sort-<?= $pid ?>">Order</label>
      <input class="input" id="pl-sort-<?= $pid ?>" name="sort_order" type="number" step="1" value="<?= e($plan['sort_order'] ?? '0') ?>">
    </div>
    <div class="field">
      <label class="label">&nbsp;</label>
      <label class="check-row"><input type="checkbox" name="is_popular" value="1" <?= !empty($plan['is_popular']) ? 'checked' : '' ?>> <span>Most popular</span></label>
    </div>
  </div>
  <button class="btn btn--primary btn--sm" type="submit"><?= icon('save') ?> <?= $plan ? 'Save' : 'Add plan' ?></button>
</form>
