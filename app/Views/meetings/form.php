<?php
require_once APP_PATH . '/Views/partials/icons.php';

$errors = $errors ?? [];
$val = static function (string $field, $default = '') use ($meeting) {
    $old = \App\Core\Session::old($field, null);
    if ($old !== null) return $old;
    if ($meeting && array_key_exists($field, $meeting)) return $meeting[$field];
    return $default;
};

$action = $meeting ? url('/meetings/' . $meeting['id']) : url('/meetings');

// datetime-local wants "2026-08-20T14:30"; the database holds a space.
$when = (string) $val('scheduled_at', date('Y-m-d H:i', strtotime('+1 day 10:00')));
$when = str_replace(' ', 'T', substr($when, 0, 16));

$alreadyIn = array_column(array_filter($invited, static fn($p) => $p['user_id'] !== null), 'user_id');
$guests    = array_values(array_filter($invited, static fn($p) => $p['user_id'] === null));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= $meeting ? 'Edit ' . e($meeting['title']) : 'Schedule a meeting' ?></h1>
    <div class="page-head__sub">Invite colleagues and clients. Everyone gets a link and a reminder.</div>
  </div>
</div>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">What and when</div></div>
        <div class="card__body">

          <div class="field">
            <label class="label" for="title">Title <span class="req">*</span></label>
            <input class="input <?= isset($errors['title']) ? 'has-error' : '' ?>" type="text"
                   id="title" name="title" required maxlength="200"
                   value="<?= e($val('title')) ?>"
                   placeholder="e.g. Website review with Acme">
            <?= error_for($errors, 'title') ?>
          </div>

          <div class="row">
            <div class="col field">
              <label class="label" for="scheduled_at">Starts <span class="req">*</span></label>
              <input class="input <?= isset($errors['scheduled_at']) ? 'has-error' : '' ?>"
                     type="datetime-local" id="scheduled_at" name="scheduled_at" required
                     value="<?= e($when) ?>">
              <?= error_for($errors, 'scheduled_at') ?>
            </div>

            <div class="col field">
              <label class="label" for="duration_mins">Runs for</label>
              <select class="select" id="duration_mins" name="duration_mins">
                <?php foreach ([15, 30, 45, 60, 90, 120] as $mins): ?>
                  <option value="<?= $mins ?>" <?= (int) $val('duration_mins', 30) === $mins ? 'selected' : '' ?>>
                    <?= $mins ?> minutes
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="field">
            <label class="label" for="agenda">Agenda</label>
            <textarea class="textarea" id="agenda" name="agenda" rows="4"
                      placeholder="What needs covering…"><?= e($val('agenda')) ?></textarea>
          </div>

          <div class="field">
            <label class="label" for="client_id">About a client</label>
            <select class="select" id="client_id" name="client_id">
              <option value="">Not client-specific</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $val('client_id') === (int) $c['id'] ? 'selected' : '' ?>>
                  <?= e($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <?= icon('users') ?>
          <div>
            <div class="card__title">Who is coming</div>
            <div class="card__sub">Colleagues by name; anyone else by email or phone.</div>
          </div>
        </div>
        <div class="card__body">

          <div class="field">
            <label class="label">Colleagues</label>
            <div class="checkgrid">
              <?php foreach ($staff as $u): ?>
                <label class="check-row">
                  <input type="checkbox" name="user_ids[]" value="<?= (int) $u['id'] ?>"
                         <?= in_array((int) $u['id'], array_map('intval', $alreadyIn), true) ? 'checked' : '' ?>>
                  <span><?= e($u['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <hr>

          <div class="field">
            <label class="label">People outside the company</label>
            <div class="text-xs text-muted mb-8">
              They join from a link — no account and nothing to install. An email
              address or a phone number is needed so the reminder can reach them.
            </div>

            <div data-guest-rows>
              <?php
                // One blank row more than there are guests, so there is always
                // somewhere to type without pressing "add" first.
                $rows = $guests;
                $rows[] = ['name' => '', 'email' => '', 'phone' => ''];
              ?>
              <?php foreach ($rows as $g): ?>
                <div class="guestrow">
                  <input class="input" type="text"  name="guest_name[]"  value="<?= e($g['name']) ?>"  placeholder="Name">
                  <input class="input" type="email" name="guest_email[]" value="<?= e($g['email']) ?>" placeholder="Email">
                  <input class="input" type="tel"   name="guest_phone[]" value="<?= e($g['phone']) ?>" placeholder="07XX XXX XXX">
                </div>
              <?php endforeach; ?>
            </div>

            <button class="btn btn--ghost btn--sm mt-8" type="button" data-add-guest>
              <?= icon('plus') ?> Another person
            </button>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Reminders &amp; access</div></div>
        <div class="card__body">

          <div class="field">
            <label class="label" for="reminder_mins">Remind everyone</label>
            <input class="input" type="text" id="reminder_mins" name="reminder_mins"
                   value="<?= e($val('reminder_mins')) ?>" placeholder="60,30">
            <div class="text-xs text-muted mt-4">
              Minutes before the start, separated by commas. Blank uses the system
              default (<?= e(setting('meeting_reminder_mins', '60,30')) ?>).
              Sent by email and SMS.
            </div>
          </div>

          <label class="check-row">
            <input type="checkbox" name="allow_guests" value="1"
                   <?= $meeting === null || $val('allow_guests') ? 'checked' : '' ?>>
            <span>
              <strong>Allow the share link</strong>
              <span class="text-xs text-muted d-block">
                Anyone holding the link can join. Turn this off for an internal
                meeting that only signed-in staff should reach.
              </span>
            </span>
          </label>
        </div>
      </div>

      <div class="card">
        <div class="card__body flex gap-8">
          <button class="btn btn--primary" type="submit">
            <?= icon('check') ?> <?= $meeting ? 'Save changes' : 'Schedule it' ?>
          </button>
          <a class="btn btn--ghost" href="<?= url($meeting ? '/meetings/' . $meeting['id'] : '/meetings') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
