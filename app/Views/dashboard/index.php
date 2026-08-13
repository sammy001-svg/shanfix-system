<?php
require_once APP_PATH . '/Views/partials/icons.php';

$me = auth();

$collectedMonth = (float) $money['collected_month'];
$expensesMonth  = (float) $money['expenses_month'];
$profitMonth    = $collectedMonth - $expensesMonth;

$delta = $lastMonthCollected > 0
    ? (($collectedMonth - $lastMonthCollected) / $lastMonthCollected) * 100
    : null;

$trendMax = 1.0;
foreach ($trend as $t) {
    $trendMax = max($trendMax, $t['income'], $t['expenses']);
}

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($greeting) ?>, <?= e(explode(' ', $me['name'])[0]) ?></h1>
    <div class="page-head__sub"><?= e(date('l, j F Y')) ?> · here is where the business stands.</div>
  </div>
  <div class="page-head__actions">
    <?php if (can('leads.manage')): ?>
      <a class="btn btn--outline" href="<?= url('/leads/create') ?>"><?= icon('target') ?> New lead</a>
    <?php endif; ?>
    <?php if (can('documents.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/invoices/create') ?>"><?= icon('receipt') ?> New invoice</a>
    <?php endif; ?>
  </div>
</div>

<?php
  // What the company earns, is owed and keeps is not everyone's business.
  // Each tile below carries the permission that matches what it discloses:
  //   payments.view  — money in and money owed
  //   expenses.view  — the cost base, and so the margin
  // Production and general staff hold neither, and see the operational
  // counters underneath instead of the company's financial position.
  $showMoneyIn  = can('payments.view');
  $showMargin   = can('expenses.view');
?>

<?php if ($showMoneyIn || $showMargin): ?>
  <div class="stat-grid">
    <?php if ($showMoneyIn): ?>
      <div class="stat stat--green">
        <div class="stat__label">Collected this month</div>
        <div class="stat__value"><?= e(money_short($collectedMonth)) ?></div>
        <div class="stat__meta">
          <?php if ($delta !== null): ?>
            <span class="<?= $delta >= 0 ? 'stat__delta--up' : 'stat__delta--down' ?>">
              <?= $delta >= 0 ? '▲' : '▼' ?> <?= number_format(abs($delta), 1) ?>%
            </span>
            vs last month
          <?php else: ?>
            <?= e(money_short($money['collected_today'])) ?> today
          <?php endif; ?>
        </div>
      </div>

      <div class="stat <?= (float) $money['outstanding'] > 0 ? 'stat--red' : 'stat--green' ?>">
        <div class="stat__label">Outstanding</div>
        <div class="stat__value"><?= e(money_short($money['outstanding'])) ?></div>
        <div class="stat__meta">
          <?php if ((float) $money['overdue_value'] > 0): ?>
            <span class="text-red fw-600"><?= e(money_short($money['overdue_value'])) ?> overdue</span>
          <?php else: ?>
            Nothing overdue
          <?php endif; ?>
        </div>
      </div>

      <div class="stat stat--navy">
        <div class="stat__label">Invoiced this month</div>
        <div class="stat__value"><?= e(money_short($money['invoiced_month'])) ?></div>
        <div class="stat__meta"><?= e(date('F Y')) ?></div>
      </div>
    <?php endif; ?>

    <?php if ($showMargin): ?>
      <div class="stat <?= $profitMonth >= 0 ? 'stat--green' : 'stat--red' ?>">
        <div class="stat__label">Net this month</div>
        <div class="stat__value"><?= e(money_short($profitMonth)) ?></div>
        <div class="stat__meta">After <?= e(money_short($expensesMonth)) ?> expenses</div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="row row--tight mb-24">
  <?php
    $tiles = [];

    if (can('leads.view')) {
        $tiles[] = ['leads', 'target', (int) $counts['open_leads'], 'Open leads', ''];
    }
    if (can('clients.view')) {
        $tiles[] = ['clients', 'users', (int) $counts['clients'], 'Active clients', ''];
    }
    if (can('documents.view')) {
        $tiles[] = ['quotations', 'file-text', (int) $counts['open_quotes'], 'Open quotations', ''];
        $tiles[] = ['invoices?status=overdue', 'alert-triangle', (int) $counts['overdue_invoices'], 'Overdue invoices', 'text-red'];
    }
    if (can('jobs.view')) {
        $tiles[] = ['jobs', 'printer', (int) $jobCounts['active'], 'Jobs on the floor', ''];
        if ((int) $jobCounts['overdue'] > 0) {
            $tiles[] = ['jobs', 'clock', (int) $jobCounts['overdue'], 'Jobs past deadline', 'text-red'];
        }
    }
    if (can('inventory.view')) {
        $tiles[] = ['inventory?stock=low', 'package', (int) $counts['low_stock'], 'Low stock items', 'text-amber'];
    }
  ?>

  <?php foreach ($tiles as [$path, $iconName, $value, $label, $cls]): ?>
    <a class="col card" href="<?= url('/' . $path) ?>" style="min-width:170px;text-decoration:none;margin-bottom:0">
      <div class="card__body flex items-center gap-12">
        <span class="empty__icon" style="width:38px;height:38px;margin:0;flex:0 0 38px">
          <?= icon($iconName) ?>
        </span>
        <span>
          <span class="stat__value <?= e($cls) ?>" style="font-size:19px;display:block"><?= $value ?></span>
          <span class="text-xs text-muted"><?= e($label) ?></span>
        </span>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<div class="grid-sidebar">
  <div>
    <?php if (can('reports.view')): ?>
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Cash in vs out</div>
            <div class="card__sub">Last six months</div>
          </div>
          <div class="card__actions">
            <a class="btn btn--ghost btn--sm" href="<?= url('/reports') ?>">Full reports</a>
          </div>
        </div>
        <div class="card__body">
          <div class="chart-columns">
            <?php foreach ($trend as $t): ?>
              <div class="chart-col"
                   title="<?= e($t['label']) ?>: in <?= e(money($t['income'])) ?>, out <?= e(money($t['expenses'])) ?>">
                <div class="chart-col__stack" style="flex-direction:row;align-items:flex-end;gap:3px">
                  <div class="chart-col__bar chart-col__bar--green"
                       style="height:<?= number_format(($t['income'] / $trendMax) * 100, 2) ?>%"></div>
                  <div class="chart-col__bar"
                       style="height:<?= number_format(($t['expenses'] / $trendMax) * 100, 2) ?>%;background:var(--red-600)"></div>
                </div>
                <div class="chart-col__label"><?= e($t['label']) ?></div>
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
    <?php endif; ?>

    <?php if (can('documents.view') && $overdueInvoices): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('alert-triangle') ?>
          <div>
            <div class="card__title">Needs chasing</div>
            <div class="card__sub">Overdue invoices, oldest first</div>
          </div>
          <div class="card__actions">
            <a class="btn btn--ghost btn--sm" href="<?= url('/invoices') ?>">All invoices</a>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Invoice</th><th>Client</th><th>Due</th><th class="num">Balance</th><th class="actions"></th></tr></thead>
            <tbody>
              <?php foreach ($overdueInvoices as $inv):
                  $days = (int) floor((time() - strtotime($inv['due_date'])) / 86400);
              ?>
                <tr>
                  <td><a class="table__primary" href="<?= url('/invoices/' . $inv['id']) ?>"><?= e($inv['doc_number']) ?></a></td>
                  <td class="text-sm"><a href="<?= url('/clients/' . $inv['client_id']) ?>"><?= e($inv['client_name']) ?></a></td>
                  <td class="text-sm text-red fw-600"><?= $days ?>d overdue</td>
                  <td class="num fw-700 text-red"><?= e(money($inv['balance'], false)) ?></td>
                  <td class="actions">
                    <a class="btn btn--outline btn--sm" href="<?= url('/invoices/' . $inv['id']) ?>"><?= icon('eye') ?></a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if (can('jobs.view') && $urgentJobs): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('printer') ?>
          <div>
            <div class="card__title">On the floor</div>
            <div class="card__sub">Highest priority and nearest deadline first</div>
          </div>
          <div class="card__actions">
            <a class="btn btn--ghost btn--sm" href="<?= url('/jobs') ?>">Job board</a>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Job</th><th>Client</th><th>Stage</th><th>Deadline</th><th>Assigned</th></tr></thead>
            <tbody>
              <?php foreach ($urgentJobs as $j):
                  $late = $j['due_date'] && strtotime($j['due_date']) < time();
              ?>
                <tr>
                  <td>
                    <a class="table__primary" href="<?= url('/jobs/' . $j['id']) ?>"><?= e($j['job_number']) ?></a>
                    <div class="table__muted"><?= e(str_excerpt($j['title'], 38)) ?></div>
                  </td>
                  <td class="text-sm"><?= e($j['client_name']) ?></td>
                  <td>
                    <span class="badge <?= $j['stage'] === 'on_hold' ? 'badge--amber' : 'badge--navy' ?>">
                      <?= e(\App\Controllers\JobController::STAGES[$j['stage']]['label'] ?? label_of($j['stage'])) ?>
                    </span>
                  </td>
                  <td class="text-sm <?= $late ? 'text-red fw-600' : '' ?>">
                    <?= $j['due_date'] ? e(fdate($j['due_date'], 'D d M, H:i')) : '—' ?>
                    <?php if ($late): ?><div class="text-xs">overdue</div><?php endif; ?>
                  </td>
                  <td class="text-sm"><?= e($j['assignee_name'] ?: 'Unassigned') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if (can('leads.view') && $hotLeads): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('zap') ?>
          <div>
            <div class="card__title">Deals close to landing</div>
            <div class="card__sub">Qualified, proposal and negotiation stages</div>
          </div>
          <div class="card__actions">
            <a class="btn btn--ghost btn--sm" href="<?= url('/leads') ?>">Pipeline</a>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Lead</th><th>Service</th><th>Stage</th><th class="num">Value</th><th>Owner</th></tr></thead>
            <tbody>
              <?php foreach ($hotLeads as $l): ?>
                <tr>
                  <td>
                    <a class="table__primary" href="<?= url('/leads/' . $l['id']) ?>"><?= e($l['name']) ?></a>
                    <?php if ($l['company']): ?>
                      <div class="table__muted"><?= e($l['company']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-sm text-muted"><?= e(str_excerpt($l['service_name'], 26) ?: '—') ?></td>
                  <td><span class="badge <?= status_badge($l['stage']) ?>"><?= e(label_of($l['stage'])) ?></span></td>
                  <td class="num fw-700 text-green"><?= e(money($l['estimated_value'], false)) ?></td>
                  <td class="text-sm"><?= e($l['assignee_name'] ?: '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if (can('payments.view') && $recentPayments): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('credit-card') ?>
          <div>
            <div class="card__title">Money in</div>
            <div class="card__sub">Most recent payments</div>
          </div>
          <div class="card__actions">
            <a class="btn btn--ghost btn--sm" href="<?= url('/payments') ?>">All payments</a>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Receipt</th><th>Client</th><th>Method</th><th>When</th><th class="num">Amount</th></tr></thead>
            <tbody>
              <?php foreach ($recentPayments as $p): ?>
                <tr>
                  <td class="table__primary"><?= e($p['payment_number']) ?></td>
                  <td class="text-sm"><a href="<?= url('/clients/' . $p['client_id']) ?>"><?= e($p['client_name']) ?></a></td>
                  <td>
                    <span class="badge <?= str_starts_with($p['method'], 'mpesa') ? 'badge--green' : 'badge--navy' ?>">
                      <?= e(label_of($p['method'])) ?>
                    </span>
                  </td>
                  <td class="text-sm text-muted"><?= e(time_ago($p['paid_at'] ?: $p['created_at'])) ?></td>
                  <td class="num fw-700 text-green"><?= e(money($p['amount'], false)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <aside>
    <div class="card">
      <div class="card__head">
        <?= icon('bell') ?>
        <div class="card__title">Your follow-ups</div>
        <div class="card__actions">
          <a class="btn btn--ghost btn--sm" href="<?= url('/reminders') ?>">All</a>
        </div>
      </div>

      <?php if (!$myReminders): ?>
        <div class="card__body">
          <p class="text-sm text-muted mb-0">Nothing outstanding — you are all caught up.</p>
        </div>
      <?php else: ?>
        <div class="card__body">
          <?php foreach ($myReminders as $r):
              $overdue = strtotime($r['remind_at']) < time();
              $today   = date('Y-m-d', strtotime($r['remind_at'])) === date('Y-m-d');
          ?>
            <div class="flex items-start gap-8 mb-12">
              <form method="post" action="<?= url('/reminders/' . $r['id'] . '/done') ?>" style="margin-top:2px">
                <?= csrf_field() ?>
                <button class="btn btn--outline btn--sm btn--icon" type="submit" title="Mark done">
                  <?= icon('check') ?>
                </button>
              </form>
              <div class="flex-1" style="min-width:0">
                <div class="text-sm fw-600"><?= e(str_excerpt($r['title'], 46)) ?></div>
                <div class="text-xs <?= $overdue ? 'text-red fw-600' : ($today ? 'text-amber' : 'text-muted') ?>">
                  <?= $overdue ? 'Overdue · ' : ($today ? 'Today · ' : '') ?><?= e(fdatetime($r['remind_at'])) ?>
                </div>
                <?php if ($r['lead_id']): ?>
                  <a class="text-xs" href="<?= url('/leads/' . $r['lead_id']) ?>"><?= e($r['lead_name']) ?></a>
                <?php elseif ($r['client_id']): ?>
                  <a class="text-xs" href="<?= url('/clients/' . $r['client_id']) ?>"><?= e($r['client_name']) ?></a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (can('inventory.view') && $lowStock): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('package') ?>
          <div class="card__title">Restock soon</div>
          <div class="card__actions">
            <a class="btn btn--ghost btn--sm" href="<?= url('/inventory?stock=low') ?>">View</a>
          </div>
        </div>
        <div class="card__body--flush">
          <?php foreach ($lowStock as $item): ?>
            <a class="conv" href="<?= url('/inventory/' . $item['id']) ?>">
              <span class="conv__meta">
                <span class="conv__name"><?= e(str_excerpt($item['name'], 30)) ?></span>
                <span class="conv__preview"><?= e($item['sku']) ?></span>
              </span>
              <span class="conv__right">
                <span class="badge <?= (float) $item['quantity'] <= 0 ? 'badge--red' : 'badge--amber' ?>">
                  <?= e(qty($item['quantity'])) ?> <?= e($item['unit']) ?>
                </span>
                <span class="conv__time">reorder at <?= e(qty($item['reorder_level'])) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head"><div class="card__title">Quick actions</div></div>
      <div class="card__body">
        <?php if (can('clients.manage')): ?>
          <a class="btn btn--outline btn--block mb-8" href="<?= url('/clients/create') ?>">
            <?= icon('user-plus') ?> Register a client
          </a>
        <?php endif; ?>
        <?php if (can('documents.manage')): ?>
          <a class="btn btn--outline btn--block mb-8" href="<?= url('/quotations/create') ?>">
            <?= icon('file-text') ?> Create a quotation
          </a>
        <?php endif; ?>
        <?php if (can('payments.manage')): ?>
          <a class="btn btn--outline btn--block mb-8" href="<?= url('/payments/create') ?>">
            <?= icon('credit-card') ?> Record a payment
          </a>
        <?php endif; ?>
        <?php if (can('expenses.manage')): ?>
          <a class="btn btn--outline btn--block" href="<?= url('/expenses/create') ?>">
            <?= icon('trending-down') ?> Record an expense
          </a>
        <?php endif; ?>
      </div>
    </div>
  </aside>
</div>
