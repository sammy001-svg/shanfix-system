<?php
/**
 * Sender IDs, from the customer's side: what they have, and asking for
 * another.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;

$smsTab = 'senders';
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Your sender IDs</div></div>

    <?php if (!$rows): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('shield') ?></div>
        <div class="portal-empty__title">None yet</div>
        <p class="text-sm text-muted">
          A sender ID is the name your messages arrive from — your business
          name instead of a phone number. Ask for one below.
        </p>
      </div>
    <?php else: ?>
      <ul class="portal-list portal-list--tight">
        <?php foreach ($rows as $s): ?>
          <?php [$cls, $word] = Present::sender((string) $s['status']); ?>
          <li class="portal-list__row">
            <span class="portal-list__main">
              <span class="portal-list__title code"><?= e($s['sender_id']) ?></span>
              <span class="portal-list__meta">
                Asked <?= e(fdate($s['created_at'])) ?>
                <?php if ($s['reject_reason']): ?> · <?= e($s['reject_reason']) ?><?php endif; ?>
              </span>
            </span>
            <span class="portal-list__side"><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Ask for a sender ID</div></div>

    <p class="text-sm text-muted">
      The mobile networks register these, not us, so it takes a few working
      days. They ask for a letter on your company letterhead saying what the
      messages are for, and your registration certificate. Send them here if
      you have them and it goes through faster.
    </p>

    <form method="post" action="<?= url('/portal/sms/senders') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="field">
        <label class="label" for="sender_id">The name you want</label>
        <input class="input input--code" id="sender_id" name="sender_id" maxlength="11" required
               placeholder="SHANFIX" value="<?= e(old('sender_id')) ?>">
        <span class="field-hint">
          3 to 11 letters or numbers, no spaces at the ends. Capitals are
          kept exactly as you type them.
        </span>
      </div>

      <div class="field">
        <label class="label" for="purpose">What will you send?</label>
        <textarea class="textarea" id="purpose" name="purpose" rows="3" required
                  placeholder="e.g. Order confirmations and delivery notices to our own customers."><?= e(old('purpose')) ?></textarea>
        <span class="field-hint">The networks ask for this in writing. One or two sentences is enough.</span>
      </div>

      <div class="portal-cols">
        <div class="field">
          <label class="label" for="letter">Letter on your letterhead</label>
          <input class="input" type="file" id="letter" name="letter" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
        </div>
        <div class="field">
          <label class="label" for="certificate">Registration certificate</label>
          <input class="input" type="file" id="certificate" name="certificate" accept=".pdf,.jpg,.jpeg,.png">
        </div>
      </div>

      <button class="btn btn--primary btn--block" type="submit"><?= icon('shield') ?> Send the request</button>
    </form>
  </div>
</div>
