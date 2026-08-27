<?php
require_once APP_PATH . '/Views/partials/icons.php';

$l     = $letter;
$final = $l['status'] === 'final';
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($l['subject']) ?></h1>
    <div class="page-head__sub">
      <?= e($l['reference']) ?>
      · to <?= e($l['recipient_name']) ?><?= $l['recipient_org'] ? ', ' . e($l['recipient_org']) : '' ?>
      · <?= e(date('j M Y', strtotime((string) $l['letter_date']))) ?>
      <?php if ($l['author']): ?> · written by <?= e($l['author']) ?><?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <span class="badge badge--<?= $final ? 'green' : 'grey' ?>">
      <?= $final ? 'Final' : 'Draft' ?>
    </span>
    <a class="btn btn--primary" href="<?= url('/letters/' . $l['id'] . '/print') ?>">
      <?= icon('printer') ?> Print
    </a>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <?= icon('file-text') ?>
        <div>
          <div class="card__title">What it says</div>
          <div class="card__sub">Shown plainly here; the letterhead is added when you print</div>
        </div>
      </div>
      <div class="card__body">
        <p class="text-sm text-muted mb-4"><?= e($l['salutation']) ?>,</p>
        <p class="fw-600 mb-12"><?= e($l['subject']) ?></p>

        <?php foreach (preg_split('/\n\s*\n/', trim((string) $l['body'])) as $para): ?>
          <p class="text-sm"><?= nl2br(e(trim($para))) ?></p>
        <?php endforeach; ?>

        <p class="text-sm mt-16 mb-0"><?= e($l['closing']) ?>,</p>
        <p class="text-sm mb-0">
          <strong><?= e($l['signatory_name']) ?></strong>
          <?php if ($l['signatory_title']): ?><br><?= e($l['signatory_title']) ?><?php endif; ?>
        </p>
      </div>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card__head">
        <?= icon('user') ?>
        <div><div class="card__title">Addressed to</div></div>
      </div>
      <div class="card__body">
        <p class="text-sm mb-0">
          <?php if ($l['recipient_title']): ?><?= e($l['recipient_title']) ?><br><?php endif; ?>
          <strong><?= e($l['recipient_name']) ?></strong>
          <?php if ($l['recipient_org']): ?><br><?= e($l['recipient_org']) ?><?php endif; ?>
          <?php if ($l['recipient_address']): ?><br><?= nl2br(e($l['recipient_address'])) ?><?php endif; ?>
        </p>

        <?php if ($l['client_id']): ?>
          <p class="text-xs text-muted mt-12 mb-0">
            Filed against
            <a href="<?= url('/clients/' . $l['client_id']) ?>"><?= e($l['client_name']) ?></a>
          </p>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($canManage)): ?>
      <div class="card">
        <div class="card__head">
          <?= icon('settings') ?>
          <div><div class="card__title">This letter</div></div>
        </div>
        <div class="card__body">
          <a class="btn btn--outline btn--block" href="<?= url('/letters/' . $l['id'] . '/edit') ?>">
            <?= icon('edit') ?> Edit
          </a>

          <form method="post" action="<?= url('/letters/' . $l['id'] . '/duplicate') ?>" class="mt-8">
            <?= csrf_field() ?>
            <button class="btn btn--ghost btn--block" type="submit">
              <?= icon('copy') ?> Start another from this
            </button>
          </form>

          <form method="post" action="<?= url('/letters/' . $l['id'] . '/status') ?>" class="mt-8">
            <?= csrf_field() ?>
            <input type="hidden" name="status" value="<?= $final ? 'draft' : 'final' ?>">
            <button class="btn btn--ghost btn--block" type="submit">
              <?= $final ? 'Put back to a draft' : 'Mark as final' ?>
            </button>
          </form>

          <?php if (!$final): ?>
            <form method="post" action="<?= url('/letters/' . $l['id'] . '/delete') ?>" class="mt-8"
                  data-confirm="Delete this draft? It cannot be undone.">
              <?= csrf_field() ?>
              <button class="btn btn--ghost btn--block text-red" type="submit">Delete draft</button>
            </form>
          <?php else: ?>
            <p class="text-xs text-muted mt-12 mb-0">
              A final letter is the record of what was sent, so it cannot be
              deleted. Put it back to a draft first if it really must go.
            </p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($vision !== ''): ?>
      <div class="card">
        <div class="card__body">
          <div class="text-xs text-muted mb-4">Printed at the foot of every letter</div>
          <p class="text-sm mb-0" style="font-style:italic"><?= e($vision) ?></p>
          <p class="text-xs text-muted mt-8 mb-0">
            Change it in <a href="<?= url('/settings') ?>">Settings</a>.
          </p>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>
