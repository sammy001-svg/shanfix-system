<?php
/**
 * One campaign, from the customer's side. While it is sending, the
 * progress bar moves on its own.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;

[$cls, $word] = Present::campaign((string) $c['status']);
$done    = (int) $c['sent_count'] + (int) $c['failed_count'];
$total   = max((int) $c['total_count'], $done);
$pct     = $total > 0 ? min(100, round(100 * $done / $total)) : 0;
$running = in_array($c['status'], ['queued', 'sending', 'scheduled'], true);
$campaign = $base . '/campaigns/' . (int) $c['id'];
?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title"><?= e($c['name']) ?> <span class="badge <?= e($cls) ?>"><?= e($word) ?></span></div>
      <?php if ($running): ?>
        <form method="post" action="<?= url($campaign . '/cancel') ?>"
              data-confirm="Stop this campaign? Messages already sent cannot be recalled.">
          <?= csrf_field() ?>
          <button class="btn btn--ghost btn--sm" type="submit"><?= icon('x-circle') ?> Stop it</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($c['failure_reason']): ?>
      <div class="alert alert--warning">
        <?= icon('alert-triangle') ?>
        <div class="alert__body"><?= e($c['failure_reason']) ?></div>
      </div>
    <?php endif; ?>

    <?php if ($c['status'] === 'scheduled'): ?>
      <p class="text-sm text-muted">
        Waiting until <strong><?= e(fdatetime($c['scheduled_at'])) ?></strong>. It goes out on its own.
      </p>
    <?php endif; ?>

    <div <?= $running ? 'data-sms-progress="' . e(url($campaign)) . '"' : '' ?>>
      <div class="progress"><div class="progress__bar" data-sms-bar style="width:<?= $pct ?>%"></div></div>
      <div class="text-sm text-muted mt-8" data-sms-note>
        <?= number_format($done) ?> of <?= number_format($total) ?>
        · <?= number_format((int) $c['failed_count']) ?> failed
      </div>
    </div>

    <div class="portal-stats mt-8">
      <div class="portal-stat">
        <div class="portal-stat__figure"><?= number_format((int) $c['sent_count']) ?></div>
        <div class="portal-stat__label">Sent</div>
      </div>
      <div class="portal-stat">
        <div class="portal-stat__figure"><?= number_format((int) $c['failed_count']) ?></div>
        <div class="portal-stat__label">Failed</div>
      </div>
      <div class="portal-stat">
        <div class="portal-stat__figure"><?= e(Present::units($c['units_used'])) ?></div>
        <div class="portal-stat__label">Units used</div>
        <div class="portal-stat__note"><?= $size['parts'] ?> part<?= $size['parts'] === 1 ? '' : 's' ?> each</div>
      </div>
    </div>

    <div class="portal-card portal-card--quiet mt-8">
      <div class="text-sm" style="white-space:pre-wrap"><?= e($c['message']) ?></div>
    </div>
  </div>

  <?php if ($labels): ?>
    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">What happened</div></div>
      <?php $lmax = max(1, ...array_values($labels)); ?>
      <div class="bars">
        <?php foreach ($labels as $l => $n): ?>
          <?php [$lcls, $lword] = Present::label((string) $l); ?>
          <a class="bar-row" href="<?= url($campaign . '?label=' . rawurlencode((string) $l)) ?>">
            <span class="bar-row__label"><?= e($lword) ?></span>
            <span class="bar-row__track"><span class="bar-row__fill <?= str_contains($lcls, 'red') ? 'bar-row__fill--red' : ($lcls === 'badge--green' ? '' : 'bar-row__fill--navy') ?>" style="width:<?= number_format($n / $lmax * 100, 2) ?>%"></span></span>
            <span class="bar-row__value"><?= number_format((int) $n) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="portal-card">
    <div class="portal-card__head">
      <div class="portal-card__title">Messages</div>
      <?php if ($label !== ''): ?><a class="portal-card__more" href="<?= url($campaign) ?>">Show all</a><?php endif; ?>
    </div>

    <?php if (!$messages): ?>
      <p class="text-sm text-muted"><?= $running ? 'Nothing has gone yet.' : 'No messages match.' ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th style="width:140px">To</th><th>Message</th><th style="width:180px">Outcome</th></tr></thead>
          <tbody>
            <?php foreach ($messages as $m): ?>
              <?php [$lcls, $lword] = Present::label((string) $m['label']); ?>
              <tr>
                <td class="code text-sm"><?= e($m['recipient']) ?></td>
                <td class="text-sm"><?= e(mb_strimwidth((string) $m['message'], 0, 70, '…')) ?></td>
                <td>
                  <span class="badge <?= e($lcls) ?>"><?= e($lword) ?></span>
                  <?php if ($m['failed_reason']): ?><div class="text-xs text-muted"><?= e($m['failed_reason']) ?></div><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php include APP_PATH . '/Views/partials/pagination.php'; ?>
    <?php endif; ?>
  </div>
</div>
