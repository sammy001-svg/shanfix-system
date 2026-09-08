<?php
/**
 * Adding a machine, or correcting one.
 *
 * Shared between the new-machine page and the bottom of an existing
 * machine's page, so a field only has to be described once.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$q   = $equipment ?? null;
$val = static fn(string $k, string $d = ''): string => (string) old($k, (string) ($q[$k] ?? $d));

$action = $q ? url('/equipment/' . (int) $q['id']) : url('/equipment/new');
?>

<?php if (!$q): ?>
  <p class="text-sm mb-12"><a href="<?= url('/equipment') ?>">&larr; Equipment</a></p>
  <div class="page-head">
    <div class="page-head__text">
      <h1>Add a machine</h1>
      <div class="page-head__sub">A printer, a cutter, a laminator, a vehicle — anything worth keeping track of</div>
    </div>
  </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card__head"><div class="card__title">What it is</div></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label class="label" for="name">Name <span class="req">*</span></label>
          <input class="input <?= isset($errors['name']) ? 'has-error' : '' ?>"
                 type="text" id="name" name="name" required maxlength="180"
                 value="<?= e($val('name')) ?>" <?= $q ? '' : 'autofocus' ?>
                 placeholder="Roland large-format printer">
          <?= error_for($errors ?? [], 'name') ?>
        </div>
        <div class="field">
          <label class="label" for="category">Category</label>
          <input class="input" type="text" id="category" name="category" maxlength="80"
                 value="<?= e($val('category')) ?>" placeholder="Printer, cutter, vehicle…">
        </div>
        <div class="field">
          <label class="label" for="make">Make</label>
          <input class="input" type="text" id="make" name="make" maxlength="120"
                 value="<?= e($val('make')) ?>">
        </div>
        <div class="field">
          <label class="label" for="model">Model</label>
          <input class="input" type="text" id="model" name="model" maxlength="120"
                 value="<?= e($val('model')) ?>">
        </div>
        <div class="field">
          <label class="label" for="serial_number">Serial number</label>
          <input class="input" type="text" id="serial_number" name="serial_number" maxlength="120"
                 value="<?= e($val('serial_number')) ?>">
        </div>
        <div class="field">
          <label class="label" for="status">State</label>
          <select class="select" id="status" name="status">
            <?php foreach ([
              'in_service'   => 'In service',
              'idle'         => 'Idle — working, not in use',
              'under_repair' => 'Under repair — out of action',
              'retired'      => 'Retired',
              'disposed'     => 'Sold or scrapped',
            ] as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $val('status', 'in_service') === $k ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">Where it is and who has it</div></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label class="label" for="location">Where it lives</label>
          <input class="input" type="text" id="location" name="location" maxlength="180"
                 value="<?= e($val('location')) ?>" placeholder="Workshop, front office, van…">
        </div>
        <div class="field">
          <label class="label" for="assigned_to">Answerable for it</label>
          <select class="select" id="assigned_to" name="assigned_to">
            <option value="">Nobody in particular</option>
            <?php foreach ($people as $p): ?>
              <option value="<?= (int) $p['id'] ?>"
                <?= (string) $val('assigned_to') === (string) $p['id'] ? 'selected' : '' ?>>
                <?= e($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">What it cost</div></div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label class="label" for="purchased_on">Bought on</label>
          <input class="input" type="date" id="purchased_on" name="purchased_on"
                 value="<?= e($val('purchased_on')) ?>">
        </div>
        <div class="field">
          <label class="label" for="purchase_cost">What it cost</label>
          <input class="input" type="number" step="0.01" min="0" id="purchase_cost" name="purchase_cost"
                 value="<?= e($val('purchase_cost', '0')) ?>">
        </div>
        <div class="field">
          <label class="label" for="supplier_id">Bought from</label>
          <select class="select" id="supplier_id" name="supplier_id">
            <option value="">Not recorded</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?= (int) $s['id'] ?>"
                <?= (string) $val('supplier_id') === (string) $s['id'] ? 'selected' : '' ?>>
                <?= e($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="warranty_until">Warranty until</label>
          <input class="input" type="date" id="warranty_until" name="warranty_until"
                 value="<?= e($val('warranty_until')) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <div class="card__title">Servicing</div>
      <span class="text-xs text-muted">Leave the interval empty if it is not on a schedule</span>
    </div>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label class="label" for="service_interval_days">Service every</label>
          <div class="input-group">
            <input class="input" type="number" step="1" min="0"
                   id="service_interval_days" name="service_interval_days"
                   value="<?= e($val('service_interval_days')) ?>">
            <span class="input-group__addon">days</span>
          </div>
        </div>
        <div class="field">
          <label class="label" for="last_serviced_on">Last serviced</label>
          <input class="input" type="date" id="last_serviced_on" name="last_serviced_on"
                 value="<?= e($val('last_serviced_on')) ?>">
        </div>
        <div class="field">
          <label class="label" for="next_service_on">Next due</label>
          <input class="input" type="date" id="next_service_on" name="next_service_on"
                 value="<?= e($val('next_service_on')) ?>">
          <span class="field-hint">
            Left empty, this is worked out from the interval and the last service.
          </span>
        </div>
      </div>

      <div class="field">
        <label class="label" for="notes">Notes</label>
        <textarea class="input" id="notes" name="notes" rows="3"><?= e($val('notes')) ?></textarea>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn--primary" type="submit">
      <?= icon('check') ?> <?= $q ? 'Save' : 'Add it' ?>
    </button>
    <a class="btn btn--ghost" href="<?= url($q ? '/equipment/' . (int) $q['id'] : '/equipment') ?>">Cancel</a>
  </div>
</form>
