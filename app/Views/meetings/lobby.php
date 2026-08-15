<?php
require_once APP_PATH . '/Views/partials/icons.php';

$ts   = strtotime($starts);
$mins = (int) round(($ts - time()) / 60);
?>

<div class="lobby">
  <div class="card lobby__card">
    <div class="card__body">
      <div class="lobby__brand">
        <span class="sidebar__mark" style="width:34px;height:34px;flex-basis:34px;font-size:13px">SF</span>
        <span class="fw-600"><?= e($company['name']) ?></span>
      </div>

      <h1 class="lobby__title"><?= e($meeting['title']) ?></h1>
      <div class="lobby__when">
        <?= icon('calendar') ?>
        <?= e(fdate($starts)) ?> at <?= e(date('H:i', $ts)) ?>
        <?php if ($mins > 0 && $mins < 120): ?>
          · starts in <?= $mins ?> minute<?= $mins === 1 ? '' : 's' ?>
        <?php elseif ($meeting['status'] === 'in_progress'): ?>
          · <span class="text-green fw-600">happening now</span>
        <?php endif; ?>
      </div>

      <?php if ($meeting['agenda']): ?>
        <div class="lobby__agenda"><?= e(str_excerpt($meeting['agenda'], 220)) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= url('/join/' . $meeting['public_token']) ?>" class="lobby__form">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="name">Your name <span class="req">*</span></label>
          <input class="input" type="text" id="name" name="name" required autofocus
                 maxlength="160" placeholder="So everyone knows who is in the room">
        </div>

        <div class="field">
          <label class="label" for="email">Your email</label>
          <input class="input" type="email" id="email" name="email"
                 placeholder="Optional — matches you to your invitation">
        </div>

        <button class="btn btn--primary" type="submit" style="width:100%;justify-content:center">
          <?= icon('video') ?> Join the meeting
        </button>
      </form>

      <p class="lobby__note">
        The meeting runs in your browser. Nothing to install and no account needed.
        You will be asked for permission before your microphone or screen is used.
      </p>
    </div>
  </div>
</div>
