<?php
require_once APP_PATH . '/Views/partials/icons.php';

$ts   = strtotime($meeting['scheduled_at']);
$mins = (int) round(($ts - time()) / 60);
$live = $meeting['status'] === 'in_progress';
$over = in_array($meeting['status'], ['ended', 'cancelled'], true);
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="text-xs text-muted mb-4">
      <a href="<?= url('/meetings') ?>">Meetings</a>
      <?php if ($client): ?> / <a href="<?= url('/clients/' . $client['id']) ?>"><?= e($client['name']) ?></a><?php endif; ?>
    </div>
    <h1><?= e($meeting['title']) ?></h1>
    <div class="page-head__sub">
      <?= e(fdate($meeting['scheduled_at'])) ?> at <?= e(date('H:i', $ts)) ?>
      · <?= (int) $meeting['duration_mins'] ?> minutes
      <?php if ($host): ?> · hosted by <?= e($host['name']) ?><?php endif; ?>
    </div>
  </div>

  <div class="page-head__actions">
    <?php if (!$over): ?>
      <a class="btn btn--primary" href="<?= url('/meetings/' . $meeting['id'] . '/room') ?>"
         target="_blank" rel="noopener">
        <?= icon('video') ?> <?= $live ? 'Join the room' : 'Open the room' ?>
      </a>
    <?php endif; ?>

    <?php if (can('meetings.manage') && !$over): ?>
      <a class="btn btn--outline" href="<?= url('/meetings/' . $meeting['id'] . '/edit') ?>">
        <?= icon('edit') ?> Edit
      </a>

      <?php if ($live): ?>
        <form method="post" action="<?= url('/meetings/' . $meeting['id'] . '/end') ?>" style="display:inline"
              data-confirm="Close this meeting? The room will no longer be reachable.">
          <?= csrf_field() ?>
          <button class="btn btn--outline" type="submit"><?= icon('check') ?> End meeting</button>
        </form>
      <?php else: ?>
        <form method="post" action="<?= url('/meetings/' . $meeting['id'] . '/start') ?>" style="display:inline">
          <?= csrf_field() ?>
          <button class="btn btn--outline" type="submit"><?= icon('video') ?> Start</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($meeting['status'] === 'cancelled'): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">This meeting was cancelled. Anyone holding the link will be told so.</div>
  </div>
<?php endif; ?>

<div class="grid-sidebar">
  <div>
    <?php if ($meeting['agenda']): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Agenda</div></div>
        <div class="card__body"><p style="white-space:pre-line"><?= e($meeting['agenda']) ?></p></div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <?= icon('edit') ?>
        <div>
          <div class="card__title">What was said</div>
          <div class="card__sub">Typed live during the meeting, in the order it happened.</div>
        </div>
      </div>

      <?php if (!$notes): ?>
        <div class="empty">
          <div class="empty__icon"><?= icon('edit') ?></div>
          <div class="empty__title">Nothing noted yet</div>
          <p class="empty__text">Notes taken in the room appear here.</p>
        </div>
      <?php else: ?>
        <div class="card__body">
          <?php foreach ($notes as $n): ?>
            <div class="note note--<?= e($n['kind']) ?>" style="margin-bottom:12px">
              <div class="note__meta">
                <span class="note__who"><?= e($n['author_name']) ?></span>
                <span class="note__at">
                  <?php if ($n['kind'] !== 'note'): ?>
                    <span class="badge badge--<?= $n['kind'] === 'decision' ? 'green' : 'amber' ?>">
                      <?= e(label_of($n['kind'])) ?>
                    </span>
                  <?php endif; ?>
                  <?= e(date('H:i', strtotime($n['created_at']))) ?>
                </span>
              </div>
              <div class="note__body"><?= nl2br(e($n['body'])) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (can('meetings.manage')): ?>
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Minutes</div>
            <div class="card__sub">The tidied-up record, kept with the meeting.</div>
          </div>
        </div>
        <div class="card__body">
          <form method="post" action="<?= url('/meetings/' . $meeting['id'] . '/minutes') ?>">
            <?= csrf_field() ?>
            <textarea class="textarea" name="minutes" rows="10"
                      placeholder="Write up what was agreed…"><?= e($meeting['minutes']) ?></textarea>
            <div class="flex items-center gap-12 mt-8">
              <button class="btn btn--primary btn--sm" type="submit"><?= icon('check') ?> Save minutes</button>
              <?php if ($meeting['minutes_updated_at']): ?>
                <span class="text-xs text-muted">
                  Last saved <?= e(fdatetime($meeting['minutes_updated_at'])) ?>
                </span>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    <?php elseif ($meeting['minutes']): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">Minutes</div></div>
        <div class="card__body"><p style="white-space:pre-line"><?= e($meeting['minutes']) ?></p></div>
      </div>
    <?php endif; ?>
  </div>

  <aside>
    <?php if (!$over && $meeting['allow_guests']): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('external-link') ?>
          <div>
            <div class="card__title">Share this link</div>
            <div class="card__sub">Anyone with it can join — nothing to install.</div>
          </div>
        </div>
        <div class="card__body">
          <input class="input" type="text" readonly data-select-on-focus aria-label="Meeting link"
                 value="<?= e($joinUrl) ?>">
          <div class="text-xs text-muted mt-8">
            Click to select, then copy. It is included in the reminders automatically.
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <?= icon('users') ?>
        <div>
          <div class="card__title">Invited</div>
          <div class="card__sub"><?= count($participants) ?> person(s)</div>
        </div>
      </div>
      <div class="card__body">
        <?php foreach ($participants as $p): ?>
          <div class="siterow">
            <div class="siterow__main">
              <span class="siterow__name">
                <?= e($p['user_name'] ?: $p['name']) ?>
                <?php if ($p['invite_role'] === 'host'): ?>
                  <span class="badge badge--navy">Host</span>
                <?php endif; ?>
              </span>
              <div class="text-xs text-muted">
                <?= $p['user_id'] ? 'Staff' : 'Guest' ?>
                <?php if ($p['email']): ?> · <?= e(str_excerpt($p['email'], 26)) ?><?php endif; ?>
              </div>
            </div>
            <span class="siterow__side">
              <?php if ($p['joined_at']): ?>
                <span class="badge badge--green">Joined</span>
              <?php else: ?>
                <span class="badge badge--grey">Invited</span>
              <?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (can('meetings.manage') && !$over): ?>
      <div class="card">
        <div class="card__body flex gap-8">
          <form method="post" action="<?= url('/meetings/' . $meeting['id'] . '/cancel') ?>"
                data-confirm="Cancel this meeting? People holding the link will be told it is off.">
            <?= csrf_field() ?>
            <button class="btn btn--outline btn--sm" type="submit"><?= icon('x') ?> Cancel meeting</button>
          </form>

          <?php if (can('meetings.delete')): ?>
            <form method="post" action="<?= url('/meetings/' . $meeting['id'] . '/delete') ?>"
                  data-confirm="Delete this meeting and its minutes for good?">
              <?= csrf_field() ?>
              <button class="btn btn--danger-soft btn--sm" type="submit"><?= icon('trash') ?> Delete</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>
