<?php
/**
 * @var array|null $supplier  null when adding
 */
require_once APP_PATH . '/Views/partials/icons.php';

$s      = $supplier ?? [];
$action = $supplier ? url('/suppliers/' . $supplier['id']) : url('/suppliers');
// old() escapes for us; escaping again would turn an apostrophe in a
// supplier's name into visible entity noise.
$val = static fn(string $k, string $d = '') => old($k, $s[$k] ?? $d);
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= $supplier ? 'Edit ' . e($supplier['name']) : 'New supplier' ?></h1>
    <div class="page-head__sub">
      <?= $supplier ? e($supplier['supplier_code']) : 'A code is allocated when you save.' ?>
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url($supplier ? '/suppliers/' . $supplier['id'] : '/suppliers') ?>">
      Cancel
    </a>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card__head">
      <?= icon('package') ?>
      <div>
        <div class="card__title">Supplier details</div>
        <div class="card__sub">Only the name is required</div>
      </div>
    </div>
    <div class="card__body">
      <div class="form-grid form-grid--2">

        <div class="field field--full">
          <label class="label" for="name">Supplier name <span class="req">*</span></label>
          <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>"
                 id="name" name="name" required maxlength="180" value="<?= $val('name') ?>">
          <?= error_for($errors ?? [], 'name') ?>
        </div>

        <div class="field">
          <label class="label" for="contact_person">Contact person</label>
          <input class="input" id="contact_person" name="contact_person" maxlength="140"
                 value="<?= $val('contact_person') ?>">
        </div>

        <div class="field">
          <label class="label" for="phone">Phone</label>
          <input class="input <?= isset($errors['phone']) ? 'has-error' : '' ?>"
                 id="phone" name="phone" maxlength="30" value="<?= $val('phone') ?>">
          <?= error_for($errors ?? [], 'phone') ?>
        </div>

        <div class="field">
          <label class="label" for="email">Email</label>
          <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>"
                 type="email" id="email" name="email" maxlength="160" value="<?= $val('email') ?>">
          <?= error_for($errors ?? [], 'email') ?>
        </div>

        <div class="field">
          <label class="label" for="kra_pin">KRA PIN</label>
          <input class="input" id="kra_pin" name="kra_pin" maxlength="30"
                 style="text-transform:uppercase" value="<?= $val('kra_pin') ?>">
          <span class="field-hint">Needed to reclaim input VAT on what you buy.</span>
        </div>

        <div class="field">
          <label class="label" for="address">Address</label>
          <input class="input" id="address" name="address" maxlength="255" value="<?= $val('address') ?>">
        </div>

        <div class="field">
          <label class="label" for="city">Town or city</label>
          <input class="input" id="city" name="city" maxlength="80" value="<?= $val('city') ?>">
        </div>

        <div class="field">
          <label class="label" for="payment_terms">Payment terms</label>
          <input class="input" type="number" min="0" max="365" id="payment_terms" name="payment_terms"
                 value="<?= old('payment_terms', (string) ($s['payment_terms'] ?? 30)) ?>">
          <span class="field-hint">Days from their invoice to when you pay.</span>
        </div>

        <div class="field">
          <label class="label" for="status">Status</label>
          <select class="input" id="status" name="status">
            <option value="active"   <?= ($s['status'] ?? 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= ($s['status'] ?? '')       === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>

        <div class="field field--full">
          <label class="label" for="notes">Notes</label>
          <textarea class="textarea" id="notes" name="notes" rows="3"><?= $val('notes') ?></textarea>
        </div>

      </div>

      <div class="form-actions">
        <button class="btn btn--primary" type="submit">
          <?= icon('save') ?> <?= $supplier ? 'Save changes' : 'Add supplier' ?>
        </button>
      </div>
    </div>
  </div>
</form>
