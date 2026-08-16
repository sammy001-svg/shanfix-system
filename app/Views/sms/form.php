<?php
/**
 * Compose a campaign, then price it before sending.
 *
 * @var array      $audiences
 * @var array      $form
 * @var array|null $preview  populated once the audience has been resolved
 * @var bool       $smsOn
 */
require_once APP_PATH . '/Views/partials/icons.php';

$parts = \App\Services\Sms::parts($form['message']);
$len   = mb_strlen($form['message']);

// A single curly quote or dash pasted from Word turns the message Unicode,
// which cuts the per-part budget from 160 characters to 70 and quietly
// doubles the bill. Worth naming the culprits rather than just the count.
preg_match_all('/[^\x20-\x7E\n\r]/u', $form['message'], $m);
$unicodeChars = array_values(array_unique($m[0] ?? []));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>New SMS campaign</h1>
    <div class="page-head__sub">One message to many clients, priced before it goes.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/sms-campaigns') ?>">Past campaigns</a>
  </div>
</div>

<?php if (!$smsOn): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      <strong>SMS is switched off.</strong>
      Turn it on and add your Shanfix Bulk SMS credentials in
      <a href="<?= url('/settings?tab=messaging') ?>">Settings &rarr; Email &amp; SMS</a>
      before sending a campaign.
    </div>
  </div>
<?php endif; ?>

<div class="grid-2">

  <form method="post" action="<?= url('/sms-campaigns/preview') ?>">
    <?= csrf_field() ?>
    <div class="card">
      <div class="card__head">
        <?= icon('message') ?>
        <div>
          <div class="card__title">The message</div>
          <div class="card__sub">Kept under 160 characters, it costs one credit per recipient</div>
        </div>
      </div>
      <div class="card__body">

        <div class="field">
          <label class="label" for="title">Campaign name <span class="req">*</span></label>
          <input class="input <?= isset($errors['title']) ? 'has-error' : '' ?>"
                 id="title" name="title" maxlength="140"
                 value="<?= e($form['title']) ?>"
                 placeholder="e.g. December workshop closure">
          <span class="field-hint">For your own records — clients never see this.</span>
          <?= error_for($errors ?? [], 'title') ?>
        </div>

        <div class="field">
          <label class="label" for="audience">Send to <span class="req">*</span></label>
          <select class="input <?= isset($errors['audience']) ? 'has-error' : '' ?>"
                  id="audience" name="audience">
            <?php foreach ($audiences as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $form['audience'] === $key ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="field-hint">Only clients with a valid Kenyan number are included.</span>
          <?= error_for($errors ?? [], 'audience') ?>
        </div>

        <div class="field">
          <label class="label" for="message">
            Message <span class="req">*</span>
            <span class="label__hint">— <?= $len ?> chars, <?= $parts ?> credit(s) each</span>
          </label>
          <textarea class="textarea <?= isset($errors['message']) ? 'has-error' : '' ?>"
                    id="message" name="message" rows="6" maxlength="918"
                    data-sms-counter
                    placeholder="Hi, our workshop closes on 20 December and reopens on 6 January. Thank you for your business this year."><?= e($form['message']) ?></textarea>
          <span class="field-hint">
            No placeholders here — a campaign goes out word for word to everyone.
            Sign off with your company name so it is clear who is texting.
          </span>
          <?= error_for($errors ?? [], 'message') ?>

          <?php if ($unicodeChars): ?>
            <div class="alert alert--warning mt-8">
              <?= icon('alert-triangle') ?>
              <div class="alert__body text-sm">
                <strong>This message costs double.</strong>
                It contains
                <?php foreach (array_slice($unicodeChars, 0, 6) as $ch): ?>
                  <code><?= e($ch) ?></code>
                <?php endforeach; ?>
                — characters outside the plain SMS set, usually curly quotes or
                long dashes pasted from Word. They cut the limit from 160
                characters per credit to 70. Retype them as
                <code>'</code> and <code>-</code> to halve the cost.
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit">
            <?= icon('search') ?> Check recipients &amp; cost
          </button>
        </div>

      </div>
    </div>
  </form>

  <?php if ($preview !== null): ?>
    <div class="card">
      <div class="card__head">
        <?= icon('users') ?>
        <div>
          <div class="card__title">Before you send</div>
          <div class="card__sub"><?= e($audiences[$form['audience']] ?? '') ?></div>
        </div>
      </div>
      <div class="card__body">

        <?php if ($preview['count'] === 0): ?>
          <div class="alert alert--warning mb-0">
            <?= icon('alert-triangle') ?>
            <div class="alert__body">
              Nobody in that audience has a usable phone number, so there is
              nothing to send.
            </div>
          </div>
        <?php else: ?>

          <div class="stat-grid mb-16">
            <div class="stat">
              <div class="stat__label">Recipients</div>
              <div class="stat__value"><?= number_format($preview['count']) ?></div>
            </div>
            <div class="stat">
              <div class="stat__label">Credits each</div>
              <div class="stat__value"><?= (int) $preview['parts'] ?></div>
            </div>
            <div class="stat stat--green">
              <div class="stat__label">Total credits</div>
              <div class="stat__value"><?= number_format($preview['credits']) ?></div>
            </div>
          </div>

          <?php if ($preview['balance'] !== null): ?>
            <?php $short = $preview['balance'] < $preview['credits']; ?>
            <div class="alert <?= $short ? 'alert--error' : 'alert--info' ?>">
              <?= icon($short ? 'alert-triangle' : 'info') ?>
              <div class="alert__body">
                Your balance is
                <strong><?= e(rtrim(rtrim(number_format((float) $preview['balance'], 2), '0'), '.')) ?></strong>
                units.
                <?php if ($short): ?>
                  That is <strong>not enough</strong> for this campaign — top up first,
                  or the gateway will stop part way through.
                <?php else: ?>
                  About
                  <strong><?= number_format(max(0, $preview['balance'] - $preview['credits'])) ?></strong>
                  would be left afterwards.
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="alert alert--warning">
              <?= icon('alert-triangle') ?>
              <div class="alert__body">
                Could not read your balance: <?= e($preview['balanceNote'] ?? '') ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="text-xs uppercase fw-700 text-muted mb-8 mt-16">
            First few recipients
          </div>
          <div class="table-wrap" style="max-height:220px;overflow-y:auto">
            <table class="table">
              <tbody>
                <?php foreach (array_slice($preview['recipients'], 0, 12) as $r): ?>
                  <tr>
                    <td><?= e($r['name']) ?></td>
                    <td class="text-muted">+<?= e($r['phone']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($preview['count'] > 12): ?>
            <p class="field-hint">…and <?= number_format($preview['count'] - 12) ?> more.</p>
          <?php endif; ?>

          <hr>

          <form method="post" action="<?= url('/sms-campaigns') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="title"    value="<?= e($form['title']) ?>">
            <input type="hidden" name="audience" value="<?= e($form['audience']) ?>">
            <input type="hidden" name="message"  value="<?= e($form['message']) ?>">
            <?php // Spent on the first submit, so a refresh cannot send twice. ?>
            <input type="hidden" name="_idem"    value="<?= e($preview['token']) ?>">

            <button class="btn btn--primary btn--block" type="submit" <?= $smsOn ? '' : 'disabled' ?>>
              <?= icon('send') ?>
              Send to <?= number_format($preview['count']) ?> client<?= $preview['count'] === 1 ? '' : 's' ?>
              (<?= number_format($preview['credits']) ?> credits)
            </button>
            <p class="field-hint mt-8 mb-0" style="text-align:center">
              This sends immediately and cannot be recalled.
            </p>
          </form>

        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card__body">
        <div class="empty">
          <div class="empty__icon"><?= icon('users') ?></div>
          <div class="empty__title">Nothing priced yet</div>
          <p class="empty__text">
            Write your message and choose an audience, then
            <strong>Check recipients &amp; cost</strong> to see exactly how many
            clients it reaches and what it will spend before anything is sent.
          </p>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div>
