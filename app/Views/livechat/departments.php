<?php
/**
 * Setting up the queues and deciding who answers them.
 *
 * Each department is one card: what it is called, what the visitor is
 * told it is for, and the list of people whose inbox its conversations
 * land in. Everything is on one page because there is not enough of it
 * to justify a second, and because who-answers-what is the sort of thing
 * that only makes sense seen all at once.
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Chat departments</h1>
    <div class="page-head__sub">
      Where a visitor's question lands, and who sees it. One department is
      always the default — it catches anybody who does not choose.
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= e(url('/livechat')) ?>"><?= icon('message') ?> Back to the desk</a>
  </div>
</div>

<?php if (!$people): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      Nobody on the team currently holds the permission to answer live chat,
      so no conversation will ever be read. Give somebody a role that does
      under Settings &rarr; Users first.
    </div>
  </div>
<?php endif; ?>

<div class="grid-2">
  <?php foreach ($departments as $d): ?>
    <?php $mine = $staff[(int) $d['id']] ?? []; ?>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">
            <?= e($d['name']) ?>
            <?php if ($d['is_default']): ?>
              <span class="badge badge--green">Default</span>
            <?php endif; ?>
            <?php if ($d['status'] === 'inactive'): ?>
              <span class="badge badge--grey">Off</span>
            <?php endif; ?>
          </div>
          <div class="text-sm text-muted"><?= e($d['blurb'] ?: 'No description') ?></div>
        </div>
        <div class="text-sm text-muted">
          <?= (int) $d['staff_count'] ?> answering
          <?php if ($d['waiting'] > 0): ?>
            &middot; <span class="badge badge--amber"><?= (int) $d['waiting'] ?> waiting</span>
          <?php endif; ?>
        </div>
      </div>

      <?php // ── Who answers ─────────────────────────────────────────
            // Nobody ticked is a real and dangerous state, so it is
            // spelled out rather than shown as an empty list. ?>
      <form method="post" action="<?= e(url('/livechat/departments/' . $d['id'] . '/staff')) ?>">
        <?= csrf_field() ?>

        <?php if (!$mine): ?>
          <p class="text-sm" style="color:var(--amber-700)">
            <?= icon('alert-triangle') ?>
            Nobody is answering this department. Anything arriving here will sit unread.
          </p>
        <?php endif; ?>

        <div class="checkgrid">
          <?php foreach ($people as $u): ?>
            <div class="check-row lc__person">
              <label class="lc__person-name">
                <input type="checkbox" name="user_ids[]" value="<?= (int) $u['id'] ?>"
                       <?= isset($mine[(int) $u['id']]) ? 'checked' : '' ?>>
                <span><?= e($u['name']) ?></span>
              </label>
              <?php // A lead is who gets told when something has been
                    // waiting too long. ?>
              <label class="lc__person-lead" title="Told when something has been waiting too long">
                <input type="checkbox" name="lead_ids[]" value="<?= (int) $u['id'] ?>"
                       <?= !empty($mine[(int) $u['id']]) ? 'checked' : '' ?>>
                <span>lead</span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>

        <button class="btn btn--primary btn--sm mt-8">Save who answers</button>
      </form>

      <?php // ── The department itself ───────────────────────────────── ?>
      <details class="mt-12">
        <summary class="text-sm text-muted">Rename, describe, or turn off</summary>

        <form method="post" action="<?= e(url('/livechat/departments')) ?>" class="mt-8">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">

          <div class="field">
            <label class="label">Name</label>
            <input class="input" name="name" value="<?= e($d['name']) ?>" maxlength="80" required>
          </div>

          <div class="field">
            <label class="label">What the visitor is told it is for</label>
            <input class="input" name="blurb" value="<?= e($d['blurb'] ?? '') ?>" maxlength="160"
                   placeholder="Prices, quotations and new orders">
          </div>

          <div class="field">
            <label class="label">Where to write if nobody answers</label>
            <input class="input" type="email" name="fallback_email"
                   value="<?= e($d['fallback_email'] ?? '') ?>" maxlength="160"
                   placeholder="Leave empty to use the company address">
          </div>

          <div class="field">
            <label class="label">Order in the list</label>
            <input class="input" type="number" name="position" value="<?= (int) $d['position'] ?>" min="0">
          </div>

          <label class="check mb-8">
            <input type="checkbox" name="status" value="inactive" <?= $d['status'] === 'inactive' ? 'checked' : '' ?>>
            <span class="check__text">Turn it off — no new conversations, history kept</span>
          </label>

          <label class="check mb-8">
            <input type="checkbox" name="is_default" value="1" <?= $d['is_default'] ? 'checked' : '' ?>>
            <span class="check__text">Make this the default</span>
          </label>

          <button class="btn btn--primary btn--sm">Save</button>
        </form>

        <?php if (count($departments) > 1): ?>
          <form method="post" action="<?= e(url('/livechat/departments/' . $d['id'] . '/delete')) ?>"
                class="mt-8"
                onsubmit="return confirm('Remove <?= e(addslashes($d['name'])) ?>? Its conversations move to the default department.')">
            <?= csrf_field() ?>
            <button class="btn btn--ghost btn--sm" style="color:var(--red-700)">Remove this department</button>
          </form>
        <?php endif; ?>
      </details>
    </div>
  <?php endforeach; ?>

  <?php // ── A new one ─────────────────────────────────────────────── ?>
  <div class="card">
    <div class="card__head"><div class="card__title">Add a department</div></div>

    <form method="post" action="<?= e(url('/livechat/departments')) ?>">
      <?= csrf_field() ?>

      <div class="field">
        <label class="label">Name</label>
        <input class="input" name="name" maxlength="80" required placeholder="Accounts">
      </div>

      <div class="field">
        <label class="label">What the visitor is told it is for</label>
        <input class="input" name="blurb" maxlength="160" placeholder="Invoices, statements and payments">
      </div>

      <div class="field">
        <label class="label">Where to write if nobody answers</label>
        <input class="input" type="email" name="fallback_email" maxlength="160"
               placeholder="Leave empty to use the company address">
      </div>

      <button class="btn btn--primary">Add it</button>
      <p class="text-sm text-muted mt-8">
        You can say who answers it once it exists.
      </p>
    </form>
  </div>
</div>

<?php // ── When we are here, and who gets told ───────────────────────
      // These settings have lived only in the database since the chat
      // was built: seeded by the migration and changeable by nobody
      // without a MySQL client. They sit on this page rather than under
      // Settings because this is what somebody opens when they are
      // thinking about live chat, and because the alert thresholds only
      // make sense beside the departments they escalate to. ?>
<div class="card mt-16">
  <div class="card__head">
    <div>
      <div class="card__title">Hours, and being told</div>
      <div class="card__sub">
        Outside these hours visitors are told nobody is at the desk and are
        asked for an email address, so a question at midnight is not lost.
        Inside them, everybody in a department gets the bell the moment a
        chat arrives.
      </div>
    </div>
  </div>

  <form method="post" action="<?= e(url('/livechat/settings')) ?>">
    <?= csrf_field() ?>

    <div class="grid-2">
      <div>
        <div class="field">
          <label class="label" for="lcGreeting">The first thing a visitor reads</label>
          <input class="input" id="lcGreeting" name="livechat_greeting" maxlength="200"
                 value="<?= e(setting('livechat_greeting', '')) ?>">
        </div>

        <div class="field">
          <label class="label" for="lcOffline">And what they read out of hours</label>
          <textarea class="input" id="lcOffline" name="livechat_offline_message" rows="3"
                    maxlength="400"><?= e(setting('livechat_offline_message', '')) ?></textarea>
        </div>

        <div class="field">
          <label class="label" for="lcFrom">Open between</label>
          <div class="field-row">
            <input class="input" id="lcFrom" type="time" name="livechat_hours_from"
                   value="<?= e(setting('livechat_hours_from', '08:00')) ?>">
            <span class="text-muted">and</span>
            <input class="input" type="time" name="livechat_hours_to"
                   value="<?= e(setting('livechat_hours_to', '17:30')) ?>">
          </div>
        </div>

        <div class="field">
          <label class="label">On these days</label>
          <?php
            $openDays = array_map('intval', array_filter(
                explode(',', (string) setting('livechat_hours_days', '1,2,3,4,5,6')),
                'strlen'
            ));
            // Monday first, Sunday last: the Kenyan working week, and the
            // order everybody reads a week in.
            $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
                         5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];
          ?>
          <div class="field-row field-row--wrap">
            <?php foreach ($dayNames as $n => $label): ?>
              <label class="check">
                <input type="checkbox" name="days[]" value="<?= $n ?>"
                       <?= in_array($n, $openDays, true) ? 'checked' : '' ?>>
                <span class="check__text"><?= $label ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div>
        <div class="field">
          <label class="label" for="lcAfter">Tell the department's leads after</label>
          <div class="field-row">
            <input class="input" id="lcAfter" type="number" name="livechat_alert_after"
                   min="1" max="240" style="max-width:110px"
                   value="<?= (int) setting('livechat_alert_after', 5) ?>">
            <span class="text-muted">minutes unanswered</span>
          </div>
          <p class="text-sm text-muted mt-8">
            The bell rings for everybody in the department straight away. This
            is the second line, for a conversation nobody has picked up — once
            per conversation, not once per cron run.
          </p>
        </div>

        <label class="check mb-8">
          <input type="checkbox" name="livechat_alert_email" value="1"
                 <?= setting('livechat_alert_email', '1') ? 'checked' : '' ?>>
          <span class="check__text">Email the leads when it escalates</span>
        </label>

        <label class="check mb-8">
          <input type="checkbox" name="livechat_alert_sms" value="1"
                 <?= setting('livechat_alert_sms', '0') ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>Text them as well</strong>
            <span>Costs a message each time it escalates</span>
          </span>
        </label>

        <label class="check mb-8">
          <input type="checkbox" name="livechat_alert_sound" value="1"
                 <?= setting('livechat_alert_sound', '1') ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>The desk makes a sound when somebody joins the queue</strong>
            <span>A default only — each person can silence their own from the desk</span>
          </span>
        </label>

        <label class="check mb-8">
          <input type="checkbox" name="livechat_ask_department" value="1"
                 <?= setting('livechat_ask_department', '1') ? 'checked' : '' ?>>
          <span class="check__text">Ask the visitor which department they want</span>
        </label>

        <label class="check mb-8">
          <input type="checkbox" name="livechat_enabled" value="1"
                 <?= setting('livechat_enabled', '1') ? 'checked' : '' ?>>
          <span class="check__text">
            <strong>Show the chat widget on the website</strong>
            <span>Unticking it hides the widget; nothing is deleted</span>
          </span>
        </label>
      </div>
    </div>

    <button class="btn btn--primary mt-16">Save these</button>
  </form>
</div>
