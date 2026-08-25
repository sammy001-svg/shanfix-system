<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= $mine ? 'My Reminders' : 'All Reminders' ?></h1>
    <div class="page-head__sub">Follow-ups on leads and clients, so nothing slips.</div>
  </div>
  <div class="page-head__actions">
    <div class="btn-group">
      <a class="btn <?= $mine ? 'btn--navy' : 'btn--outline' ?>"
         href="<?= e(query_string(['scope' => 'mine'])) ?>">Mine</a>
      <a class="btn <?= !$mine ? 'btn--navy' : 'btn--outline' ?>"
         href="<?= e(query_string(['scope' => 'all'])) ?>">Everyone</a>
    </div>
    <button class="btn btn--primary" type="button" data-modal-open="new-reminder"><?= icon('plus') ?> New reminder</button>
  </div>
</div>

<div class="stat-grid">
  <div class="stat <?= (int) $counts['overdue'] > 0 ? 'stat--red' : 'stat--green' ?>">
    <div class="stat__label">Overdue</div>
    <div class="stat__value"><?= (int) $counts['overdue'] ?></div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Due today</div>
    <div class="stat__value"><?= (int) $counts['today'] ?></div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Pending</div>
    <div class="stat__value"><?= (int) $counts['pending'] ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Completed</div>
    <div class="stat__value"><?= (int) $counts['done'] ?></div>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <?php foreach ([
        'pending'  => 'All pending',
        'overdue'  => 'Overdue',
        'today'    => 'Due today',
        'upcoming' => 'Upcoming',
        'done'     => 'Completed',
    ] as $key => $label): ?>
      <a class="tab <?= $filter === $key ? 'is-active' : '' ?>"
         href="<?= e(query_string(['filter' => $key])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$reminders): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('bell') ?></div>
      <div class="empty__title">Nothing here</div>
      <p class="empty__text">
        <?= $filter === 'overdue' ? 'No overdue follow-ups — nicely done.' : 'No reminders match this view.' ?>
      </p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th style="width:44px"></th><th>Reminder</th><th>Related to</th>
              <th>Due</th><th>Assigned to</th><th class="actions"></th></tr>
        </thead>
        <tbody>
          <?php foreach ($reminders as $r):
              $overdue = !$r['is_done'] && strtotime($r['remind_at']) < time();
          ?>
            <tr>
              <td>
                <?php if (!$r['is_done']): ?>
                  <form method="post" action="<?= url('/reminders/' . $r['id'] . '/done') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--outline btn--sm btn--icon" type="submit" title="Mark done">
                      <?= icon('check') ?>
                    </button>
                  </form>
                <?php else: ?>
                  <span class="text-green"><?= icon('check-circle') ?></span>
                <?php endif; ?>
              </td>
              <td>
                <div class="table__primary <?= $r['is_done'] ? 'text-muted' : '' ?>"
                     style="<?= $r['is_done'] ? 'text-decoration:line-through' : '' ?>">
                  <?= e($r['title']) ?>
                </div>
                <?php if ($r['notes']): ?>
                  <div class="table__muted"><?= e(str_excerpt($r['notes'], 70)) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-sm">
                <?php if ($r['lead_id']): ?>
                  <a href="<?= url('/leads/' . $r['lead_id']) ?>"><?= e($r['lead_name']) ?></a>
                  <div class="table__muted"><?= e($r['lead_number']) ?></div>
                <?php elseif ($r['client_id']): ?>
                  <a href="<?= url('/clients/' . $r['client_id']) ?>"><?= e($r['client_name']) ?></a>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-sm <?= $overdue ? 'text-red fw-600' : '' ?>">
                <?= e(fdatetime($r['remind_at'])) ?>
                <?php if ($overdue): ?><div class="text-xs">overdue</div><?php endif; ?>
              </td>
              <td class="text-sm"><?= e($r['user_name'] ?: '—') ?></td>
              <td class="actions">
                <?php if ($r['is_done']): ?>
                  <form method="post" action="<?= url('/reminders/' . $r['id'] . '/reopen') ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <button class="btn btn--outline btn--sm" type="submit" title="Reopen"><?= icon('refresh') ?></button>
                  </form>
                <?php endif; ?>
                <form method="post" action="<?= url('/reminders/' . $r['id'] . '/delete') ?>" style="display:inline"
                      data-confirm="Delete this reminder?">
                  <?= csrf_field() ?>
                  <button class="btn btn--danger-soft btn--sm" type="submit" aria-label="Delete this reminder"><?= icon('trash') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="table-foot"><span><?= count($reminders) ?> reminder(s)</span></div>
  <?php endif; ?>
</div>

<div class="modal-backdrop" id="new-reminder">
  <div class="modal">
    <form method="post" action="<?= url('/reminders') ?>">
      <?= csrf_field() ?>
      <div class="modal__head">
        <div class="modal__title">New reminder</div>
        <button class="modal__close" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
      </div>
      <div class="modal__body">
        <div class="field mb-12">
          <label class="label" for="nr_title">What do you need to do? <span class="req">*</span></label>
          <input class="input" id="nr_title" name="title" required maxlength="200"
                 placeholder="e.g. Chase Acme Ltd for artwork approval">
        </div>
        <div class="field mb-12">
          <label class="label" for="nr_when">When <span class="req">*</span></label>
          <input class="input" type="datetime-local" id="nr_when" name="remind_at" required
                 value="<?= e(date('Y-m-d\TH:i', strtotime('tomorrow 09:00'))) ?>">
        </div>
        <div class="field">
          <label class="label" for="nr_notes">Notes</label>
          <textarea class="textarea" id="nr_notes" name="notes" rows="3"></textarea>
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit"><?= icon('bell') ?> Add reminder</button>
      </div>
    </form>
  </div>
</div>
