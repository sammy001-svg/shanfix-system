<?php
require_once APP_PATH . '/Views/partials/icons.php';

$tab = static fn(string $w): string => url('/meetings') . query_string(['when' => $w, 'page' => null]);
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Meetings</h1>
    <div class="page-head__sub">Schedule, meet, and keep a record of what was agreed.</div>
  </div>
  <?php if (can('meetings.manage')): ?>
    <div class="page-head__actions">
      <a class="btn btn--primary" href="<?= url('/meetings/create') ?>"><?= icon('plus') ?> Schedule a meeting</a>
    </div>
  <?php endif; ?>
</div>

<div class="stat-grid">
  <div class="stat stat--navy">
    <div class="stat__label">Coming up</div>
    <div class="stat__value"><?= (int) $summary['upcoming'] ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Today</div>
    <div class="stat__value"><?= (int) $summary['today'] ?></div>
  </div>
  <div class="stat <?= (int) $summary['live'] > 0 ? 'stat--amber' : 'stat--grey' ?>">
    <div class="stat__label">Happening now</div>
    <div class="stat__value"><?= (int) $summary['live'] ?></div>
    <div class="stat__meta"><?= (int) $summary['live'] > 0 ? 'Rooms are open' : 'Nothing running' ?></div>
  </div>
  <div class="stat stat--grey">
    <div class="stat__label">Held</div>
    <div class="stat__value"><?= (int) $summary['held'] ?></div>
    <div class="stat__meta">With minutes on file</div>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <a class="tab <?= $filters['when'] === 'upcoming' ? 'is-active' : '' ?>" href="<?= e($tab('upcoming')) ?>">
      <?= icon('calendar') ?> Coming up
    </a>
    <a class="tab <?= $filters['when'] === 'past' ? 'is-active' : '' ?>" href="<?= e($tab('past')) ?>">
      <?= icon('check-circle') ?> Past
    </a>
    <a class="tab <?= $filters['when'] === 'cancelled' ? 'is-active' : '' ?>" href="<?= e($tab('cancelled')) ?>">
      <?= icon('x') ?> Cancelled
    </a>
  </nav>

  <form class="filters" method="get" action="<?= url('/meetings') ?>">
    <input type="hidden" name="when" value="<?= e($filters['when']) ?>">
    <div class="field" style="min-width:240px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Title or agenda" data-debounce-submit>
    </div>
    <div class="field">
      <label class="label" for="mine">Show</label>
      <select class="select" id="mine" name="mine" data-auto-submit>
        <option value="">Everyone's</option>
        <option value="1" <?= $filters['mine'] ? 'selected' : '' ?>>Only mine</option>
      </select>
    </div>
    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/meetings') ?>">Clear</a>
  </form>

  <?php if (!$meetings): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('calendar') ?></div>
      <div class="empty__title">Nothing here</div>
      <p class="empty__text">
        Schedule a meeting and everyone invited — colleagues and clients alike —
        gets a link and a reminder before it starts.
      </p>
      <?php if (can('meetings.manage')): ?>
        <a class="btn btn--primary" href="<?= url('/meetings/create') ?>"><?= icon('plus') ?> Schedule a meeting</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Meeting</th>
            <th>When</th>
            <th>Host</th>
            <th class="num">Invited</th>
            <th>Status</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($meetings as $m):
              $ts   = strtotime($m['scheduled_at']);
              $mins = (int) round(($ts - time()) / 60);

              if ($m['status'] === 'in_progress')    { $tone = 'green'; $label = 'Live now'; }
              elseif ($m['status'] === 'cancelled')  { $tone = 'grey';  $label = 'Cancelled'; }
              elseif ($m['status'] === 'ended')      { $tone = 'navy';  $label = 'Held'; }
              elseif ($mins < 0)                     { $tone = 'amber'; $label = 'Overdue'; }
              elseif ($mins <= 60)                   { $tone = 'amber'; $label = 'In ' . $mins . ' min'; }
              else                                   { $tone = 'grey';  $label = 'Scheduled'; }
          ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/meetings/' . $m['id']) ?>"><?= e($m['title']) ?></a>
                <div class="table__muted">
                  <?php if ($m['client_name']): ?>With <?= e($m['client_name']) ?> · <?php endif; ?>
                  <?= (int) $m['duration_mins'] ?> min
                  <?php if ((int) $m['note_count'] > 0): ?>
                    · <?= (int) $m['note_count'] ?> note(s)
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-sm">
                <?= e(fdate($m['scheduled_at'])) ?>
                <div class="table__muted"><?= e(date('H:i', $ts)) ?></div>
              </td>
              <td class="text-sm"><?= e($m['host_name'] ?: '—') ?></td>
              <td class="num"><?= (int) $m['invited'] ?></td>
              <td><span class="badge badge--<?= e($tone) ?>"><?= e($label) ?></span></td>
              <td class="actions">
                <?php if (in_array($m['status'], ['scheduled', 'in_progress'], true)): ?>
                  <a class="btn btn--primary btn--sm" href="<?= url('/meetings/' . $m['id'] . '/room') ?>"
                     target="_blank" rel="noopener">
                    <?= icon('video') ?> Join
                  </a>
                <?php endif; ?>
                <a class="btn btn--outline btn--sm" href="<?= url('/meetings/' . $m['id']) ?>"><?= icon('eye') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($meetings) ?> of <?= number_format($pager['total']) ?> meeting(s)</span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
