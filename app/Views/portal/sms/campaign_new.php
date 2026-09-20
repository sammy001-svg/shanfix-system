<?php
/**
 * Starting a campaign.
 *
 * Three ways to say who it goes to — a saved list, a file, or numbers
 * typed in — and only one of them applies, so they are radio buttons
 * rather than three boxes anyone could fill in at once.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;

$smsTab = 'campaigns';
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <?php if (!$senders): ?>
    <div class="alert alert--warning">
      <?= icon('alert-triangle') ?>
      <div class="alert__body">
        You need an approved sender ID first.
        <a href="<?= url('/portal/sms/senders') ?>"><strong>Ask for one</strong></a>.
      </div>
    </div>
  <?php else: ?>
    <div class="portal-card">
      <form method="post" action="<?= url('/portal/sms/campaigns') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="portal-cols">
          <div class="field">
            <label class="label" for="name">Name it</label>
            <input class="input" id="name" name="name" maxlength="180" value="<?= e(old('name')) ?>"
                   placeholder="e.g. December offer">
            <span class="field-hint">Only you see this.</span>
          </div>
          <div class="field">
            <label class="label" for="sender_id">From</label>
            <select class="select" id="sender_id" name="sender_id" required>
              <?php foreach ($senders as $s): ?>
                <option value="<?= e($s['sender_id']) ?>" <?= old('sender_id') === $s['sender_id'] ? 'selected' : '' ?>>
                  <?= e($s['sender_id']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <fieldset class="field">
          <legend class="label">Who is it going to?</legend>

          <label class="check-row">
            <input type="radio" name="audience" value="group" <?= old('audience', 'group') === 'group' ? 'checked' : '' ?>>
            <span>One of my lists</span>
          </label>
          <div class="field" style="margin-left:26px">
            <select class="select" name="group_id">
              <option value="">Choose a list…</option>
              <?php foreach ($groups as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= (int) old('group_id') === (int) $g['id'] ? 'selected' : '' ?>>
                  <?= e($g['name']) ?> — <?= number_format((int) $g['people']) ?> contact<?= (int) $g['people'] === 1 ? '' : 's' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$groups): ?>
              <span class="field-hint">You have no lists yet. <a href="<?= url('/portal/sms/contacts') ?>">Make one</a>, or upload a file below.</span>
            <?php endif; ?>
          </div>

          <label class="check-row">
            <input type="radio" name="audience" value="file" <?= old('audience') === 'file' ? 'checked' : '' ?>>
            <span>A file of numbers</span>
          </label>
          <div class="field" style="margin-left:26px">
            <input class="input" type="file" name="list" accept=".csv,.xlsx,.txt">
            <span class="field-hint">
              CSV or Excel, with a column called phone, mobile or number. Any
              other columns can be used in the message as {column}. Nothing is
              kept: the file is deleted as soon as it has been sent.
            </span>
          </div>

          <label class="check-row">
            <input type="radio" name="audience" value="numbers" <?= old('audience') === 'numbers' ? 'checked' : '' ?>>
            <span>Numbers I paste in</span>
          </label>
          <div class="field" style="margin-left:26px">
            <textarea class="textarea" name="recipients" rows="3"
                      placeholder="0712345678, 0722000111"><?= e(old('recipients')) ?></textarea>
          </div>
        </fieldset>

        <div class="field">
          <label class="label" for="message">Message</label>
          <textarea class="textarea" id="message" name="message" rows="5" required maxlength="918"
                    data-sms-counter="#campaign-cost"><?= e(old('message')) ?></textarea>
          <span class="field-hint" id="campaign-cost">0 characters · 1 part each</span>
          <?php if ($templates): ?>
            <div class="mt-8">
              <select class="select" data-sms-template>
                <option value="">Use a saved message…</option>
                <?php foreach ($templates as $t): ?>
                  <option value="<?= e($t['message']) ?>"><?= e($t['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <span class="field-hint">
            Write {name} and each person's own name is put in. Anything else
            in your list works the same way — {balance}, {town}, whatever
            your columns are called.
          </span>
        </div>

        <div class="field">
          <label class="label" for="scheduled_at">Send it</label>
          <input class="input" type="datetime-local" id="scheduled_at" name="scheduled_at" value="<?= e(old('scheduled_at')) ?>">
          <span class="field-hint">
            Leave empty to start now. Scheduled campaigns go out on their own
            — you do not need to stay signed in.
          </span>
        </div>

        <div class="alert alert--info">
          <?= icon('info') ?>
          <div class="alert__body">
            You have <strong><?= e(Present::units($balance)) ?></strong> units.
            One part to one person costs one unit, so 1,000 people and a
            two-part message costs 2,000.
          </div>
        </div>

        <button class="btn btn--primary btn--block btn--lg" type="submit">
          <?= icon('send') ?> Start this campaign
        </button>
      </form>
    </div>
  <?php endif; ?>
</div>
