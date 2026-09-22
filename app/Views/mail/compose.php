<?php
/**
 * Writing a message: new, reply, reply all, or forward.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$d = $draft;
$titles = ['reply' => 'Reply', 'all' => 'Reply to all', 'forward' => 'Forward'];
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($titles[$mode] ?? 'New message') ?></h1>
    <div class="page-head__sub">From <?= e(($box->displayName() !== '' ? $box->displayName() . ' ' : '') . '<' . $box->email() . '>') ?></div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--ghost" href="<?= e(url('/mail')) ?>">Discard</a>
  </div>
</div>

<form class="card mb-compose" method="post" action="<?= e(url('/mail/send')) ?>" enctype="multipart/form-data" data-compose>
  <?= csrf_field() ?>
  <input type="hidden" name="in_reply_to" value="<?= e($d['in_reply_to']) ?>">
  <input type="hidden" name="references" value="<?= e($d['references']) ?>">
  <input type="hidden" name="fwd_uid" value="<?= (int) $d['fwd_uid'] ?>">
  <input type="hidden" name="fwd_folder" value="<?= e($d['fwd_folder']) ?>">

  <div class="card__body">
    <div class="mb-compose__row">
      <label class="mb-compose__label" for="c_to">To</label>
      <input class="input" id="c_to" name="to" value="<?= e($d['to']) ?>" required autocomplete="off"
             placeholder="name@example.com, another@example.com" <?= $d['to'] === '' ? 'autofocus' : '' ?>>
      <button type="button" class="btn btn--ghost btn--sm" data-show-cc>Cc / Bcc</button>
    </div>

    <div class="mb-compose__row" data-cc-row <?= ($d['cc'] === '' && $d['bcc'] === '') ? 'hidden' : '' ?>>
      <label class="mb-compose__label" for="c_cc">Cc</label>
      <input class="input" id="c_cc" name="cc" value="<?= e($d['cc']) ?>" autocomplete="off">
    </div>
    <div class="mb-compose__row" data-cc-row <?= ($d['cc'] === '' && $d['bcc'] === '') ? 'hidden' : '' ?>>
      <label class="mb-compose__label" for="c_bcc">Bcc</label>
      <input class="input" id="c_bcc" name="bcc" value="<?= e($d['bcc']) ?>" autocomplete="off">
    </div>

    <div class="mb-compose__row">
      <label class="mb-compose__label" for="c_subject">Subject</label>
      <input class="input" id="c_subject" name="subject" value="<?= e($d['subject']) ?>" maxlength="250">
    </div>

    <textarea class="textarea mb-compose__text" name="text" rows="16" id="c_text"
              <?= $d['to'] !== '' ? 'autofocus' : '' ?>><?= e($d['text']) ?></textarea>

    <?php if ($box->signature() !== ''): ?>
      <div class="text-xs text-muted mt-8">Your signature is added when it sends.</div>
    <?php endif; ?>

    <?php if ($d['fwd_files']): ?>
      <div class="mt-16">
        <div class="text-xs uppercase fw-700 text-muted mb-8">Attachments from the original</div>
        <?php foreach ($d['fwd_files'] as $ff): ?>
          <label class="check mb-8">
            <input type="checkbox" name="fwd_keep[]" value="<?= (int) $ff['index'] ?>" checked>
            <span class="check__text"><?= icon('paperclip') ?> <?= e($ff['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="mb-compose__foot">
      <label class="btn btn--ghost btn--sm mb-compose__attach">
        <?= icon('paperclip') ?> Attach files
        <input type="file" name="attachments[]" multiple data-attach hidden>
      </label>
      <span class="text-xs text-muted" data-attach-list>Up to <?= (int) $maxMb ?>MB in total</span>
      <button class="btn btn--primary" type="submit" data-send><?= icon('send') ?> Send</button>
    </div>
  </div>
</form>
