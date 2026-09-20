<?php
/**
 * Payments a partner's own clients say they have made.
 *
 * The money went to the partner, so only the partner can confirm it
 * arrived. Confirming moves units out of their balance and into the
 * client's, which is why it is a deliberate act and not automatic.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;
use App\Services\BulkSms\Purchases;

$smsTab = 'sales';
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-stats">
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= count($waiting) ?></div>
      <div class="portal-stat__label">Waiting on you</div>
    </div>
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= e(money($earned)) ?></div>
      <div class="portal-stat__label">Confirmed this month</div>
    </div>
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= e(Present::units($account['sms_units'])) ?></div>
      <div class="portal-stat__label">Units you hold</div>
      <div class="portal-stat__note">What you can hand over</div>
    </div>
  </div>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Waiting to be confirmed</div></div>

    <?php if (!$waiting): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('check-circle') ?></div>
        <div class="portal-empty__title">Nothing waiting</div>
        <p class="text-sm text-muted">When a client asks to buy units, it appears here.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead>
            <tr>
              <th>Client</th>
              <th class="num" style="width:100px">Units</th>
              <th class="num" style="width:120px">They pay</th>
              <th style="width:150px">Their reference</th>
              <th style="width:120px">Asked</th>
              <th style="width:210px"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($waiting as $p): ?>
              <?php $short = (float) $account['sms_units'] < (float) $p['units']; ?>
              <tr>
                <td>
                  <span class="fw-600"><?= e($p['client_name']) ?></span>
                  <?php if ($p['client_phone']): ?>
                    <div class="text-xs text-muted"><?= e($p['client_phone']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="num"><?= e(Present::units($p['units'])) ?></td>
                <td class="num fw-600"><?= e(money($p['amount'])) ?></td>
                <td class="code text-sm"><?= e($p['transaction_ref'] ?: '—') ?></td>
                <td class="text-sm text-muted"><?= e(fdate($p['created_at'])) ?></td>
                <td>
                  <?php if ($short): ?>
                    <div class="text-xs text-muted mb-8">
                      You hold <?= e(Present::units($account['sms_units'])) ?> — not enough.
                      <a href="<?= url($base . '/buy') ?>">Top up</a>.
                    </div>
                  <?php endif; ?>
                  <div class="btn-group">
                    <form method="post" action="<?= url($base . '/sales/' . (int) $p['id'] . '/approve') ?>"
                          data-confirm="Confirm you have been paid <?= e(money($p['amount'])) ?> and send <?= e(Present::units($p['units'])) ?> units to <?= e($p['client_name']) ?>?">
                      <?= csrf_field() ?>
                      <button class="btn btn--primary btn--sm" type="submit" <?= $short ? 'disabled' : '' ?>>Paid</button>
                    </form>
                    <form method="post" action="<?= url($base . '/sales/' . (int) $p['id'] . '/decline') ?>"
                          data-confirm="Mark this as not paid? No units move.">
                      <?= csrf_field() ?>
                      <button class="btn btn--ghost btn--sm" type="submit">Not paid</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Settled</div></div>

    <?php if (!$history): ?>
      <p class="text-sm text-muted">Nothing yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th style="width:120px">Date</th><th>Client</th><th class="num">Units</th><th class="num">Amount</th><th>How</th><th style="width:100px">State</th></tr></thead>
          <tbody>
            <?php foreach ($history as $h): ?>
              <?php [$cls, $word] = Present::purchase((string) $h['status']); ?>
              <tr>
                <td class="text-sm text-muted"><?= e(fdate($h['completed_at'] ?: $h['created_at'])) ?></td>
                <td class="text-sm"><?= e($h['client_name']) ?></td>
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
