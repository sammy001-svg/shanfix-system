<?php
/**
 * HR and fleet at a glance.
 *
 * Staff, whether this month's payroll has been run, and which machines
 * are due a service — the three things somebody running the place asks
 * about before opening anything.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Human Resources &amp; Fleet</h1>
    <div class="page-head__sub">
      Staff, payroll readiness and equipment health in one place
    </div>
  </div>
  <div class="page-head__actions">
    <?php if (Auth::can('hr.manage')): ?>
      <a class="btn btn--outline" href="<?= url('/staff/new') ?>">
        <?= icon('user-plus') ?> Add staff
      </a>
    <?php endif; ?>
    <?php if (Auth::can('payroll.view')): ?>
      <a class="btn btn--primary" href="<?= url('/payroll') ?>">
        <?= icon('briefcase') ?> Payroll
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid mb-16">
  <a class="stat stat--green" href="<?= url('/staff?status=active') ?>">
    <div class="stat__label">Active staff</div>
    <div class="stat__value"><?= (int) ($staffCounts['active_count'] ?? 0) ?></div>
    <div class="stat__meta">
      <?= (int) ($staffCounts['on_leave_count'] ?? 0) ?> on leave ·
      <?= (int) ($staffCounts['suspended_count'] ?? 0) ?> suspended
    </div>
  </a>

  <a class="stat <?= $currentRun ? 'stat--green' : 'stat--amber' ?>" href="<?= url('/payroll') ?>">
    <div class="stat__label"><?= e(date('F Y')) ?> payroll</div>
    <div class="stat__value" style="font-size:17px">
      <?= $currentRun ? e(strtoupper($currentRun['status'])) : 'NOT STARTED' ?>
    </div>
    <div class="stat__meta">
      <?= $currentRun
          ? 'KES ' . number_format((float) $currentRun['total_net'], 2) . ' net'
          : 'Run this month\'s payroll' ?>
    </div>
  </a>

  <a class="stat <?= ($equipmentStats['due_service'] ?? 0) > 0 ? 'stat--amber' : '' ?>"
     href="<?= url('/equipment?due=1') ?>">
    <div class="stat__label">Equipment service due</div>
    <div class="stat__value"><?= (int) ($equipmentStats['due_service'] ?? 0) ?></div>
    <div class="stat__meta">Within <?= (int) $warnDays ?> days</div>
  </a>

  <a class="stat" href="<?= url('/equipment') ?>">
    <div class="stat__label">Fleet and assets</div>
    <div class="stat__value"><?= (int) ($equipmentStats['total_active'] ?? 0) ?></div>
    <div class="stat__meta"><?= (int) ($equipmentStats['under_repair'] ?? 0) ?> under repair</div>
  </a>
</div>

<div class="grid-2">
  <?php // ── Left ────────────────────────────────────────────────────── ?>
  <div class="hr-col">

    <div class="card">
      <div class="card__head">
        <div class="card__title"><?= icon('briefcase') ?> Payroll runs</div>
        <a class="btn btn--ghost btn--sm" href="<?= url('/payroll') ?>">All runs</a>
      </div>
      <div class="card__body">
        <?php if ($latestPayrollRun): ?>
          <div class="hr-pair">
            <div class="hr-tile">
              <div class="hr-tile__label">Latest run</div>
              <div class="hr-tile__value"><?= e($latestPayrollRun['period']) ?></div>
              <span class="badge badge--<?= match ($latestPayrollRun['status']) {
                  'paid'     => 'green',
                  'approved' => 'blue',
                  default    => 'amber',
              } ?>"><?= e(strtoupper($latestPayrollRun['status'])) ?></span>
            </div>

            <div class="hr-tile">
              <div class="hr-tile__label">Net total</div>
              <div class="hr-tile__value">KES <?= number_format((float) $latestPayrollRun['total_net'], 2) ?></div>
              <div class="hr-tile__meta">
                <?= (int) $latestPayrollRun['employee_count'] ?> employees paid
              </div>
            </div>
          </div>

          <p class="text-sm text-muted mt-8">
            Prepared by <strong><?= e($latestPayrollRun['created_by_name'] ?? 'System') ?></strong>
            on <?= e(fdate($latestPayrollRun['created_at'])) ?>
          </p>
        <?php else: ?>
          <p class="text-sm text-muted">No payroll runs recorded yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <div class="card__title"><?= icon('users') ?> Staff distribution</div>
        <a class="btn btn--ghost btn--sm" href="<?= url('/staff') ?>">Directory</a>
      </div>
      <div class="card__body">
        <?php if ($departmentBreakdown): ?>
          <div class="label">By department</div>
          <div class="badge-set mb-16">
            <?php foreach ($departmentBreakdown as $d): ?>
              <span class="badge badge--grey">
                <?= e($d['dept'] ?? 'Unassigned') ?>: <?= (int) $d['cnt'] ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($typeBreakdown): ?>
          <div class="label">By employment type</div>
          <div class="badge-set">
            <?php foreach ($typeBreakdown as $t): ?>
              <?php // A blank is possible: employment_type is an enum, and
                    // MySQL outside strict mode stores '' for a value that
                    // is not one of them. Better named than a badge reading
                    // ": 1", which tells nobody anything. ?>
              <span class="badge badge--blue">
                <?= e(ucfirst((string) $t['employment_type']) ?: 'Unspecified') ?>: <?= (int) $t['cnt'] ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!$departmentBreakdown && !$typeBreakdown): ?>
          <p class="text-sm text-muted">Nobody on the staff list yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($anniversaries)): ?>
      <div class="card">
        <div class="card__head">
          <div class="card__title"><?= icon('calendar') ?> Work anniversaries in <?= e(date('F')) ?></div>
        </div>
        <div class="card__body">
          <ul class="hr-list">
            <?php foreach ($anniversaries as $a): ?>
              <li class="hr-list__row">
                <div>
                  <a href="<?= url('/staff/' . $a['id']) ?>"><strong><?= e($a['name']) ?></strong></a>
                  <div class="text-xs text-muted"><?= e($a['job_title'] ?? 'Staff') ?></div>
                </div>
                <div class="text-right">
                  <span class="badge badge--green">
                    <?= (int) $a['years_served'] ?> <?= (int) $a['years_served'] === 1 ? 'year' : 'years' ?>
                  </span>
                  <div class="text-xs text-muted mt-4">Joined <?= e(fdate($a['started_on'])) ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php // ── Right ───────────────────────────────────────────────────── ?>
  <div class="hr-col">

    <div class="card">
      <div class="card__head">
        <div class="card__title"><?= icon('package') ?> Equipment service watchlist</div>
        <a class="btn btn--ghost btn--sm" href="<?= url('/equipment') ?>">All equipment</a>
      </div>
      <div class="card__body card__body--flush">
        <?php if (!empty($equipmentDue)): ?>
          <table class="table">
            <thead>
              <tr>
                <th>Machine</th>
                <th>Assigned to</th>
                <th>Service due</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($equipmentDue as $eq): ?>
                <tr>
                  <td>
                    <a href="<?= url('/equipment/' . $eq['id']) ?>"><strong><?= e($eq['name']) ?></strong></a>
                    <div class="text-xs text-muted"><?= e($eq['asset_code'] ?? '') ?></div>
                  </td>
                  <td><?= e($eq['assignee'] ?? 'Unassigned') ?></td>
                  <td><span class="badge badge--amber"><?= e(fdate($eq['next_service_on'])) ?></span></td>
                  <td class="actions">
                    <a class="btn btn--ghost btn--sm" href="<?= url('/equipment/' . $eq['id']) ?>">View</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p class="text-sm text-muted p-16">
            <?= icon('check-circle') ?> Every machine is up to date with its service.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <div class="card__title"><?= icon('clock') ?> Recent HR and fleet activity</div>
      </div>
      <div class="card__body card__body--flush">
        <?php if (!empty($recentLogs)): ?>
          <table class="table">
            <tbody>
              <?php foreach ($recentLogs as $log): ?>
                <tr>
                  <td>
                    <strong><?= e($log['user_name'] ?? 'System') ?></strong>
                    <?= e($log['description'] ?? $log['action']) ?>
                    <div class="text-xs text-muted"><?= e(fdatetime($log['created_at'])) ?></div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p class="text-sm text-muted p-16">No recent HR activity recorded.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
