<?php
require_once APP_PATH . '/Views/partials/icons.php';

$errors = $errors ?? [];
$val = static function (string $field, $default = '') use ($sub) {
    $old = \App\Core\Session::old($field, null);
    if ($old !== null) return $old;
    if ($sub && array_key_exists($field, $sub)) return $sub[$field];
    return $default;
};

$action = $sub ? url('/subscriptions/' . $sub['id']) : url('/subscriptions');

// A new service almost always starts today and renews a year out; filling
// those in saves the common case and can still be changed.
$defaultStart   = date('Y-m-d');
$defaultRenewal = date('Y-m-d', strtotime('+1 year'));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= $sub ? 'Edit ' . e($sub['name']) : 'Register a recurring service' ?></h1>
    <div class="page-head__sub">A website, hosting package, domain or retainer that renews on a cycle.</div>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">What it is</div></div>
        <div class="card__body">

          <div class="row">
            <div class="col field">
              <label class="label" for="client_id">Client <span class="req">*</span></label>
              <select class="select <?= isset($errors['client_id']) ? 'has-error' : '' ?>"
                      id="client_id" name="client_id" required>
                <option value="">Choose a client…</option>
                <?php foreach ($clients as $c): ?>
                  <option value="<?= (int) $c['id'] ?>"
                    <?= (int) $val('client_id', $preset) === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?= error_for($errors, 'client_id') ?>
            </div>

            <div class="col field">
              <label class="label" for="service_type">Type <span class="req">*</span></label>
              <select class="select" id="service_type" name="service_type" required>
                <?php foreach ($types as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $val('service_type', 'website') === $key ? 'selected' : '' ?>>
                    <?= e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="field">
            <label class="label" for="name">What we call it <span class="req">*</span></label>
            <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>" type="text"
                   id="name" name="name" required maxlength="180"
                   value="<?= e($val('name')) ?>"
                   placeholder="e.g. Company website — hosting &amp; maintenance">
            <?= error_for($errors, 'name') ?>
          </div>

          <div class="field">
            <label class="label" for="url">Web address</label>
            <input class="input <?= isset($errors['url']) ? 'has-error' : '' ?>" type="text"
                   id="url" name="url" value="<?= e($val('url')) ?>"
                   placeholder="www.clientsite.co.ke">
            <div class="text-xs text-muted mt-4">
              Adds a Visit button that opens the site in a new tab. Leave blank for
              services with no address, such as a support retainer.
            </div>
            <?= error_for($errors, 'url') ?>
          </div>

          <div class="field">
            <label class="label" for="service_id">Link to a catalogue service</label>
            <select class="select" id="service_id" name="service_id">
              <option value="">Not linked</option>
              <?php foreach ($services as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) $val('service_id') === (int) $s['id'] ? 'selected' : '' ?>>
                  <?= e($s['name']) ?> — <?= e(money($s['price'], false)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="text-xs text-muted mt-4">Optional. Only affects how the renewal invoice describes the line.</div>
          </div>

          <div class="field">
            <label class="label" for="notes">Notes</label>
            <textarea class="textarea" id="notes" name="notes" rows="3"
                      placeholder="Hosting provider, control panel, who to contact…"><?= e($val('notes')) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Money &amp; timing</div></div>
        <div class="card__body">

          <div class="field">
            <label class="label" for="amount">Renewal amount <span class="req">*</span></label>
            <input class="input <?= isset($errors['amount']) ? 'has-error' : '' ?>" type="number"
                   id="amount" name="amount" step="0.01" min="0" required
                   value="<?= e($val('amount', '0')) ?>">
            <?= error_for($errors, 'amount') ?>
          </div>

          <div class="field">
            <label class="label" for="billing_cycle">Renews <span class="req">*</span></label>
            <select class="select" id="billing_cycle" name="billing_cycle" required data-cycle>
              <?php foreach ($cycles as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $val('billing_cycle', 'annual') === $key ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field" data-cycle-days <?= $val('billing_cycle', 'annual') === 'custom' ? '' : 'hidden' ?>>
            <label class="label" for="cycle_days">Days in a cycle</label>
            <input class="input" type="number" id="cycle_days" name="cycle_days" min="1"
                   value="<?= e($val('cycle_days', '365')) ?>">
            <?= error_for($errors, 'cycle_days') ?>
          </div>

          <div class="field">
            <label class="label" for="start_date">Started on <span class="req">*</span></label>
            <input class="input" type="date" id="start_date" name="start_date" required
                   value="<?= e($val('start_date', $defaultStart)) ?>">
          </div>

          <div class="field">
            <label class="label" for="next_renewal_date">Next renewal <span class="req">*</span></label>
            <input class="input <?= isset($errors['next_renewal_date']) ? 'has-error' : '' ?>" type="date"
                   id="next_renewal_date" name="next_renewal_date" required
                   value="<?= e($val('next_renewal_date', $defaultRenewal)) ?>">
            <?= error_for($errors, 'next_renewal_date') ?>
          </div>

          <hr>

          <div class="field">
            <label class="label" for="status">Status</label>
            <select class="select" id="status" name="status">
              <option value="active"    <?= $val('status', 'active') === 'active'    ? 'selected' : '' ?>>Active</option>
              <option value="paused"    <?= $val('status', 'active') === 'paused'    ? 'selected' : '' ?>>Paused — no reminders</option>
              <option value="cancelled" <?= $val('status', 'active') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
          </div>

          <label class="check-row">
            <input type="checkbox" name="auto_invoice" value="1" <?= $val('auto_invoice') ? 'checked' : '' ?>>
            <span>
              <strong>Invoice automatically</strong>
              <span class="text-xs text-muted d-block">
                Raise the renewal invoice without waiting for someone to press the button.
                It is still sent by hand, so nothing reaches the client unreviewed.
              </span>
            </span>
          </label>

          <div class="field mt-16">
            <label class="label" for="reminder_days">Remind the client</label>
            <input class="input" type="text" id="reminder_days" name="reminder_days"
                   value="<?= e($val('reminder_days')) ?>" placeholder="30,14,7,1">
            <div class="text-xs text-muted mt-4">
              Days before renewal, separated by commas. Leave blank to use the system default.
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__body flex gap-8">
          <button class="btn btn--primary" type="submit">
            <?= icon('check') ?> <?= $sub ? 'Save changes' : 'Register service' ?>
          </button>
          <a class="btn btn--ghost" href="<?= url($sub ? '/subscriptions/' . $sub['id'] : '/subscriptions') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
