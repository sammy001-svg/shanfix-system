<?php
/**
 * Every campaign on the platform, newest first.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;

$section = 'campaigns';
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>SMS campaigns</h1>
    <div class="page-head__sub">What every client and partner has sent, is sending, or has scheduled</div>
  </div>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= url('/bulk-sms/campaigns') ?>" class="row-form">
      <div class="field mb-0" style="flex:1;min-width:220px">
        <label class="label" for="q">Search</label>
        <input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Campaign, sender ID or account">
      </div>
      <div class="field mb-0" style="max-width:200px">
        <label class="label" for="status">State</label>
        <select class="select" id="status" name="status">
          <option value="">Any</option>
          <?php foreach (['scheduled', 'queued', 'sending', 'completed', 'failed', 'cancelled'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(Present::campaign($s)[1]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn--outline" type="submit"><?= icon('filter') ?> Filter</button>
    </form>
  </div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('send') ?></div>
      <div class="empty__title">No campaigns <?= $q !== '' || $status !== '' ? 'match that' : 'yet' ?></div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Campaign</th>
            <th>Account</th>
            <th style="width:120px">State</th>
            <th style="width:200px">Progress</th>
            <th class="num" style="width:100px">Units</th>
            <th style="width:140px">When</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $c): ?>
            <?php [$cls, $word] = Present::campaign((string) $c['status']);
                  $done  = (int) $c['sent_count'] + (int) $c['failed_count'];
                  $total = max((int) $c['total_count'], $done);
                  $pct   = $total > 0 ? min(100, round(100 * $done / $total)) : 0; ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/bulk-sms/campaigns/' . (int) $c['id']) ?>"><?= e($c['name']) ?></a>
                <div class="text-xs text-muted code"><?= e($c['sender_id']) ?></div>
              </td>
              <td class="text-sm">
                <a href="<?= url('/bulk-sms/accounts/' . (int) $c['account_id']) ?>"><?= e($c['owner_name']) ?></a>
                <?php if ($c['source'] === 'api'): ?><span class="badge badge--grey">API</span><?php endif; ?>
              </td>
              <td><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></td>
              <td>
                <div class="progress"><div class="progress__bar <?= (int) $c['failed_count'] > (int) $c['sent_count'] ? 'progress__bar--red' : '' ?>" style="width:<?= $pct ?>%"></div></div>
                <div class="text-xs text-muted">
                  <?= number_format((int) $c['sent_count']) ?> sent
                  <?php if ((int) $c['failed_count'] > 0): ?>· <?= number_format((int) $c['failed_count']) ?> failed<?php endif; ?>
                  of <?= number_format($total) ?>
                </div>
              </td>
              <td class="num"><?= e(Present::units($c['units_used'])) ?></td>
              <td class="text-sm text-muted">
                <?= e(fdatetime($c['status'] === 'scheduled' ? $c['scheduled_at'] : $c['created_at'])) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php include APP_PATH . '/Views/partials/pagination.php'; ?>
  <?php endif; ?>
</div>
