<?php
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div style="margin-bottom:12px; font-size:13px;">
  <a href="<?= url('/reports') ?>" style="text-decoration:none;">&larr; Back to Financial Reports</a>
</div>

<div class="page-head">
  <div class="page-head__text">
    <h1>Receivables Ageing Report</h1>
    <div class="page-head__sub">
      Drill-down of all unpaid invoices grouped by client and days overdue
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--secondary" href="<?= url('/reports/ageing/export') ?>">
      <?= icon('download') ?> Export CSV
    </a>
  </div>
</div>

<!-- Stat Cards by Ageing Bucket -->
<div class="stat-grid mb-24" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
  <a href="<?= url('/reports/ageing?bucket=all') ?>" class="stat <?= $bucket === 'all' ? 'stat--blue' : '' ?>" style="text-decoration:none;">
    <div class="stat__value">KES <?= number_format((float) ($totals['total_outstanding'] ?? 0), 2) ?></div>
    <div class="stat__label">Total Receivables</div>
  </a>

  <a href="<?= url('/reports/ageing?bucket=not_due') ?>" class="stat <?= $bucket === 'not_due' ? 'stat--green' : '' ?>" style="text-decoration:none;">
    <div class="stat__value">KES <?= number_format((float) ($totals['not_due'] ?? 0), 2) ?></div>
    <div class="stat__label">Not Due Yet</div>
  </a>

  <a href="<?= url('/reports/ageing?bucket=0_30') ?>" class="stat <?= $bucket === '0_30' ? 'stat--amber' : '' ?>" style="text-decoration:none;">
    <div class="stat__value">KES <?= number_format((float) ($totals['days_0_30'] ?? 0), 2) ?></div>
    <div class="stat__label">1 – 30 Days Overdue</div>
  </a>

  <a href="<?= url('/reports/ageing?bucket=31_60') ?>" class="stat <?= $bucket === '31_60' ? 'stat--amber' : '' ?>" style="text-decoration:none;">
    <div class="stat__value">KES <?= number_format((float) ($totals['days_31_60'] ?? 0), 2) ?></div>
    <div class="stat__label">31 – 60 Days Overdue</div>
  </a>

  <a href="<?= url('/reports/ageing?bucket=61_90') ?>" class="stat <?= $bucket === '61_90' ? 'stat--red' : '' ?>" style="text-decoration:none;">
    <div class="stat__value">KES <?= number_format((float) ($totals['days_61_90'] ?? 0), 2) ?></div>
    <div class="stat__label">61 – 90 Days Overdue</div>
  </a>

  <a href="<?= url('/reports/ageing?bucket=90_plus') ?>" class="stat <?= $bucket === '90_plus' ? 'stat--red' : '' ?>" style="text-decoration:none;">
    <div class="stat__value">KES <?= number_format((float) ($totals['days_90_plus'] ?? 0), 2) ?></div>
    <div class="stat__label">90+ Days Overdue</div>
  </a>
</div>

<!-- Table Card -->
<div class="card">
  <div class="card__head" style="display:flex; justify-content:space-between; align-items:center;">
    <h3>Client Breakdown (<?= count($clients) ?> clients with open balances)</h3>
    <div style="display:flex; gap:6px;">
      <a class="btn btn--sm <?= $bucket === 'all' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('/reports/ageing?bucket=all') ?>">All</a>
      <a class="btn btn--sm <?= $bucket === 'not_due' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('/reports/ageing?bucket=not_due') ?>">Current</a>
      <a class="btn btn--sm <?= $bucket === '0_30' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('/reports/ageing?bucket=0_30') ?>">1-30d</a>
      <a class="btn btn--sm <?= $bucket === '31_60' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('/reports/ageing?bucket=31_60') ?>">31-60d</a>
      <a class="btn btn--sm <?= $bucket === '61_90' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('/reports/ageing?bucket=61_90') ?>">61-90d</a>
      <a class="btn btn--sm <?= $bucket === '90_plus' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('/reports/ageing?bucket=90_plus') ?>">90d+</a>
    </div>
  </div>
  <div class="card__body" style="padding:0;">
    <?php if (!empty($clients)): ?>
      <table class="table">
        <thead>
          <tr>
            <th>Client Name</th>
            <th style="text-align:right;">Not Due</th>
            <th style="text-align:right;">1 – 30 Days</th>
            <th style="text-align:right;">31 – 60 Days</th>
            <th style="text-align:right;">61 – 90 Days</th>
            <th style="text-align:right;">90+ Days</th>
            <th style="text-align:right;">Total Balance</th>
            <th style="text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clients as $c): ?>
            <tr>
              <td>
                <a href="<?= url('/clients/' . $c['id']) ?>" style="font-weight:600; text-decoration:none;">
                  <?= esc($c['name']) ?>
                </a>
                <div style="font-size:11px; color:var(--text-muted);">
                  <?= (int) $c['unpaid_invoices'] ?> unpaid <?= $c['unpaid_invoices'] == 1 ? 'invoice' : 'invoices' ?>
                  <?php if (!empty($c['phone'])): ?>
                     · <?= esc($c['phone']) ?>
                  <?php endif; ?>
                </div>
              </td>

              <!-- Not Due -->
              <td style="text-align:right; font-size:13px;">
                <?= (float) $c['not_due'] > 0 ? 'KES ' . number_format((float) $c['not_due'], 2) : '—' ?>
              </td>

              <!-- 1 - 30 days -->
              <td style="text-align:right; font-size:13px; <?= (float) $c['days_0_30'] > 0 ? 'color:var(--amber-color, #d97706); font-weight:600;' : '' ?>">
                <?= (float) $c['days_0_30'] > 0 ? 'KES ' . number_format((float) $c['days_0_30'], 2) : '—' ?>
              </td>

              <!-- 31 - 60 days -->
              <td style="text-align:right; font-size:13px; <?= (float) $c['days_31_60'] > 0 ? 'color:var(--amber-color, #d97706); font-weight:600;' : '' ?>">
                <?= (float) $c['days_31_60'] > 0 ? 'KES ' . number_format((float) $c['days_31_60'], 2) : '—' ?>
              </td>

              <!-- 61 - 90 days -->
              <td style="text-align:right; font-size:13px; <?= (float) $c['days_61_90'] > 0 ? 'color:var(--danger-color, #dc2626); font-weight:600;' : '' ?>">
                <?= (float) $c['days_61_90'] > 0 ? 'KES ' . number_format((float) $c['days_61_90'], 2) : '—' ?>
              </td>

              <!-- 90+ days -->
              <td style="text-align:right; font-size:13px; <?= (float) $c['days_90_plus'] > 0 ? 'color:var(--danger-color, #dc2626); font-weight:700;' : '' ?>">
                <?= (float) $c['days_90_plus'] > 0 ? 'KES ' . number_format((float) $c['days_90_plus'], 2) : '—' ?>
              </td>

              <!-- Total -->
              <td style="text-align:right; font-weight:700; font-size:14px;">
                KES <?= number_format((float) $c['total_outstanding'], 2) ?>
              </td>

              <!-- Actions -->
              <td style="text-align:center;">
                <div style="display:flex; justify-content:center; gap:4px;">
                  <a class="btn btn--sm btn--ghost" href="<?= url('/clients/' . $c['id']) ?>" title="View Profile">Profile</a>
                  <a class="btn btn--sm btn--secondary" href="<?= url('/clients/' . $c['id'] . '/statement') ?>" title="Statement">Statement</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p style="padding:24px; text-align:center; color:var(--text-muted); font-size:14px; margin:0;">
        No clients match the selected ageing bucket filter.
      </p>
    <?php endif; ?>
  </div>
</div>
