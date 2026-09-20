<?php
/**
 * Send straight from a spreadsheet.
 *
 * The list people are handed is usually a file, and most of the time
 * they do not want it in their address book — they want this one message
 * to reach these people, with each person's own details in it.
 *
 * The file is read in the browser only to show what is in it and which
 * {columns} can be used. The send itself is an ordinary campaign: the
 * file goes to the worker, which reads it again server-side. Nothing is
 * trusted from the preview.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <?php if (!$senders): ?>
    <div class="alert alert--warning">
      <?= icon('alert-triangle') ?>
      <div class="alert__body">
        You need an approved sender ID before you can send.
        <a href="<?= url($base . '/senders') ?>"><strong>Ask for one</strong></a>.
      </div>
    </div>
  <?php else: ?>
    <form method="post" action="<?= url($base . '/campaigns') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="audience" value="file">

      <div class="portal-card">
        <div class="portal-card__head"><div class="portal-card__title">1. The file</div></div>

        <div class="field">
          <label class="label" for="fs-file">Your list</label>
          <input class="input" type="file" id="fs-file" name="list" accept=".csv,.xlsx,.txt" required
                 data-sms-preview="#file-preview" data-sms-placeholders="#placeholders">
          <span class="field-hint">
            CSV or Excel .xlsx, with a column of numbers headed
            <span class="code">phone</span>, <span class="code">mobile</span>
            or <span class="code">number</span>. It is deleted as soon as the
            campaign has been sent.
          </span>
        </div>

        <div id="file-preview" hidden></div>
      </div>

      <div class="portal-card">
        <div class="portal-card__head"><div class="portal-card__title">2. The message</div></div>

        <div class="portal-cols">
          <div class="field">
            <label class="label" for="fs-name">Name this send</label>
            <input class="input" id="fs-name" name="name" maxlength="180" placeholder="e.g. October statements">
          </div>
          <div class="field">
            <label class="label" for="fs-sender">From</label>
            <select class="select" id="fs-sender" name="sender_id" required>
              <?php foreach ($senders as $s): ?>
                <option value="<?= e($s['sender_id']) ?>"
                        <?= $account['default_sender_id'] === $s['sender_id'] ? 'selected' : '' ?>>
                  <?= e($s['sender_id']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label class="label" for="message">What it says</label>
          <textarea class="textarea" id="message" name="message" rows="5" required maxlength="918"
                    data-sms-counter="#fs-cost"
                    placeholder="Hello {name}, your balance is {balance}."></textarea>
          <span class="field-hint" id="fs-cost">0 characters · 1 part each</span>
          <div id="placeholders" class="mt-8" hidden></div>
        </div>

        <div class="field">
          <label class="label" for="fs-when">Send it</label>
          <input class="input" type="datetime-local" id="fs-when" name="scheduled_at">
          <span class="field-hint">Leave empty to start straight away.</span>
        </div>

        <div class="alert alert--info">
          <?= icon('info') ?>
          <div class="alert__body">
            You have <strong><?= e(Present::units($balance)) ?></strong> units.
            Each person costs one unit per part, so a two-part message to
            500 people is 1,000.
          </div>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">
          <?= icon('send') ?> Send to everybody in the file
        </button>
      </div>
    </form>
  <?php endif; ?>
</div>
