<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Human Resources & Fleet</h1>
    <div class="page-head__sub">
      Unified dashboard for staff management, payroll readiness, and equipment health
    </div>
  </div>
  <div class="page-head__actions" style="display:flex; gap:8px;">
    <?php if (Auth::can('hr.manage')): ?>
      <a class="btn btn--secondary" href="<?= url('/staff/new') ?>">
        <?= icon('user-plus') ?> Add Staff
      </a>
    <?php endif; ?>
    <?php if (Auth::can('payroll.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/payroll') ?>">
        <?= icon('briefcase') ?> Payroll Portal
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid mb-24">
  <a href="<?= url('/staff?status=active') ?>" class="stat stat--green" style="text-decoration:none;">
    <div class="stat__value"><?= (int) ($staffCounts['active_count'] ?? 0) ?></div>
    <div class="stat__label">Active Staff</div>
    <div class="stat__sub" style="font-size:12px; color:var(--text-muted); margin-top:4px;">
      <?= (int) ($staffCounts['on_leave_count'] ?? 0) ?> on leave · <?= (int) ($staffCounts['suspended_count'] ?? 0) ?> suspended
    </div>
  </a>

  <a href="<?= url('/payroll') ?>" class="stat <?= $currentRun ? 'stat--green' : 'stat--amber' ?>" style="text-decoration:none;">
    <div class="stat__value" style="font-size:18px; font-weight:700; margin-bottom:4px;">
      <?= date('F Y') ?>
    </div>
    <div class="stat__label">
      Status: <strong><?= $currentRun ? strtoupper($currentRun['status']) : 'NOT STARTED' ?></strong>
    </div>
    <div class="stat__sub" style="font-size:12px; color:var(--text-muted); margin-top:4px;">
      <?= $currentRun ? 'KES ' . number_format((float) $currentRun['total_net'], 2) . ' net total' : 'Click to run monthly payroll' ?>
    </div>
  </a>

  <a href="<?= url('/equipment?due=1') ?>" class="stat <?= ($equipmentStats['due_service'] ?? 0) > 0 ? 'stat--amber' : '' ?>" style="text-decoration:none;">
    <div class="stat__value"><?= (int) ($equipmentStats['due_service'] ?? 0) ?></div>
    <div class="stat__label">Equipment Service Due</div>
    <div class="stat__sub" style="font-size:12px; color:var(--text-muted); margin-top:4px;">
      Within next <?= (int) $warnDays ?> days
    </div>
  </a>

  <a href="<?= url('/equipment') ?>" class="stat" style="text-decoration:none;">
    <div class="stat__value"><?= (int) ($equipmentStats['total_active'] ?? 0) ?></div>
    <div class="stat__label">Total Fleet / Assets</div>
    <div class="stat__sub" style="font-size:12px; color:var(--text-muted); margin-top:4px;">
      <?= (int) ($equipmentStats['under_repair'] ?? 0) ?> under repair
    </div>
  </a>
</div>

<div class="grid grid--2col gap-24">
  <!-- Left Column -->
  <div style="display:flex; flex-direction:column; gap:24px;">
    <!-- Payroll Status Card -->
    <div class="card">
      <div class="card__head" style="display:flex; justify-content:space-between; align-items:center;">
        <h3><?= icon('briefcase') ?> Payroll Run Overview</h3>
        <a class="btn btn--sm btn--ghost" href="<?= url('/payroll') ?>">View All Runs &rarr;</a>
      </div>
      <div class="card__body">
        <?php if ($latestPayrollRun): ?>
          <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:16px;">
            <div style="background:var(--bg-subtle, #f8fafc); padding:12px; border-radius:6px;">
              <div style="font-size:12px; color:var(--text-muted);">Latest Run Period</div>
              <div style="font-size:16px; font-weight:600;"><?= esc($latestPayrollRun['period']) ?></div>
              <div style="font-size:11px; margin-top:2px;">
                <span class="badge badge--<?= match($latestPayrollRun['status']) { 'paid' => 'green', 'approved' => 'blue', default => 'amber' } ?>">
                  <?= strtoupper($latestPayrollRun['status']) ?>
                </span>
              </div>
            </div>
            <div style="background:var(--bg-subtle, #f8fafc); padding:12px; border-radius:6px;">
              <div style="font-size:12px; color:var(--text-muted);">Gross / Net Total</div>
              <div style="font-size:16px; font-weight:600;">KES <?= number_format((float) $latestPayrollRun['total_net'], 2) ?></div>
              <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                <?= (int) $latestPayrollRun['employee_count'] ?> employees paid
              </div>
            </div>
          </div>
          <div style="font-size:13px; color:var(--text-muted);">
            Prepared by <strong><?= esc($latestPayrollRun['created_by_name'] ?? 'System') ?></strong> on <?= fdate($latestPayrollRun['created_at']) ?>
          </div>
        <?php else: ?>
          <p style="color:var(--text-muted); font-size:14px; margin:0;">No payroll runs recorded yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Staff Department & Type Distribution -->
    <div class="card">
      <div class="card__head" style="display:flex; justify-content:space-between; align-items:center;">
        <h3><?= icon('users') ?> Staff Distribution</h3>
        <a class="btn btn--sm btn--ghost" href="<?= url('/staff') ?>">Staff Directory &rarr;</a>
      </div>
      <div class="card__body">
        <div style="margin-bottom:16px;">
          <div style="font-weight:600; font-size:13px; margin-bottom:8px;">By Department</div>
          <div style="display:flex; flex-wrap:wrap; gap:8px;">
            <?php foreach ($departmentBreakdown as $d): ?>
              <span style="background:var(--bg-subtle, #f1f5f9); border:1px solid var(--border-color, #e2e8f0); padding:4px 10px; border-radius:20px; font-size:12px;">
                <strong><?= esc($d['dept']) ?></strong>: <?= (int) $d['cnt'] ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <div style="font-weight:600; font-size:13px; margin-bottom:8px;">By Employment Type</div>
          <div style="display:flex; flex-wrap:wrap; gap:8px;">
            <?php foreach ($typeBreakdown as $t): ?>
              <span class="badge badge--blue">
                <?= ucfirst(esc($t['employment_type'])) ?>: <?= (int) $t['cnt'] ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Work Anniversaries This Month -->
    <?php if (!empty($anniversaries)): ?>
      <div class="card">
        <div class="card__head">
          <h3><?= icon('calendar') ?> Work Anniversaries (<?= date('F') ?>)</h3>
        </div>
        <div class="card__body">
          <ul style="list-style:none; padding:0; margin:0;">
            <?php foreach ($anniversaries as $a): ?>
              <li style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border-color, #e2e8f0);">
                <div>
                  <a href="<?= url('/staff/' . $a['id']) ?>" style="font-weight:600; text-decoration:none;">
                    <?= esc($a['name']) ?>
                  </a>
                  <div style="font-size:12px; color:var(--text-muted);"><?= esc($a['job_title'] ?? 'Staff') ?></div>
                </div>
                <div style="text-align:right;">
                  <span class="badge badge--green"><?= (int) $a['years_served'] ?> <?= $a['years_served'] == 1 ? 'Year' : 'Years' ?></span>
                  <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">Joined <?= fdate($a['started_on']) ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Right Column -->
  <div style="display:flex; flex-direction:column; gap:24px;">
    <!-- Equipment Due for Service -->
    <div class="card">
      <div class="card__head" style="display:flex; justify-content:space-between; align-items:center;">
        <h3><?= icon('package') ?> Equipment Service Watchlist</h3>
        <a class="btn btn--sm btn--ghost" href="<?= url('/equipment') ?>">All Equipment &rarr;</a>
      </div>
      <div class="card__body" style="padding:0;">
        <?php if (!empty($equipmentDue)): ?>
          <table class="table">
            <thead>
              <tr>
                <th>Machine</th>
                <th>Assigned To</th>
                <th>Service Due</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($equipmentDue as $eq): ?>
                <tr>
                  <td>
                    <a href="<?= url('/equipment/' . $eq['id']) ?>" style="font-weight:600; text-decoration:none;">
                      <?= esc($eq['name']) ?>
                    </a>
                    <div style="font-size:11px; color:var(--text-muted);"><?= esc($eq['asset_code']) ?></div>
                  </td>
                  <td><?= esc($eq['assignee'] ?? 'Unassigned') ?></td>
                  <td>
                    <span class="badge badge--amber"><?= fdate($eq['next_service_on']) ?></span>
                  </td>
                  <td>
                    <a class="btn btn--sm btn--ghost" href="<?= url('/equipment/' . $eq['id']) ?>">View</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p style="padding:16px; color:var(--text-muted); font-size:14px; margin:0;">
            <?= icon('check-circle', 'text-green') ?> All machinery is up to date with services.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <!-- HR & Equipment Activity Log -->
    <div class="card">
      <div class="card__head">
        <h3><?= icon('clock') ?> Recent HR & Fleet Activity</h3>
      </div>
      <div class="card__body" style="padding:0;">
        <?php if (!empty($recentLogs)): ?>
          <table class="table">
            <tbody>
              <?php foreach ($recentLogs as $log): ?>
                <tr>
                  <td style="font-size:13px;">
                    <strong><?= esc($log['user_name'] ?? 'System') ?></strong>
                    <span><?= esc($log['summary']) ?></span>
                    <div style="font-size:11px; color:var(--text-muted);"><?= fdate($log['created_at'], true) ?></div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p style="padding:16px; color:var(--text-muted); font-size:14px; margin:0;">No recent HR activity recorded.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
