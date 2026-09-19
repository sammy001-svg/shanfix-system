<?php
/**
 * The SMS platform at a glance: what we hold with the gateway, what our
 * customers hold, what is going out now and what is waiting on us.
 */
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Auth;
use App\Services\BulkSms\Present;

$section = 'overview';

$chartMax = max(1, ...array_map(static fn(array $d): int => $d['sent'] + $d['failed'], $daily));
$houseUnits = (float) $house['sms_units'];

// The house should hold at least what customers could spend — every unit
// they hold was sold out of it and is owed to them by the gateway.
$covered = $houseUnits >= $held;
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>SMS platform</h1>
    <div class="page-head__sub">Bulk SMS for our clients and partners, sent through Onfon</div>
  </div>
  <div class="page-head__actions">
    <?php if (Auth::can('bulksms.manage')): ?>
      <form method="post" action="<?= url('/bulk-sms/sync') ?>">
        <?= csrf_field() ?>
        <button class="btn btn--outline" type="submit"><?= icon('refresh') ?> Sync with Onfon</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_nav.php'; ?>

<?php if (!$configured): ?>
  <div class="alert alert--warning">
    <?= icon('alert-triangle') ?>
    <div class="alert__body">
      The gateway is not set up yet, so nothing can be sent.
      <?php if (Auth::can('bulksms.settings')): ?>
        <a href="<?= url('/bulk-sms/settings') ?>"><strong>Add the Onfon keys</strong></a>.
      <?php else: ?>
        An administrator needs to add the Onfon keys.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($waiting['senders'] > 0 || $waiting['purchases'] > 0): ?>
  <div class="alert alert--info">
    <?= icon('inbox') ?>
    <div class="alert__body">
      Waiting on us:
      <?php if ($waiting['senders'] > 0): ?>
        <a href="<?= url('/bulk-sms/sender-ids') ?>"><strong><?= $waiting['senders'] ?> sender ID<?= $waiting['senders'] === 1 ? '' : 's' ?></strong></a>
      <?php endif; ?>
      <?php if ($waiting['senders'] > 0 && $waiting['purchases'] > 0): ?> and <?php endif; ?>
      <?php if ($waiting['purchases'] > 0): ?>
        <a href="<?= url('/bulk-sms/purchases?status=pending') ?>"><strong><?= $waiting['purchases'] ?> purchase<?= $waiting['purchases'] === 1 ? '' : 's' ?></strong></a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="stat-grid mb-16">
  <div class="stat <?= $covered ? 'stat--navy' : 'stat--red' ?>">
    <div class="stat__value"><?= e(Present::units($houseUnits)) ?></div>
    <div class="stat__label">Units we hold with Onfon</div>
    <div class="stat__meta">
      <?= $covered
          ? 'Covers everything customers hold'
          : 'Less than customers hold — top up the gateway' ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= e(Present::units($held)) ?></div>
    <div class="stat__label">Units customers and partners hold</div>
    <div class="stat__meta"><?= $accounts['client'] ?> client · <?= $accounts['partner'] ?> partner accounts</div>
  </div>
  <div class="stat stat--green">
    <div class="stat__value"><?= number_format($today['sent']) ?></div>
    <div class="stat__label">Sent today</div>
    <div class="stat__meta"><?= number_format($today['failed']) ?> failed</div>
  </div>
  <div class="stat">
    <div class="stat__value"><?= $month['rate'] === null ? '—' : e($month['rate']) . '%' ?></div>
    <div class="stat__label">Delivered, last 30 days</div>
    <div class="stat__meta"><?= number_format($month['sent']) ?> sent · <?= e(Present::units($month['units'])) ?> units</div>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">The last two weeks</div>
          <div class="card__sub">Messages handed to the network, and those that failed</div>
        </div>
        <div class="card__actions">
          <a class="btn btn--ghost btn--sm" href="<?= url('/bulk-sms/messages') ?>">Delivery reports</a>
        </div>
      </div>
      <div class="card__body">
        <div class="chart-columns">
          <?php foreach ($daily as $d): ?>
            <div class="chart-col" title="<?= e(fdate($d['day'])) ?>: <?= $d['sent'] ?> sent, <?= $d['delivered'] ?> delivered, <?= $d['failed'] ?> failed">
              <div class="chart-col__stack">
                <div class="chart-col__bar" style="height:<?= number_format($d['failed'] / $chartMax * 100, 2) ?>%;background:var(--red-600)"></div>
                <div class="chart-col__bar chart-col__bar--green" style="height:<?= number_format($d['sent'] / $chartMax * 100, 2) ?>%"></div>
              </div>
              <div class="chart-col__label"><?= e(date('j M', strtotime($d['day']))) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="chart-legend">
          <span class="chart-legend__key"><span class="chart-legend__swatch" style="background:var(--green-600)"></span> Sent</span>
          <span class="chart-legend__key"><span class="chart-legend__swatch" style="background:var(--red-600)"></span> Failed</span>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Going out now</div>
          <div class="card__sub">Sending, queued and scheduled campaigns</div>
        </div>
        <div class="card__actions">
          <a class="btn btn--ghost btn--sm" href="<?= url('/bulk-sms/campaigns') ?>">All campaigns</a>
        </div>
      </div>
      <?php if (!$sending): ?>
        <div class="card__body text-center text-sm text-muted">Nothing is sending or waiting to send.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Campaign</th><th>Account</th><th style="width:140px">State</th><th style="width:200px">Progress</th></tr></thead>
            <tbody>
              <?php foreach ($sending as $c): ?>
                <?php [$cls, $word] = Present::campaign((string) $c['status']);
                      $done = (int) $c['sent_count'] + (int) $c['failed_count'];
                      $pct  = (int) $c['total_count'] > 0 ? min(100, round(100 * $done / (int) $c['total_count'])) : 0; ?>
                <tr>
                  <td><a class="fw-600" href="<?= url('/bulk-sms/campaigns/' . (int) $c['id']) ?>"><?= e($c['name']) ?></a>
                    <div class="text-xs text-muted"><?= e($c['sender_id']) ?></div></td>
                  <td class="text-sm"><?= e($c['owner_name']) ?></td>
                  <td>
                    <span class="badge <?= e($cls) ?>"><?= e($word) ?></span>
                    <?php if ($c['status'] === 'scheduled'): ?>
                      <div class="text-xs text-muted"><?= e(fdatetime($c['scheduled_at'])) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="progress"><div class="progress__bar" style="width:<?= $pct ?>%"></div></div>
                    <div class="text-xs text-muted"><?= number_format($done) ?> of <?= number_format((int) $c['total_count']) ?></div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Biggest senders</div>
          <div class="card__sub">Units used in the last 30 days</div>
        </div>
      </div>
      <?php if (!$top): ?>
        <div class="card__body text-sm text-muted">Nobody has sent anything yet.</div>
      <?php else: ?>
        <?php $topMax = max(1.0, ...array_map(static fn(array $t): float => (float) $t['units'], $top)); ?>
        <div class="card__body">
          <div class="bars">
            <?php foreach ($top as $t): ?>
              <a class="bar-row" href="<?= url('/bulk-sms/accounts/' . (int) $t['id']) ?>">
                <span class="bar-row__label"><?= e($t['owner_name']) ?></span>
                <span class="bar-row__track"><span class="bar-row__fill <?= $t['owner_type'] === 'partner' ? 'bar-row__fill--navy' : '' ?>" style="width:<?= number_format((float) $t['units'] / $topMax * 100, 2) ?>%"></span></span>
                <span class="bar-row__value"><?= e(Present::units($t['units'])) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card__body text-sm">
        <div class="card__title mb-8">How units move</div>
        <p class="text-muted mb-8">
          Everything is sold out of the house balance, which should always
          match what Onfon says we hold. Partners buy from the house and sell
          on to their own clients; everyone else buys from the house directly.
        </p>
        <p class="text-muted mb-0">
          A message the network refuses is refunded straight away, so a
          customer only ever pays for what went out.
        </p>
      </div>
    </div>
  </div>
</div>
