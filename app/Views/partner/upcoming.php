<?php
/**
 * Their customers' renewals: future commission with a date on it.
 *
 * Staff could already see this on the partner's page. The partner could
 * not, which made the most useful thing about a recurring customer
 * invisible to the person who introduced them.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$rateOf = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">What is coming</h1>
    <p class="portal-lede">
      Your customers' recurring services, when each one falls due, and what
      it earns you when they pay.
    </p>
  </div>

  <?php if (!$rows): ?>
    <div class="portal-card portal-empty">
      <span class="portal-empty__icon"><?= icon('repeat') ?></span>
      <div class="portal-empty__title">Nothing recurring yet</div>
      <p class="text-sm text-muted mb-0">
        Hosting, domains and retainers renew on a date, so they earn you
        commission again every time. They will appear here as your customers
        take them on.
      </p>
    </div>
  <?php else: ?>
    <section class="portal-balance">
      <div class="portal-balance__body">
        <div class="portal-balance__label">Due to you over the next year</div>
        <div class="portal-balance__figure"><?= e(money($expected)) ?></div>
        <div class="portal-balance__note">
          If every renewal below is taken up and paid. Commission lands when
          the customer pays, not on the renewal date itself.
        </div>
      </div>
    </section>

    <div class="portal-card portal-card--flush">
      <ul class="portal-list">
        <?php foreach ($rows as $r): ?>
          <?php
            $days = (int) $r['days_away'];
            $late = $days < 0;
            $soon = $days >= 0 && $days <= 30;
          ?>
          <li>
            <span class="portal-list__row">
              <span class="portal-list__main">
                <span class="portal-list__title"><?= e($r['name']) ?></span>
                <span class="portal-list__meta">
                  <?= e($r['client_name']) ?>
                  &middot; <?= e(money($r['amount'], false)) ?>
                  <?= e(\App\Services\Renewals::cyclePhrase($r['billing_cycle'])) ?>
                </span>
              </span>
              <span class="portal-list__side">
                <span class="portal-list__figures">
                  <span class="portal-list__amount"><?= e(money($r['expected'], false)) ?></span>
                  <span class="partner-cut">yours at <?= e($rateOf((float) $r['effective_rate'])) ?></span>
                </span>
                <span class="badge badge--<?= $late ? 'red' : ($soon ? 'amber' : 'grey') ?>">
                  <?= $late
                    ? abs($days) . 'd overdue'
                    : ($days === 0 ? 'Today' : 'in ' . $days . 'd') ?>
                </span>
                <span class="portal-list__meta"><?= e(fdate($r['next_renewal_date'])) ?></span>
              </span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
