<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Leads</h1>
    <div class="page-head__sub">Track every enquiry from first contact through to a closed deal.</div>
  </div>
  <div class="page-head__actions">
    <div class="btn-group">
      <a class="btn <?= $view === 'board' ? 'btn--navy' : 'btn--outline' ?>"
         href="<?= e(query_string(['view' => 'board'])) ?>"><?= icon('columns') ?> Board</a>
      <a class="btn <?= $view === 'list' ? 'btn--navy' : 'btn--outline' ?>"
         href="<?= e(query_string(['view' => 'list'])) ?>"><?= icon('list') ?> List</a>
    </div>
    <?php if (can('leads.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/leads/create') ?>"><?= icon('plus') ?> New lead</a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--navy">
    <div class="stat__label">Open leads</div>
    <div class="stat__value"><?= (int) $summary['open_leads'] ?></div>
    <div class="stat__meta"><?= (int) $summary['total'] ?> total on record</div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Pipeline value</div>
    <div class="stat__value"><?= e(money_short($summary['pipeline_value'])) ?></div>
    <div class="stat__meta">Estimated value still in play</div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Won</div>
    <div class="stat__value"><?= (int) $summary['won'] ?></div>
    <div class="stat__meta">
      <?= e(money_short($summary['won_value'])) ?> ·
      <?php
        $closed = (int) $summary['won'] + (int) $summary['lost'];
        $winRate = $closed > 0 ? ((int) $summary['won'] / $closed) * 100 : 0;
      ?>
      <?= number_format($winRate, 0) ?>% win rate
    </div>
  </div>
  <div class="stat <?= $overdueFollowups > 0 ? 'stat--red' : 'stat--amber' ?>">
    <div class="stat__label">Overdue follow-ups</div>
    <div class="stat__value"><?= (int) $overdueFollowups ?></div>
    <div class="stat__meta"><a href="<?= url('/reminders') ?>">View reminders</a></div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/leads') ?>">
    <input type="hidden" name="view" value="<?= e($view) ?>">

    <div class="field" style="min-width:230px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Name, company or phone" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="assigned">Assigned to</label>
      <select class="select" id="assigned" name="assigned" data-auto-submit>
        <option value="">Anyone</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $filters['assigned'] === (int) $u['id'] ? 'selected' : '' ?>>
            <?= e($u['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="source">Source</label>
      <select class="select" id="source" name="source" data-auto-submit>
        <option value="">All sources</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= e($s) ?>" <?= $filters['source'] === $s ? 'selected' : '' ?>><?= e(label_of($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/leads?view=' . $view) ?>">Clear</a>
  </form>
</div>

<?php if (!$leads): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__icon"><?= icon('target') ?></div>
      <div class="empty__title">No leads yet</div>
      <p class="empty__text">
        Register every enquiry — walk-ins, referrals, website forms — link it to the service
        they need, and work it through the pipeline until the deal closes.
      </p>
      <?php if (can('leads.manage')): ?>
        <a class="btn btn--primary" href="<?= url('/leads/create') ?>"><?= icon('plus') ?> Register a lead</a>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($view === 'board'): ?>

  <div class="kanban">
    <?php foreach ($stages as $key => $stage): ?>
      <div class="kanban__col" data-stage="<?= e($key) ?>">
        <div class="kanban__head">
          <span class="kanban__title"><?= e($stage['label']) ?></span>
          <span class="kanban__count"><?= count($board[$key]) ?></span>
        </div>
        <div class="kanban__value"><?= e(money_short($stageValues[$key])) ?></div>

        <div class="kanban__body">
          <?php if (!$board[$key]): ?>
            <p class="text-xs text-muted text-center" style="padding:12px 0">No leads</p>
          <?php endif; ?>

          <?php foreach ($board[$key] as $lead):
              $overdue = $lead['next_reminder'] && strtotime($lead['next_reminder']) < time();
          ?>
            <a class="lead-card" href="<?= url('/leads/' . $lead['id']) ?>">
              <div class="lead-card__name"><?= e($lead['name']) ?></div>
              <div class="lead-card__co">
                <?= e($lead['company'] ?: label_of($lead['source'])) ?>
              </div>

              <?php if ($lead['service_name']): ?>
                <span class="badge badge--navy text-xs"><?= e(str_excerpt($lead['service_name'], 24)) ?></span>
              <?php endif; ?>

              <div class="lead-card__meta">
                <span class="lead-card__value"><?= e(money_short($lead['estimated_value'])) ?></span>
                <?php if ($lead['assignee_name']): ?>
                  <span class="avatar avatar--sm" style="background:<?= e($lead['avatar_color']) ?>"
                        title="<?= e($lead['assignee_name']) ?>">
                    <?= e(initials($lead['assignee_name'])) ?>
                  </span>
                <?php endif; ?>
              </div>

              <?php if ($lead['next_reminder']): ?>
                <div class="text-xs mt-4 <?= $overdue ? 'lead-card__overdue' : 'text-muted' ?>">
                  <?= $overdue ? 'Follow-up overdue' : 'Follow up ' . e(fdate($lead['next_reminder'], 'd M')) ?>
                </div>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php else: ?>

  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Lead</th><th>Service</th><th>Source</th>
            <th class="num">Est. value</th><th>Stage</th>
            <th>Assigned</th><th>Next follow-up</th><th class="actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($leads as $lead):
              $overdue = $lead['next_reminder'] && strtotime($lead['next_reminder']) < time();
          ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/leads/' . $lead['id']) ?>"><?= e($lead['name']) ?></a>
                <div class="table__muted">
                  <?= e($lead['company'] ?: $lead['lead_number']) ?>
                  <?= $lead['phone'] ? ' · ' . e($lead['phone']) : '' ?>
                </div>
              </td>
              <td class="text-sm"><?= e($lead['service_name'] ?: '—') ?></td>
              <td class="text-sm text-muted"><?= e(label_of($lead['source'])) ?></td>
              <td class="num fw-600"><?= e(money($lead['estimated_value'], false)) ?></td>
              <td>
                <span class="badge <?= status_badge($lead['stage']) ?>">
                  <?= e($stages[$lead['stage']]['label'] ?? label_of($lead['stage'])) ?>
                </span>
              </td>
              <td>
                <?php if ($lead['assignee_name']): ?>
                  <div class="flex items-center gap-6">
                    <span class="avatar avatar--sm" style="background:<?= e($lead['avatar_color']) ?>">
                      <?= e(initials($lead['assignee_name'])) ?>
                    </span>
                    <span class="text-sm"><?= e($lead['assignee_name']) ?></span>
                  </div>
                <?php else: ?>
                  <span class="text-muted text-sm">Unassigned</span>
                <?php endif; ?>
              </td>
              <td class="text-sm <?= $overdue ? 'text-red fw-600' : '' ?>">
                <?= $lead['next_reminder'] ? e(fdate($lead['next_reminder'], 'd M Y')) : '—' ?>
              </td>
              <td class="actions">
                <a class="btn btn--outline btn--sm" href="<?= url('/leads/' . $lead['id']) ?>"><?= icon('eye') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="table-foot"><span><?= count($leads) ?> lead(s)</span></div>
  </div>

<?php endif; ?>
