<?php
/**
 * Everyone on the books.
 *
 * People who have left are kept for the records and hidden by default:
 * somebody opening this is looking for who works here now.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;

$byStatus = [];
foreach ($counts as $c) { $byStatus[$c['status']] = (int) $c['n']; }

$tone = static fn(string $s): string => match ($s) {
    'active'    => 'green',
    'on_leave'  => 'blue',
    'suspended' => 'amber',
    'left'      => 'grey',
    default     => 'grey',
};

$filterUrl = static fn(string $s): string =>
    url('/staff' . ($s === '' ? '' : '?status=' . $s));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Staff</h1>
    <div class="page-head__sub">
      The people who work here, on what terms, and what they are paid
    </div>
  </div>
  <?php if (Auth::can('hr.manage')): ?>
    <div class="page-head__actions">
      <a class="btn btn--primary" href="<?= url('/staff/new') ?>">
        <?= icon('user-plus') ?> Add someone
      </a>
    </div>
  <?php endif; ?>
</div>

<div class="stat-grid mb-16">
  <div class="stat stat--green">
    <div class="stat__value"><?= (int) ($byStatus['active'] ?? 0) ?></div>
    <div class="stat__label">Working</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= (int) ($byStatus['on_leave'] ?? 0) ?></div>
    <div class="stat__label">On leave</div>
  </div>
  <div class="stat <?= ($byStatus['suspended'] ?? 0) > 0 ? 'stat--amber' : '' ?>">
    <div class="stat__value"><?= (int) ($byStatus['suspended'] ?? 0) ?></div>
    <div class="stat__label">Suspended</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= (int) ($byStatus['left'] ?? 0) ?></div>
    <div class="stat__label">Left</div>
  </div>
</div>

<div class="card">
  <div class="filters">
    <form method="get" action="<?= url('/staff') ?>" class="row-form flex-1">
      <div class="field mb-0 flex-1">
        <label class="label" for="q">Search</label>
        <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>"
               placeholder="A name, a number, a job or a department…">
      </div>
      <?php if ($status !== ''): ?>
        <input type="hidden" name="status" value="<?= e($status) ?>">
      <?php endif; ?>
      <button class="btn btn--outline" type="submit">Show</button>
    </form>
  </div>
  <div class="card__body">
    <nav class="tabs">
      <a class="tab <?= $status === '' ? 'is-active' : '' ?>" href="<?= e($filterUrl('')) ?>">Here now</a>
      <?php foreach (['active' => 'Working', 'on_leave' => 'On leave', 'suspended' => 'Suspended', 'left' => 'Left'] as $k => $label): ?>
        <a class="tab <?= $status === $k ? 'is-active' : '' ?>" href="<?= e($filterUrl($k)) ?>">
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <div class="card__title">
      <?= (int) $total ?> <?= $total === 1 ? 'person' : 'people' ?>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="card__body text-center">
      <div class="text-muted" style="font-size:30px;line-height:1"><?= icon('users') ?></div>
      <div class="card__title mt-8">Nobody here</div>
      <p class="text-sm text-muted mb-0">
        <?= $search !== '' ? 'Nothing matches that. Try a different word.' : 'Add somebody to get started.' ?>
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th style="width:180px">Job</th>
            <th style="width:150px">Department</th>
            <th style="width:120px">Terms</th>
            <th style="width:120px">Since</th>
            <th style="width:140px" class="num">Basic</th>
            <th style="width:110px">State</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/staff/' . (int) $r['id']) ?>"><?= e($r['name']) ?></a>
                <div class="text-xs text-muted"><?= e($r['employee_number']) ?></div>
              </td>
              <td class="text-sm"><?= e($r['job_title'] ?: '—') ?></td>
              <td class="text-sm text-muted"><?= e($r['department'] ?: '—') ?></td>
              <td class="text-sm text-muted"><?= e(label_of((string) $r['employment_type'])) ?></td>
              <td class="text-sm text-muted"><?= e($r['started_on'] ? fdate($r['started_on']) : '—') ?></td>
              <td class="num fw-600"><?= e(money($r['basic_salary'], false)) ?></td>
              <td>
                <span class="badge badge--<?= e($tone((string) $r['status'])) ?>">
                  <?= e(label_of((string) $r['status'])) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
