<?php
/**
 * An account's own preferences: which name to send under by default,
 * when to be warned that the balance is running down, and how.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <form method="post" action="<?= url($base . '/settings') ?>">
    <?= csrf_field() ?>

    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">Sending</div></div>

      <div class="field">
        <label class="label" for="default_sender_id">Send under this name unless I choose another</label>
        <select class="select" id="default_sender_id" name="default_sender_id">
          <option value="">Ask me every time</option>
          <?php foreach ($senders as $s): ?>
            <option value="<?= e($s['sender_id']) ?>" <?= $account['default_sender_id'] === $s['sender_id'] ? 'selected' : '' ?>>
              <?= e($s['sender_id']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!$senders): ?>
          <span class="field-hint">
            You have no approved sender IDs yet.
            <a href="<?= url($base . '/senders') ?>">Ask for one</a>.
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">Running low</div></div>

      <div class="field">
        <label class="label" for="low_balance_threshold">Warn me below this many units</label>
        <input class="input" id="low_balance_threshold" name="low_balance_threshold" type="number" step="1" min="0"
               value="<?= e($account['low_balance_threshold'] ?? '') ?>"
               placeholder="<?= $systemLow > 0 ? (int) $systemLow : 'Not set' ?>">
        <span class="field-hint">
          <?php if ($systemLow > 0): ?>
            Leave it empty and we use <?= e(Present::units($systemLow)) ?>, which is what we warn everybody at.
          <?php else: ?>
            Leave it empty for no warning at all. 0 also means no warning.
          <?php endif; ?>
          You get one warning a day while you are under it, not one an hour.
        </span>
      </div>

      <div class="field mb-0">
        <label class="label">How we tell you</label>
        <label class="check-row">
          <input type="checkbox" name="alert_email" value="1" <?= (int) $account['alert_email'] === 1 ? 'checked' : '' ?>>
          <span>By e-mail</span>
        </label>
        <label class="check-row">
          <input type="checkbox" name="alert_sms" value="1" <?= (int) $account['alert_sms'] === 1 ? 'checked' : '' ?>>
          <span>By text</span>
        </label>
        <span class="field-hint">
          This covers a low balance, a campaign finishing, units arriving and
          a sender ID being decided. A text costs you one unit, so turn it
          off if you would rather have the e-mail alone.
        </span>
      </div>
    </div>

    <div class="portal-card">
      <button class="btn btn--primary btn--block" type="submit"><?= icon('save') ?> Save my settings</button>
    </div>
  </form>

  <div class="portal-card portal-card--quiet">
    <div class="text-sm">
      <div class="fw-600 mb-8">Your account</div>
      <p class="text-muted mb-0">
        Balance <strong><?= e(Present::units($account['sms_units'])) ?></strong> units.
        Your name, e-mail address and password are under your account menu at
        the top of the page — they are the same ones you use for the rest of
        your account with us, not separate SMS details.
      </p>
    </div>
  </div>
</div>
