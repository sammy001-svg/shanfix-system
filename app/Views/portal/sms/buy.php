<?php
/**
 * Buying units.
 *
 * Two quite different pages depending on who supplies this customer. Our
 * own clients pay by M-Pesa and the units land by themselves. A partner's
 * clients pay the partner, so all we can do is record what they asked for
 * and tell the partner — pretending otherwise would take money for units
 * we are not the ones selling.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;
use App\Services\BulkSms\Purchases;

$smsTab  = 'buy';
$waiting = (int) ($_GET['waiting'] ?? 0);
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <?php if ($waiting > 0): ?>
    <div class="portal-card" data-sms-topup="<?= e(url('/portal/sms/buy/' . $waiting . '/status')) ?>">
      <div class="portal-card__head"><div class="portal-card__title">Waiting for your M-Pesa PIN</div></div>
      <p class="text-sm" data-sms-topup-note>Check your phone and enter your M-Pesa PIN.</p>
    </div>
  <?php endif; ?>

  <?php if ($plans): ?>
    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">Bundles</div></div>
      <div class="portal-grid">
        <?php foreach ($plans as $p): ?>
          <form method="post" action="<?= url('/portal/sms/buy') ?>" class="portal-tile <?= $p['is_popular'] ? 'portal-tile--owing' : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
            <?php if ($byMpesa): ?><input type="hidden" name="phone" value="<?= e($phone) ?>"><?php endif; ?>
            <div class="portal-tile__label"><?= e($p['name']) ?><?= $p['is_popular'] ? ' · most popular' : '' ?></div>
            <div class="portal-tile__figure"><?= e(Present::units($p['units'])) ?></div>
            <div class="text-sm text-muted">units for <strong><?= e(money($p['price'])) ?></strong></div>
            <div class="text-xs text-muted mb-8">
              <?= e(number_format((float) $p['price'] / max(1, (int) $p['units']), 2)) ?> a unit
            </div>
            <button class="btn btn--outline btn--sm btn--block" type="submit">
              <?= $byMpesa ? 'Buy with M-Pesa' : 'Ask for this' ?>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">Any number of units</div>
    </div>

    <form method="post" action="<?= url('/portal/sms/buy') ?>">
      <?= csrf_field() ?>

      <div class="portal-cols">
        <div class="field">
          <label class="label" for="units">How many units</label>
          <input class="input" id="units" name="units" type="number" min="10" step="10" value="500" required>
          <span class="field-hint">KES <?= e(number_format($price, 2)) ?> a unit from <?= e($sellerName) ?>.</span>
        </div>

        <?php if ($byMpesa): ?>
          <div class="field">
            <label class="label" for="phone">M-Pesa number</label>
            <input class="input" id="phone" name="phone" value="<?= e($phone) ?>" required placeholder="0712345678">
            <span class="field-hint">The prompt goes to this phone.</span>
          </div>
        <?php else: ?>
          <div class="field">
            <label class="label" for="transaction_ref">M-Pesa code, if you have paid</label>
            <input class="input" id="transaction_ref" name="transaction_ref" maxlength="100" placeholder="e.g. SFH7K2LM9P">
          </div>
        <?php endif; ?>
      </div>

      <?php if ($byMpesa): ?>
        <button class="btn btn--primary btn--block btn--lg" type="submit">
          <?= icon('smartphone') ?> Buy with M-Pesa
        </button>
      <?php else: ?>
        <div class="alert alert--info">
          <?= icon('info') ?>
          <div class="alert__body">
            Your SMS units come from <strong><?= e($sellerName) ?></strong>.
            Pay them as you normally do, then send this request — they add
            the units once they have confirmed the money.
          </div>
        </div>
        <button class="btn btn--primary btn--block btn--lg" type="submit">
          <?= icon('send') ?> Send the request
        </button>
      <?php endif; ?>
    </form>
  </div>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">What you have bought</div></div>

    <?php if (!$history): ?>
      <p class="text-sm text-muted">Nothing yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th style="width:130px">Date</th><th class="num">Units</th><th class="num">Paid</th><th>How</th><th style="width:110px">State</th></tr></thead>
          <tbody>
            <?php foreach ($history as $h): ?>
              <?php [$cls, $word] = Present::purchase((string) $h['status']); ?>
              <tr>
                <td class="text-sm text-muted"><?= e(fdate($h['created_at'])) ?></td>
                <td class="num"><?= e(Present::units($h['units'])) ?></td>
                <td class="num"><?= e(money($h['amount'])) ?></td>
                <td class="text-sm">
                  <?= e(Purchases::METHODS[$h['method']] ?? $h['method']) ?>
                  <?php if ($h['transaction_ref']): ?><div class="text-xs text-muted code"><?= e($h['transaction_ref']) ?></div><?php endif; ?>
                </td>
                <td><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
