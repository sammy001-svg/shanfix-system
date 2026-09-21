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

<div class="grid grid--2">
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
