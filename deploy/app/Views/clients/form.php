<?php
require_once APP_PATH . '/Views/partials/icons.php';

$editing = $client !== null;
$action  = $editing ? url('/clients/' . $client['id']) : url('/clients');

$val = static function (string $key, $fallback = '') use ($client) {
    $old = \App\Core\Session::old($key, null);
    if ($old !== null && $old !== '') {
        return $old;
    }
    return $client[$key] ?? $fallback;
};
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/clients') ?>">Clients</a> <span>/</span>
      <?= $editing ? e($client['name']) : 'New client' ?>
    </div>
    <h1><?= $editing ? 'Edit client' : 'Register new client' ?></h1>
    <div class="page-head__sub">
      <?= $editing
          ? 'Update contact and billing details.'
          : 'Once registered you can raise quotations, invoices and receipts from this client\'s profile.' ?>
    </div>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">Client identity</div></div>
        <div class="card__body">
          <div class="field mb-16">
            <label class="label">Client type</label>
            <div class="radio-cards">
              <label class="radio-card">
                <input type="radio" name="client_type" value="company"
                       <?= $val('client_type', 'company') === 'company' ? 'checked' : '' ?>>
                <span class="radio-card__title">Company</span>
                <span class="radio-card__desc">Business, NGO or institution</span>
              </label>
              <label class="radio-card">
                <input type="radio" name="client_type" value="individual"
                       <?= $val('client_type', 'company') === 'individual' ? 'checked' : '' ?>>
                <span class="radio-card__title">Individual</span>
                <span class="radio-card__desc">Personal client</span>
              </label>
            </div>
          </div>

          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="name">Client name <span class="req">*</span></label>
              <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>" id="name" name="name"
                     value="<?= e($val('name')) ?>" required maxlength="180"
                     placeholder="Company or full name">
              <?= error_for($errors ?? [], 'name') ?>
            </div>

            <div class="field">
              <label class="label" for="contact_person">Contact person</label>
              <input class="input" id="contact_person" name="contact_person"
                     value="<?= e($val('contact_person')) ?>" maxlength="140"
                     placeholder="Who do you deal with?">
            </div>

            <div class="field">
              <label class="label" for="industry">Industry</label>
              <input class="input" id="industry" name="industry" value="<?= e($val('industry')) ?>"
                     maxlength="120" placeholder="e.g. Hospitality, SACCO, Retail">
            </div>

            <div class="field">
              <label class="label" for="kra_pin">KRA PIN</label>
              <input class="input" id="kra_pin" name="kra_pin" value="<?= e($val('kra_pin')) ?>"
                     maxlength="30" placeholder="e.g. P051234567X" style="text-transform:uppercase">
              <span class="field-hint">Printed on VAT invoices.</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Contact details</div>
            <div class="card__sub">A phone number is required for M-Pesa STK Push requests.</div>
          </div>
        </div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="phone">Phone number</label>
              <input class="input <?= isset($errors['phone']) ? 'has-error' : '' ?>" id="phone" name="phone"
                     value="<?= e($val('phone')) ?>" maxlength="30" placeholder="0712 345 678">
              <span class="field-hint">Safaricom number used for STK Push prompts.</span>
              <?= error_for($errors ?? [], 'phone') ?>
            </div>

            <div class="field">
              <label class="label" for="alt_phone">Alternative phone</label>
              <input class="input" id="alt_phone" name="alt_phone" value="<?= e($val('alt_phone')) ?>"
                     maxlength="30" placeholder="Optional">
              <?= error_for($errors ?? [], 'alt_phone') ?>
            </div>

            <div class="field">
              <label class="label" for="email">Email address</label>
              <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>" type="email" id="email" name="email"
                     value="<?= e($val('email')) ?>" maxlength="160" placeholder="accounts@client.co.ke">
              <?= error_for($errors ?? [], 'email') ?>
            </div>

            <div class="field">
              <label class="label" for="city">Town / City</label>
              <input class="input" id="city" name="city" value="<?= e($val('city')) ?>"
                     maxlength="80" placeholder="e.g. Nairobi">
            </div>

            <div class="field field--full">
              <label class="label" for="address">Physical / postal address</label>
              <input class="input" id="address" name="address" value="<?= e($val('address')) ?>"
                     maxlength="255" placeholder="e.g. Kimathi Street, Rehema House, 3rd Floor">
              <?= error_for($errors ?? [], 'address') ?>
            </div>

            <div class="field field--full">
              <label class="label" for="notes">Internal notes</label>
              <textarea class="textarea" id="notes" name="notes" rows="3"
                        placeholder="Payment behaviour, preferred contact times, special instructions…"><?= e($val('notes')) ?></textarea>
              <span class="field-hint">Only visible to your team — never printed on client documents.</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Account settings</div></div>
        <div class="card__body">
          <div class="field mb-16">
            <label class="label" for="credit_limit">Credit limit</label>
            <div class="input-group">
              <span class="input-group__addon input-group__addon--pre"><?= e(setting('currency', 'KES')) ?></span>
              <input class="input" type="number" step="0.01" min="0" id="credit_limit" name="credit_limit"
                     value="<?= e($val('credit_limit', '0.00')) ?>">
            </div>
            <span class="field-hint">0 means no limit is tracked.</span>
          </div>

          <div class="field">
            <label class="label" for="status">Status</label>
            <select class="select" id="status" name="status">
              <option value="active"   <?= $val('status', 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= $val('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>
      </div>

      <?php if ($editing): ?>
        <div class="card">
          <div class="card__head"><div class="card__title">Record</div></div>
          <div class="card__body">
            <dl class="dl">
              <dt>Client code</dt><dd><code><?= e($client['client_code']) ?></code></dd>
              <dt>Registered</dt><dd><?= e(fdate($client['created_at'])) ?></dd>
              <dt>Added by</dt><dd><?= e($client['created_by_name'] ?? 'System') ?></dd>
            </dl>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Register client' ?>
          </button>
          <a class="btn btn--ghost btn--block mt-8"
             href="<?= $editing ? url('/clients/' . $client['id']) : url('/clients') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
