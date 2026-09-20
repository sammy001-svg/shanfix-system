<?php
/**
 * A customer's own campaigns.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;

$smsTab = 'campaigns';
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">Campaigns</div>
      <a class="btn btn--primary btn--sm" href="<?= url($base . '/campaigns/new') ?>"><?= icon('plus') ?> New campaign</a>
    </div>

    <?php if (!$rows): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('send') ?></div>
        <div class="portal-empty__title">No campaigns yet</div>
        <p class="text-sm text-muted">One message, a whole list, sent in the background.</p>
      </div>
    <?php else: ?>
      <ul class="portal-list">
        <?php foreach ($rows as $c): ?>
          <?php [$cls, $word] = Present::campaign((string) $c['status']);
                $done  = (int) $c['sent_count'] + (int) $c['failed_count'];
                $total = max((int) $c['total_count'], $done);
                $pct   = $total > 0 ? min(100, round(100 * $done / $total)) : 0; ?>
          <li class="portal-list__row">
            <a class="portal-list__main" href="<?= url($base . '/campaigns/' . (int) $c['id']) ?>">
              <span class="portal-list__title"><?= e($c['name']) ?></span>
              <span class="portal-list__meta">
                <?= e($c['sender_id']) ?>
                · <?= e($c['status'] === 'scheduled' ? 'for ' . fdatetime($c['scheduled_at']) : fdate($c['created_at'])) ?>
                · <?= e(Present::units($c['units_used'])) ?> units
              </span>
              <?php if (in_array($c['status'], ['sending', 'queued'], true)): ?>
                <span class="progress" style="margin-top:6px"><span class="progress__bar" style="width:<?= $pct ?>%"></span></span>
              <?php endif; ?>
            </a>
            <span class="portal-list__side">
              <span class="badge <?= e($cls) ?>"><?= e($word) ?></span>
              <span class="portal-list__meta d-block">
                <?= number_format((int) $c['sent_count']) ?> sent<?php if ((int) $c['failed_count'] > 0): ?>, <?= number_format((int) $c['failed_count']) ?> failed<?php endif; ?>
              </span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php include APP_PATH . '/Views/partials/pagination.php'; ?>
    <?php endif; ?>
  </div>
</div>
