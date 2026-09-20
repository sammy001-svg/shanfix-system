<?php
/**
 * The customer's own delivery report: what they sent and what reached a
 * handset.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Present;

?>

<div class="portal-wrap">
  <?php include __DIR__ . '/_nav.php'; ?>

  <div class="portal-card">
    <form method="get" action="<?= url($base . '/reports') ?>" class="row-form">
      <div class="field mb-0" style="max-width:160px">
        <label class="label" for="from">From</label>
        <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
      </div>
      <div class="field mb-0" style="max-width:160px">
        <label class="label" for="to">To</label>
        <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
      </div>
      <div class="field mb-0" style="flex:1">
        <label class="label" for="q">Number or words</label>
        <input class="input" id="q" name="q" value="<?= e($filters['q']) ?>">
      </div>
      <button class="btn btn--outline" type="submit"><?= icon('search') ?> Show</button>
      <a class="btn btn--ghost" href="<?= e(query_string(['export' => 'csv', 'page' => null])) ?>"><?= icon('download') ?> CSV</a>
    </form>
  </div>

  <div class="portal-stats">
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= number_format($totals['messages']) ?></div>
      <div class="portal-stat__label">Messages</div>
    </div>
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= number_format($totals['delivered']) ?></div>
      <div class="portal-stat__label">Delivered</div>
      <div class="portal-stat__note"><?= $totals['rate'] === null ? '—' : e($totals['rate']) . '% of those sent' ?></div>
    </div>
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= number_format($totals['pending']) ?></div>
      <div class="portal-stat__label">Still on their way</div>
    </div>
    <div class="portal-stat">
      <div class="portal-stat__figure"><?= e(Present::units($totals['units'])) ?></div>
      <div class="portal-stat__label">Units used</div>
    </div>
  </div>

  <?php $shown = array_filter($byStatus); ?>
  <?php if ($shown): ?>
    <div class="portal-card">
      <div class="portal-card__head"><div class="portal-card__title">What happened to them</div></div>
      <?php $max = max(1, ...array_values($shown)); ?>
      <div class="bars">
        <?php foreach ($shown as $l => $n): ?>
          <?php [$cls, $word] = Present::label((string) $l); ?>
          <a class="bar-row" href="<?= e(query_string(['label' => $l, 'page' => null])) ?>">
            <span class="bar-row__label"><?= e($word) ?></span>
            <span class="bar-row__track"><span class="bar-row__fill <?= str_contains($cls, 'red') ? 'bar-row__fill--red' : ($cls === 'badge--green' ? '' : 'bar-row__fill--navy') ?>" style="width:<?= number_format($n / $max * 100, 2) ?>%"></span></span>
            <span class="bar-row__value"><?= number_format((int) $n) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($filters['label'] !== ''): ?>
        <a class="btn btn--ghost btn--sm mt-8" href="<?= e(query_string(['label' => null, 'page' => null])) ?>">Show everything</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="portal-card">
    <div class="portal-card__head"><div class="portal-card__title">Messages</div></div>

    <?php if (!$rows): ?>
      <div class="portal-empty portal-empty--inline">
        <div class="portal-empty__icon"><?= icon('activity') ?></div>
        <div class="portal-empty__title">Nothing in this range</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead>
            <tr>
              <th style="width:130px">When</th>
              <th style="width:130px">To</th>
              <th>Message</th>
              <th class="num" style="width:60px">Units</th>
              <th style="width:170px">Outcome</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $m): ?>
              <?php [$cls, $word] = Present::label((string) $m['label']); ?>
              <tr>
                <td class="text-xs text-muted"><?= e(fdatetime($m['created_at'])) ?></td>
                <td class="code text-sm"><?= e($m['recipient']) ?></td>
                <td class="text-sm">
                  <?= e(mb_strimwidth((string) $m['message'], 0, 70, '…')) ?>
                  <?php if ($m['campaign_name']): ?>
                    <div class="text-xs text-muted"><?= e($m['campaign_name']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="num text-sm"><?= e(Present::units($m['units_charged'])) ?></td>
                <td>
                  <span class="badge <?= e($cls) ?>"><?= e($word) ?></span>
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
