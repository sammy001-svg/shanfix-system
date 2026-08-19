<?php
require_once APP_PATH . '/Views/partials/icons.php';

$r    = $request;
$type = \App\Services\JobBrief::TYPES[$r['brief_type']];
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= $r['submitted_at'] ? 'Edit' : 'Fill in' ?> <?= e($r['reference']) ?></h1>
    <div class="page-head__sub">
      <?= e($type) ?> brief for <?= e($r['client_name']) ?>
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= url('/requests/' . $r['id']) ?>">Back</a>
  </div>
</div>

<div class="alert alert--info">
  <?= icon('info') ?>
  <div class="alert__body">
    <?php if ($r['submitted_at']): ?>
      You are editing answers that are already on record. It will stay marked
      as answered, but it will show that the last change came from us rather
      than the client.
    <?php else: ?>
      Use this when the client is on the phone or at the counter. It is
      recorded as taken down by you — worth knowing later, because an answer
      typed by us and an answer typed by the client do not carry the same
      weight if there is a disagreement about what was asked for.
    <?php endif; ?>
  </div>
</div>

<form method="post" action="<?= url('/requests/' . $r['id'] . '/fill') ?>">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card__head">
      <div>
        <div class="card__title"><?= e(\App\Services\JobBrief::HEADINGS[$r['brief_type']]) ?></div>
        <div class="card__sub">Leave anything they cannot answer blank</div>
      </div>
    </div>
    <div class="card__body">
      <?php require APP_PATH . '/Views/partials/brief_fields.php'; ?>
    </div>
    <div class="card__foot">
      <a class="btn btn--ghost" href="<?= url('/requests/' . $r['id']) ?>">Cancel</a>
      <button class="btn btn--primary" type="submit">Save the brief</button>
    </div>
  </div>
</form>
