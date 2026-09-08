<?php
/**
 * One machine, and what has been done to it.
 *
 * The service history is the point of the page. Recording a service also
 * moves the machine's next-due date, so the register stays true without
 * anybody having to keep two things in step by hand.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$canManage = Auth::can('equipment.manage');
$today     = date('Y-m-d');

$tone = static fn(string $s): string => match ($s) {
    'in_service'   => 'green',
    'under_repair' => 'red',
    default        => 'grey',
};

$next   = $equipment['next_service_on'];
$overdue = $next !== null && $next < $today;
?>

<p class="text-sm mb-12"><a href="<?= url('/equipment') ?>">&larr; Equipment</a></p>

<div class="card partner-head">
  <div class="partner-head__id">
    <div class="partner-head__name">
      <h1><?= e($equipment['name']) ?></h1>
      <span class="badge badge--<?= e($tone((string) $equipment['status'])) ?>">
        <?= e(label_of((string) $equipment['status'])) ?>
      </span>
      <?php if ($overdue): ?>
        <span class="badge badge--red">Service overdue</span>
      <?php endif; ?>
    </div>

    <dl class="partner-head__facts">
      <dt>Code</dt><dd><?= e($equipment['asset_code']) ?></dd>

      <?php if ($equipment['make'] || $equipment['model']): ?>
        <dt>Make and model</dt><dd><?= e(trim($equipment['make'] . ' ' . $equipment['model'])) ?></dd>
      <?php endif; ?>

      <?php if ($equipment['serial_number']): ?>
        <dt>Serial</dt><dd><?= e($equipment['serial_number']) ?></dd>
      <?php endif; ?>

      <?php if ($equipment['category']): ?>
        <dt>Category</dt><dd><?= e($equipment['category']) ?></dd>
      <?php endif; ?>

      <?php if ($equipment['location']): ?>
        <dt>Where</dt><dd><?= e($equipment['location']) ?></dd>
      <?php endif; ?>

      <dt>Answerable</dt>
      <dd>
        <?php if ($equipment['assigned_to']): ?>
          <a href="<?= url('/staff/' . (int) $equipment['assigned_to']) ?>"><?= e($equipment['assignee']) ?></a>
        <?php else: ?>
          <span class="text-muted">Nobody in particular</span>
        <?php endif; ?>
      </dd>

      <?php if ($equipment['purchased_on']): ?>
        <dt>Bought</dt>
        <dd>
          <?= e(fdate($equipment['purchased_on'])) ?>
          <?php if ($equipment['supplier_name']): ?>
            <span class="text-muted">from <?= e($equipment['supplier_name']) ?></span>
          <?php endif; ?>
        </dd>
      <?php endif; ?>

      <?php if ($equipment['warranty_until']): ?>
        <dt>Warranty</dt>
        <dd>
          <?= e(fdate($equipment['warranty_until'])) ?>
          <?php if ($equipment['warranty_until'] < $today): ?>
            <span class="text-muted">(expired)</span>
          <?php endif; ?>
        </dd>
      <?php endif; ?>

      <dt>Serviced every</dt>
      <dd>
        <?php if ($equipment['service_interval_days']): ?>
          <?= (int) $equipment['service_interval_days'] ?> days
        <?php else: ?>
          <span class="text-muted">Not on a schedule</span>
        <?php endif; ?>
      </dd>
    </dl>
  </div>

  <div class="partner-head__money">
    <div class="stat">
      <div class="stat__value"><?= e(money($equipment['purchase_cost'], false)) ?></div>
      <div class="stat__label">What it cost</div>
    </div>
    <div class="stat <?= $spent > 0.009 ? 'stat--amber' : '' ?>">
      <div class="stat__value"><?= e(money($spent, false)) ?></div>
      <div class="stat__label">Spent keeping it going</div>
    </div>
    <div class="stat <?= $overdue ? 'stat--red' : '' ?>">
      <div class="stat__value">
        <?= $next ? e(fdate($next, 'd M')) : '—' ?>
      </div>
      <div class="stat__label">
        <?= $next ? ($overdue ? 'Overdue since' : 'Next service') : 'No service due' ?>
      </div>
    </div>
  </div>
</div>

<?php if ($equipment['notes']): ?>
  <div class="card">
    <div class="card__head"><div class="card__title">Notes</div></div>
    <div class="card__body text-sm" style="white-space:pre-line"><?= e($equipment['notes']) ?></div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <div class="card__title">What has been done to it</div>
    <span class="text-xs text-muted"><?= count($history) ?></span>
  </div>

  <?php if (!$history): ?>
    <div class="card__body text-sm text-muted">
      Nothing recorded yet.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="width:120px">When</th>
            <th style="width:110px">What</th>
            <th>Done</th>
            <th style="width:180px">By</th>
            <th style="width:130px" class="num">Cost</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td class="text-sm"><?= e(fdate($h['serviced_on'])) ?></td>
              <td class="text-sm text-muted"><?= e(label_of((string) $h['kind'])) ?></td>
              <td>
                <span class="fw-600"><?= e($h['summary']) ?></span>
                <?php if ($h['detail']): ?>
                  <div class="text-xs text-muted" style="white-space:pre-line"><?= e($h['detail']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-sm text-muted">
                <?= e($h['employee_name'] ?: ($h['supplier_name'] ?: ($h['done_by_name'] ?: '—'))) ?>
              </td>
              <td class="num"><?= (float) $h['cost'] > 0.009 ? e(money($h['cost'], false)) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($canManage): ?>
    <div class="card__body">
      <form method="post" action="<?= url('/equipment/' . (int) $equipment['id'] . '/service') ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="field">
            <label class="label" for="serviced_on">When</label>
            <input class="input" type="date" id="serviced_on" name="serviced_on" required
                   value="<?= e(date('Y-m-d')) ?>">
          </div>
          <div class="field">
            <label class="label" for="kind">What kind</label>
            <select class="select" id="kind" name="kind">
              <option value="service">Routine service</option>
              <option value="repair">Repair</option>
              <option value="inspection">Inspection</option>
              <option value="part">A part replaced</option>
            </select>
          </div>
          <div class="field">
            <label class="label" for="cost">What it cost</label>
            <input class="input" type="number" step="0.01" min="0" id="cost" name="cost" value="0">
          </div>
          <div class="field">
            <label class="label" for="done_by_employee">One of ours did it</label>
            <select class="select" id="done_by_employee" name="done_by_employee">
              <option value="">No</option>
              <?php foreach ($people as $p): ?>
                <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="done_by_supplier">Or an outside firm</label>
            <select class="select" id="done_by_supplier" name="done_by_supplier">
              <option value="">No</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="done_by_name">Or somebody we have no record of</label>
            <input class="input" type="text" id="done_by_name" name="done_by_name" maxlength="140">
          </div>
        </div>

        <div class="field">
          <label class="label" for="summary">What was done</label>
          <input class="input" type="text" id="summary" name="summary" required maxlength="255"
                 placeholder="Print heads cleaned and aligned">
        </div>

        <div class="field">
          <label class="label" for="detail">Anything more</label>
          <textarea class="input" id="detail" name="detail" rows="3"></textarea>
        </div>

        <div class="field" style="max-width:280px">
          <label class="label" for="next_service_on">Next one due</label>
          <input class="input" type="date" id="next_service_on" name="next_service_on">
          <span class="field-hint">
            Leave empty and it is worked out from the interval on this machine.
          </span>
        </div>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit"><?= icon('check') ?> Record it</button>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
  <div class="card">
    <div class="card__head"><div class="card__title">The record</div></div>
  </div>
  <?php require APP_PATH . '/Views/equipment/form.php'; ?>
<?php endif; ?>
