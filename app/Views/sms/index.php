<?php
/**
 * Past campaigns.
 *
 * @var array $campaigns
 * @var array $pager
 * @var bool  $smsOn
 */
require_once APP_PATH . '/Views/partials/icons.php';

$badge = static function (string $status): string {
    return match ($status) {
        'sent'    => 'badge--green',
        'partial' => 'badge--amber',
        'failed'  => 'badge--red',
        default   => 'badge--grey',
    };
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>SMS campaigns</h1>
    <div class="page-head__sub">One message sent to many clients at once.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/notifications') ?>">
      <?= icon('mail') ?> Message log
    </a>
    <a class="btn btn--primary" href="<?= url('/sms-campaigns/new') ?>">
      <?= icon('plus') ?> New campaign
    </a>
  </div>
</div>

<?php if (!$smsOn): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      SMS is switched off, so campaigns cannot be sent.
      <a href="<?= url('/settings?tab=messaging') ?>">Turn it on in Settings</a>.
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <?php if (!$campaigns): ?>
    <div class="card__body">
      <div class="empty">
        <div class="empty__icon"><?= icon('message') ?></div>
        <div class="empty__title">No campaigns yet</div>
        <p class="empty__text">
          Send one message to a whole group of clients — a price change, a
          holiday closure, a promotion. You see the exact recipient count and
          credit cost before anything goes out.
        </p>
        <a class="btn btn--primary mt-12" href="<?= url('/sms-campaigns/new') ?>">
          <?= icon('plus') ?> New campaign
        </a>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Campaign</th>
            <th>Audience</th>
            <th style="width:90px">Sent</th>
            <th style="width:90px">Skipped</th>
            <th style="width:100px">Credits</th>
            <th style="width:110px">Status</th>
            <th style="width:150px">When</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($campaigns as $c): ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/sms-campaigns/' . $c['id']) ?>">
                  <?= e($c['title']) ?>
                </a>
                <div class="text-xs text-muted"><?= e(str_excerpt($c['message'], 70)) ?></div>
              </td>
              <td class="text-sm"><?= e($c['audience'] ?? '') ?></td>
              <td><?= number_format((int) $c['sent']) ?></td>
              <td>
                <?php $skipped = (int) $c['invalid'] + (int) $c['failed']; ?>
                <?= $skipped > 0 ? '<span class="text-muted">' . number_format($skipped) . '</span>' : '—' ?>
              </td>
              <td><?= $c['cost'] !== null ? number_format((float) $c['cost'], 2) : '—' ?></td>
              <td><span class="badge <?= $badge($c['status']) ?>"><?= e(label_of($c['status'])) ?></span></td>
              <td class="text-sm text-muted">
                <?= e(time_ago($c['created_at'])) ?>
                <?php if ($c['sent_by']): ?>
                  <div class="text-xs">by <?= e($c['sent_by']) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($campaigns) ?> of <?= number_format($pager['total']) ?></span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
