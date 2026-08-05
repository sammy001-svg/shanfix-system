<?php
require_once APP_PATH . '/Views/partials/icons.php';

$priorityBadge = static fn(string $p): string => match ($p) {
    'urgent' => 'badge--red',
    'high'   => 'badge--amber',
    'low'    => 'badge--grey',
    default  => 'badge--navy',
};

/** Deadline pressure drives the card colour, not the stage. */
$dueState = static function (?string $due, string $stage): array {
    if (!$due || in_array($stage, ['delivered', 'cancelled'], true)) {
        return ['', ''];
    }

    $ts   = strtotime($due);
    $diff = $ts - time();

    if ($diff < 0)      return ['text-red fw-600',  'Overdue ' . time_ago($due)];
    if ($diff < 86400)  return ['text-red fw-600',  'Due ' . fdate($due, 'D H:i')];
    if ($diff < 259200) return ['text-amber fw-600','Due ' . fdate($due, 'D d M')];

    return ['text-muted', 'Due ' . fdate($due, 'd M')];
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Production</h1>
    <div class="page-head__sub">
      Job cards from artwork through printing to delivery.
    </div>
  </div>
  <div class="page-head__actions">
    <div class="btn-group">
      <a class="btn <?= $view === 'board' ? 'btn--navy' : 'btn--outline' ?>"
         href="<?= e(query_string(['view' => 'board'])) ?>"><?= icon('columns') ?> Board</a>
      <a class="btn <?= $view === 'list' ? 'btn--navy' : 'btn--outline' ?>"
         href="<?= e(query_string(['view' => 'list'])) ?>"><?= icon('list') ?> List</a>
    </div>
    <a class="btn btn--outline" href="<?= url('/delivery-notes') ?>"><?= icon('archive') ?> Delivery notes</a>
    <?php if (can('jobs.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/jobs/create') ?>"><?= icon('plus') ?> New job card</a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--navy">
    <div class="stat__label">Active jobs</div>
    <div class="stat__value"><?= (int) $summary['active'] ?></div>
    <div class="stat__meta">On the floor right now</div>
  </div>
  <div class="stat <?= (int) $summary['overdue'] > 0 ? 'stat--red' : 'stat--green' ?>">
    <div class="stat__label">Overdue</div>
    <div class="stat__value"><?= (int) $summary['overdue'] ?></div>
    <div class="stat__meta">Past their deadline</div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Due today</div>
    <div class="stat__value"><?= (int) $summary['due_today'] ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Ready for collection</div>
    <div class="stat__value"><?= (int) $summary['ready'] ?></div>
    <div class="stat__meta">
      <?php if ((int) $summary['on_hold'] > 0): ?>
        <span class="text-amber fw-600"><?= (int) $summary['on_hold'] ?> on hold</span>
      <?php else: ?>
        Nothing on hold
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/jobs') ?>">
    <input type="hidden" name="view" value="<?= e($view) ?>">

    <div class="field" style="min-width:220px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Job number, title or client" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="assigned">Assigned to</label>
      <select class="select" id="assigned" name="assigned" data-auto-submit>
        <option value="">Anyone</option>
        <option value="me"   <?= $filters['assigned'] === 'me'   ? 'selected' : '' ?>>My jobs</option>
        <option value="none" <?= $filters['assigned'] === 'none' ? 'selected' : '' ?>>Unassigned</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= $filters['assigned'] === (string) $u['id'] ? 'selected' : '' ?>>
            <?= e($u['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="priority">Priority</label>
      <select class="select" id="priority" name="priority" data-auto-submit>
        <option value="">All</option>
        <?php foreach ($priorities as $p): ?>
          <option value="<?= e($p) ?>" <?= $filters['priority'] === $p ? 'selected' : '' ?>><?= e(label_of($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" style="display:flex;align-items:flex-end;padding-bottom:4px">
      <label class="check">
        <input type="checkbox" name="done" value="1" <?= $showDone ? 'checked' : '' ?> data-auto-submit>
        <span class="check__text"><strong>Show finished</strong></span>
      </label>
    </div>

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/jobs?view=' . $view) ?>">Clear</a>
  </form>
</div>

<?php if (!$jobs): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__icon"><?= icon('printer') ?></div>
      <div class="empty__title">No jobs on the floor</div>
      <p class="empty__text">
        Open a job card from an invoice — or create one directly — to track artwork,
        proofs, printing and delivery.
      </p>
      <?php if (can('jobs.manage')): ?>
        <a class="btn btn--primary" href="<?= url('/jobs/create') ?>"><?= icon('plus') ?> New job card</a>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($view === 'board'): ?>

  <div class="kanban kanban--compact">
    <?php foreach ($stages as $key => $stage): ?>
      <?php if (!$stage['board']) continue; ?>
      <div class="kanban__col" data-stage="<?= e($key) ?>">
        <div class="kanban__head">
          <?= icon($stage['icon']) ?>
          <span class="kanban__title"><?= e($stage['label']) ?></span>
          <span class="kanban__count"><?= (int) $stageTotals[$key] ?></span>
        </div>

        <div class="kanban__body">
          <?php if (!$board[$key]): ?>
            <p class="text-xs text-muted text-center" style="padding:12px 0">—</p>
          <?php endif; ?>

          <?php foreach ($board[$key] as $job):
              [$dueClass, $dueLabel] = $dueState($job['due_date'], $job['stage']);
              $itemCount = (int) $job['item_count'];
              $itemDone  = (int) $job['item_done'];
              $progress  = $itemCount > 0 ? ($itemDone / $itemCount) * 100 : 0;
          ?>
            <a class="lead-card" href="<?= url('/jobs/' . $job['id']) ?>">
              <div class="flex items-center justify-between gap-6">
                <span class="text-xs text-muted nums"><?= e($job['job_number']) ?></span>
                <?php if ($job['priority'] !== 'normal'): ?>
                  <span class="badge <?= $priorityBadge($job['priority']) ?> text-xs"><?= e(label_of($job['priority'])) ?></span>
                <?php endif; ?>
              </div>

              <div class="lead-card__name mt-4"><?= e(str_excerpt($job['title'], 42)) ?></div>
              <div class="lead-card__co"><?= e($job['client_name']) ?></div>

              <?php if ((int) $job['proofs_waiting'] > 0): ?>
                <span class="badge badge--amber text-xs"><?= icon('clock') ?> Proof awaiting client</span>
              <?php endif; ?>

              <?php if ($itemCount > 0): ?>
                <div class="mt-8">
                  <div class="flex justify-between text-xs text-muted mb-4">
                    <span><?= $itemDone ?>/<?= $itemCount ?> items</span>
                    <span><?= number_format($progress, 0) ?>%</span>
                  </div>
                  <div class="progress"><div class="progress__bar" style="width:<?= number_format($progress, 2) ?>%"></div></div>
                </div>
              <?php endif; ?>

              <div class="lead-card__meta">
                <span class="text-xs <?= e($dueClass) ?>"><?= e($dueLabel ?: 'No deadline') ?></span>
                <?php if ($job['assignee_name']): ?>
                  <span class="avatar avatar--sm" style="background:<?= e($job['avatar_color']) ?>"
                        title="<?= e($job['assignee_name']) ?>"><?= e(initials($job['assignee_name'])) ?></span>
                <?php else: ?>
                  <span class="badge badge--grey text-xs">Unassigned</span>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($offBoard): ?>
    <div class="card mt-16">
      <div class="card__head">
        <?= icon('alert-triangle') ?>
        <div>
          <div class="card__title">On hold &amp; cancelled</div>
          <div class="card__sub"><?= count($offBoard) ?> job(s) not moving</div>
        </div>
      </div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Job</th><th>Client</th><th>State</th><th>Reason</th><th>Assigned</th></tr></thead>
          <tbody>
            <?php foreach ($offBoard as $job): ?>
              <tr>
                <td>
                  <a class="table__primary" href="<?= url('/jobs/' . $job['id']) ?>"><?= e($job['job_number']) ?></a>
                  <div class="table__muted"><?= e(str_excerpt($job['title'], 40)) ?></div>
                </td>
                <td class="text-sm"><?= e($job['client_name']) ?></td>
                <td><span class="badge <?= $job['stage'] === 'on_hold' ? 'badge--amber' : 'badge--red' ?>">
                  <?= e($stages[$job['stage']]['label']) ?></span></td>
                <td class="text-sm text-muted"><?= e($job['hold_reason'] ?: '—') ?></td>
                <td class="text-sm"><?= e($job['assignee_name'] ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

<?php else: ?>

  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Job</th><th>Client</th><th>Stage</th><th>Priority</th>
            <th>Progress</th><th>Deadline</th><th>Assigned</th><th class="actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($jobs as $job):
              [$dueClass, $dueLabel] = $dueState($job['due_date'], $job['stage']);
              $itemCount = (int) $job['item_count'];
              $itemDone  = (int) $job['item_done'];
              $progress  = $itemCount > 0 ? ($itemDone / $itemCount) * 100 : 0;
          ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/jobs/' . $job['id']) ?>"><?= e($job['job_number']) ?></a>
                <div class="table__muted"><?= e(str_excerpt($job['title'], 44)) ?></div>
              </td>
              <td class="text-sm"><?= e($job['client_name']) ?></td>
              <td>
                <span class="badge <?= $job['stage'] === 'delivered' ? 'badge--green'
                    : ($job['stage'] === 'cancelled' ? 'badge--red'
                    : ($job['stage'] === 'on_hold' ? 'badge--amber' : 'badge--navy')) ?>">
                  <?= e($stages[$job['stage']]['label']) ?>
                </span>
              </td>
              <td><span class="badge <?= $priorityBadge($job['priority']) ?>"><?= e(label_of($job['priority'])) ?></span></td>
              <td style="min-width:110px">
                <?php if ($itemCount > 0): ?>
                  <div class="text-xs text-muted mb-4"><?= $itemDone ?>/<?= $itemCount ?></div>
                  <div class="progress"><div class="progress__bar" style="width:<?= number_format($progress, 2) ?>%"></div></div>
                <?php else: ?>
                  <span class="text-muted text-sm">—</span>
                <?php endif; ?>
              </td>
              <td class="text-sm <?= e($dueClass) ?>"><?= e($dueLabel ?: '—') ?></td>
              <td>
                <?php if ($job['assignee_name']): ?>
                  <div class="flex items-center gap-6">
                    <span class="avatar avatar--sm" style="background:<?= e($job['avatar_color']) ?>">
                      <?= e(initials($job['assignee_name'])) ?>
                    </span>
                    <span class="text-sm"><?= e($job['assignee_name']) ?></span>
                  </div>
                <?php else: ?>
                  <span class="text-muted text-sm">Unassigned</span>
                <?php endif; ?>
              </td>
              <td class="actions">
                <a class="btn btn--outline btn--sm" href="<?= url('/jobs/' . $job['id']) ?>"><?= icon('eye') ?></a>
                <a class="btn btn--outline btn--sm" href="<?= url('/jobs/' . $job['id'] . '/print') ?>"
                   target="_blank" rel="noopener"><?= icon('printer') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="table-foot"><span><?= count($jobs) ?> job(s)</span></div>
  </div>

<?php endif; ?>
