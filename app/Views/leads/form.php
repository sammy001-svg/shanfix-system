<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Session;

$editing = $lead !== null;
$action  = $editing ? url('/leads/' . $lead['id']) : url('/leads');

$val = static function (string $key, $fallback = '') use ($lead) {
    $old = Session::old($key, null);
    if ($old !== null && $old !== '') {
        return $old;
    }
    return $lead[$key] ?? $fallback;
};
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/leads') ?>">Leads</a> <span>/</span>
      <?= $editing ? e($lead['lead_number']) : 'New lead' ?>
    </div>
    <h1><?= $editing ? 'Edit lead' : 'Register a new lead' ?></h1>
    <div class="page-head__sub">
      Capture the enquiry, link it to what they need, and set your first follow-up.
    </div>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">Contact</div></div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="name">Contact name <span class="req">*</span></label>
              <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>" id="name" name="name"
                     value="<?= e($val('name')) ?>" required maxlength="180" placeholder="Who made the enquiry?">
              <?= error_for($errors ?? [], 'name') ?>
            </div>

            <div class="field">
              <label class="label" for="company">Company</label>
              <input class="input" id="company" name="company" value="<?= e($val('company')) ?>"
                     maxlength="180" placeholder="Leave blank for an individual">
            </div>

            <div class="field">
              <label class="label" for="phone">Phone number</label>
              <input class="input <?= isset($errors['phone']) ? 'has-error' : '' ?>" id="phone" name="phone"
                     value="<?= e($val('phone')) ?>" maxlength="30" placeholder="0712 345 678">
              <?= error_for($errors ?? [], 'phone') ?>
            </div>

            <div class="field">
              <label class="label" for="email">Email address</label>
              <input class="input <?= isset($errors['email']) ? 'has-error' : '' ?>" type="email" id="email" name="email"
                     value="<?= e($val('email')) ?>" maxlength="160">
              <?= error_for($errors ?? [], 'email') ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">What do they need?</div>
            <div class="card__sub">Linking the service makes reporting on demand possible.</div>
          </div>
        </div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="service_id">Service required</label>
              <select class="select" id="service_id" name="service_id">
                <option value="">— Not a service enquiry —</option>
                <?php foreach ($services as $s): ?>
                  <option value="<?= (int) $s['id'] ?>" <?= (int) $val('service_id') === (int) $s['id'] ? 'selected' : '' ?>>
                    <?= e($s['name']) ?>
                    <?= (float) $s['price'] > 0 ? ' — ' . e(money($s['price'])) : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label class="label" for="inventory_item_id">Product required</label>
              <select class="select" id="inventory_item_id" name="inventory_item_id">
                <option value="">— Not a product enquiry —</option>
                <?php foreach ($inventory as $i): ?>
                  <option value="<?= (int) $i['id'] ?>" <?= (int) $val('inventory_item_id') === (int) $i['id'] ? 'selected' : '' ?>>
                    <?= e($i['name']) ?> — <?= e(money($i['selling_price'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field field--full">
              <label class="label" for="requirement">Requirement / brief</label>
              <textarea class="textarea" id="requirement" name="requirement" rows="4"
                        placeholder="What exactly are they asking for? Quantities, sizes, deadlines, budget…"><?= e($val('requirement')) ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Pipeline</div></div>
        <div class="card__body">
          <div class="field mb-12">
            <label class="label" for="stage">Stage</label>
            <select class="select" id="stage" name="stage" <?= $editing ? 'disabled' : '' ?>>
              <?php foreach ($stages as $key => $stage): ?>
                <?php if (in_array($key, ['won', 'lost'], true) && !$editing) continue; ?>
                <option value="<?= e($key) ?>" <?= $val('stage', 'new') === $key ? 'selected' : '' ?>>
                  <?= e($stage['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if ($editing): ?>
              <span class="field-hint">Move stages from the lead page so the change is logged.</span>
            <?php endif; ?>
          </div>

          <div class="field mb-12">
            <label class="label" for="source">How did they find us? <span class="req">*</span></label>
            <select class="select" id="source" name="source" required>
              <?php foreach ($sources as $s): ?>
                <option value="<?= e($s) ?>" <?= $val('source', 'other') === $s ? 'selected' : '' ?>>
                  <?= e(label_of($s)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field mb-12">
            <label class="label" for="estimated_value">Estimated value</label>
            <div class="input-group">
              <span class="input-group__addon input-group__addon--pre"><?= e(setting('currency', 'KES')) ?></span>
              <input class="input" type="number" step="0.01" min="0" id="estimated_value" name="estimated_value"
                     value="<?= e($val('estimated_value', '0.00')) ?>">
            </div>
            <span class="field-hint">Drives the pipeline value figure.</span>
          </div>

          <div class="field mb-12">
            <label class="label" for="assigned_to">Assigned to</label>
            <select class="select" id="assigned_to" name="assigned_to">
              <option value="">— Unassigned —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (int) $val('assigned_to') === (int) $u['id'] ? 'selected' : '' ?>>
                  <?= e($u['name']) ?> (<?= e(label_of($u['role'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="label" for="expected_close_date">Expected close date</label>
            <input class="input" type="date" id="expected_close_date" name="expected_close_date"
                   value="<?= e($val('expected_close_date')) ?>">
          </div>
        </div>
      </div>

      <?php if (!$editing): ?>
        <div class="card">
          <div class="card__head">
            <?= icon('bell') ?>
            <div>
              <div class="card__title">First follow-up</div>
              <div class="card__sub">Optional, but recommended.</div>
            </div>
          </div>
          <div class="card__body">
            <div class="field">
              <label class="label" for="follow_up_at">Remind me on</label>
              <input class="input" type="datetime-local" id="follow_up_at" name="follow_up_at"
                     value="<?= e(date('Y-m-d\TH:i', strtotime('+2 days 09:00'))) ?>">
              <span class="field-hint">Creates a reminder for the assigned team member.</span>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Register lead' ?>
          </button>
          <a class="btn btn--ghost btn--block mt-8"
             href="<?= $editing ? url('/leads/' . $lead['id']) : url('/leads') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
