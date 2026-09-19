<?php
/**
 * Delivery reports: every message in a period, laid out by the carrier's
 * own status so it can be checked against Onfon's report line for line.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\BulkSms\Accounts;
use App\Services\BulkSms\Present;

$section = 'messages';
$labelTotal = max(1, array_sum($byStatus));
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Delivery reports</h1>
    <div class="page-head__sub">
      <?= $account ? e(Accounts::describe($account)['name']) . ' · ' : 'Every account · ' ?>
      <?= e(fdate($from)) ?> to <?= e(fdate($to)) ?>
    </div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= e(query_string(['export' => 'csv', 'page' => null])) ?>"><?= icon('download') ?> Download CSV</a>
  </div>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="card">
  <div class="card__body">
    <form method="get" action="<?= url('/bulk-sms/messages') ?>" class="row-form">
      <?php if ($account): ?><input type="hidden" name="account" value="<?= (int) $account['id'] ?>"><?php endif; ?>
      <div class="field mb-0" style="max-width:160px">
        <label class="label" for="from">From</label>
        <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
      </div>
      <div class="field mb-0" style="max-width:160px">
        <label class="label" for="to">To</label>
        <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
      </div>
      <div class="field mb-0" style="flex:1;min-width:180px">
        <label class="label" for="q">Number or words</label>
        <input class="input" id="q" name="q" value="<?= e($filters['q']) ?>">
      </div>
      <div class="field mb-0" style="max-width:140px">
        <label class="label" for="sender">Sender ID</label>
        <input class="input" id="sender" name="sender" value="<?= e($filters['sender']) ?>">
      </div>
      <div class="field mb-0" style="max-width:190px">
        <label class="label" for="label">Carrier status</label>
        <select class="select" id="label" name="label">
          <option value="">Any</option>
          <?php foreach (array_keys($byStatus) as $l): ?>
            <option value="<?= e($l) ?>" <?= $filters['label'] === $l ? 'selected' : '' ?>><?= e(Present::label((string) $l)[1]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn--outline" type="submit"><?= icon('filter') ?> Show</button>
    </form>
  </div>
</div>

<div class="stat-grid mb-16">
  <div class="stat">
    <div class="stat__value"><?= number_format($totals['messages']) ?></div>
    <div class="stat__label">Messages</div>
  </div>
  <div class="stat stat--green">
    <div class="stat__value"><?= number_format($totals['delivered']) ?></div>
    <div class="stat__label">Delivered</div>
    <div class="stat__meta"><?= $totals['rate'] === null ? '—' : e($totals['rate']) . '% of those sent' ?></div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= number_format($totals['pending']) ?></div>
    <div class="stat__label">Still with the network</div>
  </div>
  <div class="stat <?= $totals['failed'] > 0 ? 'stat--red' : '' ?>">
    <div class="stat__value"><?= number_format($totals['failed']) ?></div>
    <div class="stat__label">Failed or undelivered</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__value"><?= e(Present::units($totals['units'])) ?></div>
    <div class="stat__label">Units charged</div>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <div>
      <div class="card__title">By carrier status</div>
      <div class="card__sub">The same columns as Onfon's own report, in the same order</div>
    </div>
  </div>
  <div class="table-wrap">
    <table class="table table--compact">
      <thead>
        <tr>
          <?php foreach ($byStatus as $l => $n): ?>
            <th class="num" title="<?= e(Present::label((string) $l)[1]) ?>"><?= e($l) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <tr>
          <?php foreach ($byStatus as $l => $n): ?>
            <td class="num">
              <a href="<?= e(query_string(['label' => $l, 'page' => null])) ?>" class="fw-600"><?= number_format($n) ?></a>
              <div class="text-xs text-muted"><?= $n > 0 ? round(100 * $n / $labelTotal, 1) . '%' : '' ?></div>
            </td>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('activity') ?></div>
      <div class="empty__title">No messages in that range</div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table table--compact">
        <thead>
          <tr>
            <th style="width:140px">When</th>
            <th style="width:100px">From</th>
            <th style="width:140px">To</th>
            <th>Message</th>
            <th class="num" style="width:60px">Units</th>
            <th style="width:200px">Outcome</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $m): ?>
            <?php [$lcls, $lword] = Present::label((string) $m['label']); ?>
            <tr>
              <td class="text-xs text-muted"><?= e(fdatetime($m['created_at'])) ?></td>
              <td class="code text-sm"><?= e($m['sender_id']) ?></td>
              <td class="code text-sm"><?= e($m['recipient']) ?></td>
              <td class="text-sm">
                <?= e(mb_strimwidth((string) $m['message'], 0, 80, '…')) ?>
                <?php if ($m['campaign_name']): ?>
                  <div class="text-xs text-muted"><a href="<?= url('/bulk-sms/campaigns/' . (int) $m['campaign_id']) ?>"><?= e($m['campaign_name']) ?></a></div>
                <?php endif; ?>
              </td>
              <td class="num text-sm"><?= e(Present::units($m['units_charged'])) ?></td>
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
