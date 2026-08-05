<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Controllers\LeadController;

$collected  = (float) $revenue['collected'];
$invoiced   = (float) $revenue['invoiced'];
$spent      = (float) $expenses['total'];
$margin     = $collected > 0 ? ($grossProfit / $collected) * 100 : 0;
$vatDue     = (float) $revenue['output_vat'] - (float) $expenses['input_vat'];

// Scale for the 12-month column chart.
$chartMax = 0.0;
foreach ($monthly as $m) {
    $chartMax = max($chartMax, (float) $m['paid'], (float) $m['expenses']);
}
$chartMax = $chartMax > 0 ? $chartMax : 1;

$maxClient = 0.0;
foreach ($topClients as $c) {
    $maxClient = max($maxClient, (float) $c['invoiced']);
}

$ageingRows = [
    'Not yet due'  => ['value' => (float) $ageing['not_due'],      'class' => ''],
    '1–30 days'    => ['value' => (float) $ageing['days_0_30'],    'class' => 'bar-row__fill--navy'],
    '31–60 days'   => ['value' => (float) $ageing['days_31_60'],   'class' => 'bar-row__fill--navy'],
    '61–90 days'   => ['value' => (float) $ageing['days_61_90'],   'class' => 'bar-row__fill--red'],
    'Over 90 days' => ['value' => (float) $ageing['days_90_plus'], 'class' => 'bar-row__fill--red'],
];
$maxAgeing = max(array_map(static fn($r) => $r['value'], $ageingRows)) ?: 1;

$pipelineByStage = [];
foreach ($pipeline as $p) {
    $pipelineByStage[$p['stage']] = $p;
}
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Reports</h1>
    <div class="page-head__sub">
      Money in, money out and what is driving both — <?= e(fdate($from)) ?> to <?= e(fdate($to)) ?>.
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/reports/statement' . query_string()) ?>">
      <?= icon('download') ?> Export statement
    </a>
    <button class="btn btn--outline" type="button" data-print><?= icon('printer') ?> Print</button>
  </div>
</div>

<div class="card no-print">
  <form class="filters" method="get" action="<?= url('/reports') ?>">
    <div class="field">
      <label class="label" for="from">From</label>
      <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
      <label class="label" for="to">To</label>
      <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <div class="field" style="display:flex;align-items:flex-end">
      <button class="btn btn--navy btn--sm" type="submit"><?= icon('filter') ?> Apply</button>
    </div>
    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/reports?from=' . date('Y-m-01') . '&to=' . date('Y-m-t')) ?>">This month</a>
    <a class="btn btn--ghost btn--sm" href="<?= url('/reports?from=' . date('Y-01-01') . '&to=' . date('Y-m-d')) ?>">This year</a>
  </form>
</div>

<div class="stat-grid">
  <div class="stat stat--green">
    <div class="stat__label">Collected</div>
    <div class="stat__value"><?= e(money_short($collected)) ?></div>
    <div class="stat__meta">Invoiced: <?= e(money_short($invoiced)) ?></div>
  </div>
  <div class="stat stat--red">
    <div class="stat__label">Expenses</div>
    <div class="stat__value"><?= e(money_short($spent)) ?></div>
    <div class="stat__meta"><?= (int) $expenses['count'] ?> entries</div>
  </div>
  <div class="stat <?= $grossProfit >= 0 ? 'stat--green' : 'stat--red' ?>">
    <div class="stat__label">Gross profit</div>
    <div class="stat__value"><?= e(money_short($grossProfit)) ?></div>
    <div class="stat__meta">
      <span class="<?= $margin >= 0 ? 'stat__delta--up' : 'stat__delta--down' ?>">
        <?= number_format($margin, 1) ?>% margin
      </span>
    </div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Outstanding</div>
    <div class="stat__value"><?= e(money_short($revenue['outstanding'])) ?></div>
    <div class="stat__meta"><?= (int) $revenue['invoice_count'] ?> invoice(s) raised</div>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <div>
      <div class="card__title">Cash collected vs expenses</div>
      <div class="card__sub">Rolling 12 months</div>
    </div>
  </div>
  <div class="card__body">
    <div class="chart-columns">
      <?php foreach ($monthly as $m):
          $paidPct = ((float) $m['paid'] / $chartMax) * 100;
          $expPct  = ((float) $m['expenses'] / $chartMax) * 100;
      ?>
        <div class="chart-col" title="<?= e($m['label'] . ' ' . $m['year']) ?>: collected <?= e(money($m['paid'])) ?>, spent <?= e(money($m['expenses'])) ?>">
          <div class="chart-col__stack" style="flex-direction:row;align-items:flex-end;gap:2px">
            <div class="chart-col__bar chart-col__bar--green" style="height:<?= number_format($paidPct, 2) ?>%"></div>
            <div class="chart-col__bar" style="height:<?= number_format($expPct, 2) ?>%;background:var(--red-600)"></div>
          </div>
          <div class="chart-col__label"><?= e($m['label']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="chart-legend">
      <span class="chart-legend__key">
        <span class="chart-legend__swatch" style="background:var(--green-600)"></span> Collected
      </span>
      <span class="chart-legend__key">
        <span class="chart-legend__swatch" style="background:var(--red-600)"></span> Expenses
      </span>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card__head">
      <div>
        <div class="card__title">Receivables ageing</div>
        <div class="card__sub">Outstanding invoice balances as at today</div>
      </div>
    </div>
    <div class="card__body">
      <div class="bars">
        <?php foreach ($ageingRows as $label => $row): ?>
          <div class="bar-row">
            <span class="bar-row__label"><?= e($label) ?></span>
            <span class="bar-row__track">
              <span class="bar-row__fill <?= e($row['class']) ?>"
                    style="width:<?= number_format(($row['value'] / $maxAgeing) * 100, 2) ?>%"></span>
            </span>
            <span class="bar-row__value"><?= e(money($row['value'], false)) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <div>
        <div class="card__title">Where expenses went</div>
        <div class="card__sub">Selected period</div>
      </div>
    </div>
    <div class="card__body">
      <?php if (!$expenseMix): ?>
        <p class="text-sm text-muted mb-0">No expenses recorded for this period.</p>
      <?php else: ?>
        <?php $maxExp = max(array_map(static fn($e) => (float) $e['total'], $expenseMix)) ?: 1; ?>
        <div class="bars">
          <?php foreach ($expenseMix as $ex): ?>
            <div class="bar-row">
              <span class="bar-row__label" title="<?= e($ex['name']) ?>"><?= e($ex['name']) ?></span>
              <span class="bar-row__track">
                <span class="bar-row__fill bar-row__fill--red"
                      style="width:<?= number_format(((float) $ex['total'] / $maxExp) * 100, 2) ?>%"></span>
              </span>
              <span class="bar-row__value"><?= e(money($ex['total'], false)) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card__head">
      <div>
        <div class="card__title">Top clients</div>
        <div class="card__sub">By value invoiced in the period</div>
      </div>
    </div>
    <?php if (!$topClients): ?>
      <div class="card__body"><p class="text-sm text-muted mb-0">No invoices in this period.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Client</th><th class="num">Invoices</th><th class="num">Invoiced</th><th class="num">Outstanding</th></tr></thead>
          <tbody>
            <?php foreach ($topClients as $c): ?>
              <tr>
                <td><a class="table__primary" href="<?= url('/clients/' . $c['id']) ?>"><?= e($c['name']) ?></a></td>
                <td class="num"><?= (int) $c['invoices'] ?></td>
                <td class="num fw-600"><?= e(money($c['invoiced'], false)) ?></td>
                <td class="num <?= (float) $c['outstanding'] > 0 ? 'text-red fw-600' : 'text-muted' ?>">
                  <?= (float) $c['outstanding'] > 0 ? e(money($c['outstanding'], false)) : '—' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head">
      <div>
        <div class="card__title">Best sellers</div>
        <div class="card__sub">Highest revenue line items</div>
      </div>
    </div>
    <?php if (!$topLines): ?>
      <div class="card__body"><p class="text-sm text-muted mb-0">No sales in this period.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Item</th><th>Type</th><th class="num">Qty</th><th class="num">Revenue</th></tr></thead>
          <tbody>
            <?php foreach ($topLines as $l): ?>
              <tr>
                <td><?= e(str_excerpt($l['description'], 46)) ?></td>
                <td>
                  <span class="badge <?= $l['item_type'] === 'service' ? 'badge--navy' : ($l['item_type'] === 'inventory' ? 'badge--green' : 'badge--grey') ?>">
                    <?= e(label_of($l['item_type'])) ?>
                  </span>
                </td>
                <td class="num"><?= e(qty($l['qty'])) ?></td>
                <td class="num fw-600"><?= e(money($l['revenue'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid-3">
  <div class="card">
    <div class="card__head"><div class="card__title">Revenue mix</div></div>
    <div class="card__body">
      <?php if (!$revenueMix): ?>
        <p class="text-sm text-muted mb-0">No data.</p>
      <?php else: ?>
        <?php $mixTotal = array_sum(array_map(static fn($r) => (float) $r['revenue'], $revenueMix)) ?: 1; ?>
        <?php foreach ($revenueMix as $r):
            $pct = ((float) $r['revenue'] / $mixTotal) * 100;
        ?>
          <div class="mb-12">
            <div class="flex justify-between text-sm mb-4">
              <span><?= e(label_of($r['item_type'])) ?></span>
              <span class="fw-600"><?= number_format($pct, 1) ?>%</span>
            </div>
            <div class="progress">
              <div class="progress__bar <?= $r['item_type'] === 'service' ? 'progress__bar--navy' : '' ?>"
                   style="width:<?= number_format($pct, 2) ?>%"></div>
            </div>
            <div class="text-xs text-muted mt-4"><?= e(money($r['revenue'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><div class="card__title">Payment methods</div></div>
    <div class="card__body">
      <?php if (!$paymentMethods): ?>
        <p class="text-sm text-muted mb-0">No payments in this period.</p>
      <?php else: ?>
        <table class="table table--compact" style="margin:-8px 0">
          <tbody>
            <?php foreach ($paymentMethods as $pm): ?>
              <tr>
                <td><?= e(label_of($pm['method'])) ?></td>
                <td class="num text-muted text-sm"><?= (int) $pm['count'] ?>×</td>
                <td class="num fw-600"><?= e(money($pm['total'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <div>
        <div class="card__title">VAT position</div>
        <div class="card__sub">Selected period</div>
      </div>
    </div>
    <div class="card__body">
      <dl class="dl">
        <dt>Output VAT</dt><dd class="fw-600"><?= e(money($revenue['output_vat'])) ?></dd>
        <dt>Input VAT</dt><dd class="fw-600"><?= e(money($expenses['input_vat'])) ?></dd>
        <dt><?= $vatDue >= 0 ? 'Payable' : 'Reclaimable' ?></dt>
        <dd class="fw-700 <?= $vatDue >= 0 ? 'text-red' : 'text-green' ?>"><?= e(money(abs($vatDue))) ?></dd>
      </dl>
      <p class="field-hint mt-8 mb-0">
        Indicative only, based on what has been captured in the system. Confirm against your KRA filing.
      </p>
    </div>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card__head">
      <div>
        <div class="card__title">Open pipeline</div>
        <div class="card__sub">Leads still in play</div>
      </div>
    </div>
    <div class="card__body">
      <?php $anyPipeline = false; ?>
      <?php foreach (LeadController::STAGES as $key => $stage): ?>
        <?php if (in_array($key, ['won', 'lost'], true)) continue; ?>
        <?php $row = $pipelineByStage[$key] ?? null; ?>
        <?php if ($row) $anyPipeline = true; ?>
        <div class="flex items-center justify-between mb-8">
          <span class="text-sm"><?= e($stage['label']) ?></span>
          <span class="text-sm">
            <span class="badge badge--grey"><?= (int) ($row['count'] ?? 0) ?></span>
            <span class="fw-600 ml-8"><?= e(money_short($row['value'] ?? 0)) ?></span>
          </span>
        </div>
      <?php endforeach; ?>
      <?php if (!$anyPipeline): ?>
        <p class="text-sm text-muted mb-0">No open leads.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <div>
        <div class="card__title">Sales team performance</div>
        <div class="card__sub">Leads created in the period</div>
      </div>
    </div>
    <?php if (!$leadPerformance): ?>
      <div class="card__body"><p class="text-sm text-muted mb-0">No assigned leads in this period.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Team member</th><th class="num">Leads</th><th class="num">Won</th>
                     <th class="num">Win rate</th><th class="num">Value won</th></tr></thead>
          <tbody>
            <?php foreach ($leadPerformance as $p):
                $closed = (int) $p['won'] + (int) $p['lost'];
                $rate   = $closed > 0 ? ((int) $p['won'] / $closed) * 100 : 0;
            ?>
              <tr>
                <td class="table__primary"><?= e($p['name']) ?></td>
                <td class="num"><?= (int) $p['total_leads'] ?></td>
                <td class="num text-green fw-600"><?= (int) $p['won'] ?></td>
                <td class="num"><?= number_format($rate, 0) ?>%</td>
                <td class="num fw-600"><?= e(money($p['won_value'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
