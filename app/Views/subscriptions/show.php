<?php
require_once APP_PATH . '/Views/partials/icons.php';

$days = (int) floor((strtotime($sub['next_renewal_date']) - strtotime(date('Y-m-d'))) / 86400);

if ($days < 0)       { $tone = 'red';   $when = abs($days) . ' day(s) past the renewal date'; }
elseif ($days === 0) { $tone = 'red';   $when = 'Renews today'; }
elseif ($days <= 30) { $tone = 'amber'; $when = 'Renews in ' . $days . ' day(s)'; }
else                 { $tone = 'green'; $when = 'Renews in ' . $days . ' day(s)'; }

$owing = array_sum(array_map(
    static fn(array $r): float => (float) ($r['balance'] ?? 0),
    $renewals
));
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="text-xs text-muted mb-4">
      <a href="<?= url('/subscriptions') ?>">Recurring services</a> /
      <a href="<?= url('/clients/' . $client['id']) ?>"><?= e($client['name']) ?></a>
    </div>
    <h1><?= e($sub['name']) ?></h1>
    <div class="page-head__sub">
      <?= e($types[$sub['service_type']] ?? 'Service') ?>
      · <?= e($cycles[$sub['billing_cycle']] ?? '') ?>
      · <?= e(money($sub['amount'])) ?> per renewal
    </div>
  </div>
  <div class="page-head__actions">
    <?php if ($sub['url']): ?>
      <a class="btn btn--outline" href="<?= e($sub['url']) ?>" target="_blank" rel="noopener noreferrer">
        <?= icon('external-link') ?> Visit site
      </a>
    <?php endif; ?>

    <?php if (can('subscriptions.manage')): ?>
      <a class="btn btn--outline" href="<?= url('/subscriptions/' . $sub['id'] . '/edit') ?>">
        <?= icon('edit') ?> Edit
      </a>

      <?php if ($sub['status'] === 'active'): ?>
        <form method="post" action="<?= url('/subscriptions/' . $sub['id'] . '/invoice') ?>" style="display:inline"
              data-confirm="Raise the renewal invoice for the period starting <?= e(fdate($period['start'])) ?>?">
          <?= csrf_field() ?>
          <button class="btn btn--primary" type="submit"><?= icon('receipt') ?> Invoice this renewal</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--<?= e($tone) ?>">
    <div class="stat__label">Next renewal</div>
    <div class="stat__value"><?= e(fdate($sub['next_renewal_date'])) ?></div>
    <div class="stat__meta"><?= e($when) ?></div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Amount per renewal</div>
    <div class="stat__value"><?= e(money_short($sub['amount'])) ?></div>
    <div class="stat__meta"><?= e($cycles[$sub['billing_cycle']] ?? '') ?></div>
  </div>
  <div class="stat stat--<?= $owing > 0 ? 'red' : 'green' ?>">
    <div class="stat__label">Outstanding</div>
    <div class="stat__value"><?= e(money_short($owing)) ?></div>
    <div class="stat__meta"><?= $owing > 0 ? 'Unpaid renewal invoices' : 'Nothing owing' ?></div>
  </div>
  <div class="stat stat--grey">
    <div class="stat__label">Status</div>
    <div class="stat__value" style="font-size:19px"><?= e(label_of($sub['status'])) ?></div>
    <div class="stat__meta">
      <?= $sub['auto_invoice'] ? 'Invoices automatically' : 'Invoiced by hand' ?>
    </div>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <div class="card">
      <div class="card__head">
        <div class="card__title">Billing history</div>
      </div>

      <?php if (!$renewals): ?>
        <div class="empty">
          <div class="empty__icon"><?= icon('file-text') ?></div>
          <div class="empty__title">Nothing invoiced yet</div>
          <p class="empty__text">
            The next period runs <?= e(fdate($period['start'])) ?> to <?= e(fdate($period['end'])) ?>.
          </p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Period</th>
                <th>Invoice</th>
                <th class="num">Amount</th>
                <th class="num">Balance</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($renewals as $r): ?>
                <tr>
                  <td class="text-sm">
                    <?= e(fdate($r['period_start'])) ?> – <?= e(fdate($r['period_end'])) ?>
                  </td>
                  <td>
                    <?php if ($r['document_id']): ?>
                      <a href="<?= url('/invoices/' . $r['document_id']) ?>"><?= e($r['doc_number']) ?></a>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="num"><?= e(money($r['amount'], false)) ?></td>
                  <td class="num">
                    <?php if ((float) ($r['balance'] ?? 0) > 0): ?>
                      <span class="text-red fw-600"><?= e(money($r['balance'], false)) ?></span>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge <?= e(status_badge($r['status'])) ?>"><?= e(label_of($r['status'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <aside>
    <div class="card">
      <div class="card__head"><div class="card__title">Details</div></div>
      <div class="card__body">
        <dl class="dl">
          <dt>Client</dt>
          <dd><a href="<?= url('/clients/' . $client['id']) ?>"><?= e($client['name']) ?></a></dd>

          <?php if ($sub['url']): ?>
            <dt>Address</dt>
            <dd>
              <a href="<?= e($sub['url']) ?>" target="_blank" rel="noopener noreferrer">
                <?= e(str_excerpt(preg_replace('~^https?://~', '', $sub['url']), 34)) ?>
              </a>
            </dd>
          <?php endif; ?>

          <dt>Started</dt>
          <dd><?= e(fdate($sub['start_date'])) ?></dd>

          <dt>Last invoiced</dt>
          <dd><?= $sub['last_invoiced_on'] ? e(fdate($sub['last_invoiced_on'])) : '—' ?></dd>

          <dt>Next period</dt>
          <dd><?= e(fdate($period['start'])) ?> – <?= e(fdate($period['end'])) ?></dd>

          <dt>Reminders</dt>
          <dd>
            <?= $sub['reminder_days'] !== ''
                ? e($sub['reminder_days']) . ' days before'
                : 'System default' ?>
          </dd>
        </dl>

        <?php if ($sub['notes']): ?>
          <hr>
          <div class="text-sm"><?= nl2br(e($sub['notes'])) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (can('subscriptions.manage')): ?>
      <div class="card">
        <div class="card__body">
          <form method="post" action="<?= url('/subscriptions/' . $sub['id'] . '/delete') ?>"
                data-confirm="Remove <?= e($sub['name']) ?>? Anything already invoiced is kept.">
            <?= csrf_field() ?>
            <button class="btn btn--danger-soft btn--sm" type="submit"><?= icon('trash') ?> Remove service</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>
