<?php
/**
 * One campaign: what it said, who it reached, and what happened to each
 * message — filterable by the carrier's own status.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\BulkSms\Present;

$section   = 'campaigns';
$canManage = Auth::can('bulksms.manage');
[$cls, $word] = Present::campaign((string) $c['status']);
$done  = (int) $c['sent_count'] + (int) $c['failed_count'];
$total = max((int) $c['total_count'], $done);
$base  = '/bulk-sms/campaigns/' . (int) $c['id'];
?>

<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($c['name']) ?> <span class="badge <?= e($cls) ?>"><?= e($word) ?></span></h1>
    <div class="page-head__sub">
      <a href="<?= url('/bulk-sms/accounts/' . (int) $c['account_id']) ?>"><?= e($owner['name']) ?></a>
      &middot; from <span class="code"><?= e($c['sender_id']) ?></span>
      &middot; <?= $c['source'] === 'api' ? 'through the API' : 'from the ' . e($c['source']) ?>
      &middot; <?= e(fdatetime($c['created_at'])) ?>
    </div>
  </div>
  <?php if ($canManage): ?>
    <div class="page-head__actions">
      <?php if (in_array($c['status'], ['scheduled', 'queued', 'sending'], true)): ?>
        <form method="post" action="<?= url($base . '/cancel') ?>" data-confirm="Stop this campaign? What has gone cannot be recalled.">
          <?= csrf_field() ?>
          <button class="btn btn--danger-soft" type="submit"><?= icon('x-circle') ?> Stop it</button>
        </form>
      <?php elseif (in_array($c['status'], ['failed', 'cancelled'], true)): ?>
        <form method="post" action="<?= url($base . '/retry') ?>" data-confirm="Carry on sending to the recipients it has not reached yet?">
          <?= csrf_field() ?>
          <button class="btn btn--primary" type="submit"><?= icon('refresh') ?> Carry on sending</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if ($c['failure_reason']): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body"><?= e($c['failure_reason']) ?></div>
  </div>
<?php endif; ?>

<div class="stat-grid mb-16">
  <div class="stat stat--green">
    <div class="stat__value"><?= number_format((int) $c['sent_count']) ?></div>
    <div class="stat__label">Sent</div>
  </div>
  <div class="stat <?= (int) $c['failed_count'] > 0 ? 'stat--red' : '' ?>">
    <div class="stat__value"><?= number_format((int) $c['failed_count']) ?></div>
    <div class="stat__label">Failed</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= number_format($total) ?></div>
    <div class="stat__label">Recipients</div>
    <?php if ($c['status'] === 'sending' && $total > 0): ?>
      <div class="stat__meta"><?= min(100, round(100 * $done / $total)) ?>% through</div>
    <?php endif; ?>
  </div>
  <div class="stat stat--navy">
    <div class="stat__value"><?= e(Present::units($c['units_used'])) ?></div>
    <div class="stat__label">Units used</div>
    <div class="stat__meta"><?= $size['parts'] ?> part<?= $size['parts'] === 1 ? '' : 's' ?> each<?= $size['unicode'] ? ' · Unicode' : '' ?></div>
  </div>
</div>

<div class="grid-sidebar">
  <div class="card">
    <div class="card__head">
      <div class="card__title">Messages</div>
      <?php if ($label !== ''): ?>
        <div class="card__actions"><a class="btn btn--ghost btn--sm" href="<?= url($base) ?>">Show all</a></div>
      <?php endif; ?>
    </div>
    <?php if (!$messages): ?>
      <div class="card__body text-sm text-muted">
        <?= in_array($c['status'], ['scheduled', 'queued'], true) ? 'Nothing has gone yet.' : 'No messages match.' ?>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th style="width:150px">To</th><th>Message</th><th style="width:190px">Outcome</th><th style="width:130px">When</th></tr></thead>
          <tbody>
            <?php foreach ($messages as $m): ?>
              <?php [$lcls, $lword] = Present::label((string) $m['label']); ?>
              <tr>
                <td class="code text-sm"><?= e($m['recipient']) ?></td>
                <td class="text-sm"><?= e(mb_strimwidth((string) $m['message'], 0, 90, '…')) ?></td>
                <td>
                  <span class="badge <?= e($lcls) ?>"><?= e($lword) ?></span>
                  <?php if ($m['failed_reason']): ?><div class="text-xs text-muted"><?= e($m['failed_reason']) ?></div><?php endif; ?>
                </td>
                <td class="text-xs text-muted"><?= e(fdatetime($m['delivered_at'] ?: ($m['sent_at'] ?: $m['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php include APP_PATH . '/Views/partials/pagination.php'; ?>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card__head"><div class="card__title">The message</div></div>
      <div class="card__body">
        <div class="text-sm" style="white-space:pre-wrap"><?= e($c['message']) ?></div>
        <div class="text-xs text-muted mt-8"><?= $size['length'] ?> characters</div>
      </div>
    </div>

    <?php if ($labels): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">What happened</div></div>
        <div class="card__body">
          <?php $lmax = max(1, ...array_values($labels)); ?>
          <div class="bars">
            <?php foreach ($labels as $l => $n): ?>
              <?php [$lcls, $lword] = Present::label((string) $l); ?>
              <a class="bar-row" href="<?= url($base . '?label=' . rawurlencode((string) $l)) ?>" title="Show only these">
                <span class="bar-row__label"><?= e($lword) ?></span>
                <span class="bar-row__track"><span class="bar-row__fill <?= str_contains($lcls, 'red') ? 'bar-row__fill--red' : ($lcls === 'badge--green' ? '' : 'bar-row__fill--navy') ?>" style="width:<?= number_format($n / $lmax * 100, 2) ?>%"></span></span>
                <span class="bar-row__value"><?= number_format((int) $n) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
