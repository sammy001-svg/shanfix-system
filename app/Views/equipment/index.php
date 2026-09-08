<?php
/**
 * The machines.
 *
 * Led by what is falling due rather than by an inventory, because the
 * question that costs money is never "what do we own" — it is "what needs
 * servicing, and what is out of action right now".
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$today   = date('Y-m-d');
$horizon = date('Y-m-d', (int) strtotime('+' . $warnDays . ' days'));

$tone = static fn(string $s): string => match ($s) {
    'in_service'   => 'green',
    'idle'         => 'grey',
    'under_repair' => 'red',
    'retired'      => 'grey',
    'disposed'     => 'grey',
    default        => 'grey',
};

/** How a service date reads: overdue, due soon, or fine. */
$due = static function (?string $date) use ($today, $horizon): array {
    if ($date === null) {
        return ['text-muted', 'Not on a schedule'];
    }

    if ($date < $today)   { return ['badge badge--red',   'Overdue since ' . fdate($date)]; }
    if ($date <= $horizon) { return ['badge badge--amber', 'Due ' . fdate($date)]; }

    return ['text-muted', fdate($date)];
};

$filterUrl = static fn(string $s): string => url('/equipment' . ($s === '' ? '' : '?status=' . $s));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Equipment</h1>
    <div class="page-head__sub">
      What we own, where it is, and when it was last looked at
    </div>
  </div>
  <?php if (Auth::can('equipment.manage')): ?>
    <div class="page-head__actions">
      <a class="btn btn--primary" href="<?= url('/equipment/new') ?>">
        <?= icon('plus') ?> Add a machine
      </a>
    </div>
  <?php endif; ?>
</div>

<div class="stat-grid mb-16">
  <div class="stat <?= $dueCount > 0 ? 'stat--amber' : '' ?>">
    <div class="stat__value"><?= (int) $dueCount ?></div>
    <div class="stat__label">Due for service</div>
  </div>
  <div class="stat <?= $repairCount > 0 ? 'stat--red' : '' ?>">
    <div class="stat__value"><?= (int) $repairCount ?></div>
    <div class="stat__label">Out of action</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= count($rows) ?></div>
    <div class="stat__label">Shown</div>
  </div>
</div>

<div class="card">
  <div class="filters">
    <form method="get" action="<?= url('/equipment') ?>" class="row-form flex-1">
      <div class="field mb-0 flex-1">
        <label class="label" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>"
               placeholder="A name, a code, a make, a model or a serial number…">
      </div>
      <button class="btn btn--outline" type="submit">Show</button>
    </form>
  </div>
  <div class="card__body">
    <nav class="tabs">
      <a class="tab <?= $status === '' && !$due ? 'is-active' : '' ?>" href="<?= e($filterUrl('')) ?>">Everything</a>
      <a class="tab <?= $due ? 'is-active' : '' ?>" href="<?= url('/equipment?due=1') ?>">
        Due for service
        <?php if ($dueCount > 0): ?><span class="tab__count"><?= (int) $dueCount ?></span><?php endif; ?>
      </a>
      <?php foreach (['in_service' => 'In service', 'idle' => 'Idle', 'under_repair' => 'Under repair', 'retired' => 'Retired'] as $k => $label): ?>
        <a class="tab <?= $status === $k ? 'is-active' : '' ?>" href="<?= e($filterUrl($k)) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('package') ?></div>
      <div class="card__title mt-8">
        <?= $due ? 'Nothing due' : 'Nothing here' ?>
      </div>
      <p class="text-sm text-muted mb-0">
        <?= $due
          ? 'Every machine on a schedule has been serviced recently enough.'
          : ($search !== '' ? 'Nothing matches that.' : 'Add a machine to get started.') ?>
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Machine</th>
            <th style="width:150px">Where</th>
            <th style="width:160px">Who holds it</th>
            <th style="width:120px">State</th>
            <th style="width:200px">Next service</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php [$cls, $label] = $due($r['next_service_on']); ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/equipment/' . (int) $r['id']) ?>"><?= e($r['name']) ?></a>
                <div class="text-xs text-muted">
                  <?= e($r['asset_code']) ?>
                  <?php if ($r['make'] || $r['model']): ?>
                    &middot; <?= e(trim($r['make'] . ' ' . $r['model'])) ?>
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-sm text-muted"><?= e($r['location'] ?: '—') ?></td>
              <td class="text-sm text-muted"><?= e($r['assignee'] ?: '—') ?></td>
              <td>
                <span class="badge badge--<?= e($tone((string) $r['status'])) ?>">
                  <?= e(label_of((string) $r['status'])) ?>
                </span>
              </td>
              <td class="text-sm"><span class="<?= e($cls) ?>"><?= e($label) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
