<?php
/**
 * The commission ledger, as the partner reads it.
 *
 * One row per time a customer's payment earned them something, which is
 * also how the ledger is stored — so what they see and what we owe are
 * the same list, not two summaries that can disagree.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$rateOf = static fn(float $r): string => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';

$tabs = [
  ''       => 'All',
  'earned' => 'Owed to you',
  'paid'   => 'Paid out',
];
$tabUrl = static fn(string $k): string =>
  url('/partners/earnings' . ($k !== '' ? '?show=' . $k : ''));
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1">What you have earned</h1>
    <p class="portal-lede">
      Commission is earned the moment your customer pays us — a part payment
      earns a part of it.
    </p>
  </div>

  <div class="portal-stats">
    <div class="portal-stat">
      <span class="portal-stat__figure"><?= e(money($summary['due'], false)) ?></span>
      <span class="portal-stat__label">Owed to you</span>
    </div>
    <div class="portal-stat">
      <span class="portal-stat__figure"><?= e(money($summary['paid'], false)) ?></span>
      <span class="portal-stat__label">Paid out</span>
    </div>
    <div class="portal-stat">
      <span class="portal-stat__figure"><?= e(money($summary['earned'], false)) ?></span>
      <span class="portal-stat__label">Earned in total</span>
    </div>
  </div>

  <nav class="portal-tabs" aria-label="Filter">
    <?php foreach ($tabs as $key => $label): ?>
      <a class="portal-tab <?= $show === $key ? 'is-active' : '' ?>" href="<?= e($tabUrl($key)) ?>">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
    <div class="portal-card portal-empty">
      <span class="portal-empty__icon"><?= icon('receipt') ?></span>
      <div class="portal-empty__title">Nothing here</div>
      <p class="text-sm text-muted mb-0">
        Commission appears as soon as one of your customers pays an invoice.
      </p>
    </div>
  <?php else: ?>
    <div class="portal-card portal-card--flush">
      <ul class="portal-list">
        <?php foreach ($rows as $c): ?>
          <li>
            <span class="portal-list__row">
              <span class="portal-list__main">
                <span class="portal-list__title"><?= e($c['client_name']) ?></span>
                <span class="portal-list__meta">
                  <?= e($c['doc_number']) ?>
                  &middot; <?= e(fdate($c['created_at'])) ?>
                  &middot; <?= e($rateOf((float) $c['rate'])) ?> of <?= e(money($c['base_amount'], false)) ?>
                  <?php if ($c['status'] === 'paid' && $c['payout_ref']): ?>
                    &middot; ref <?= e($c['payout_ref']) ?>
                  <?php endif; ?>
                </span>
              </span>
              <span class="portal-list__side">
                <span class="portal-list__amount"><?= e(money($c['amount'], false)) ?></span>
                <span class="badge badge--<?= $c['status'] === 'paid' ? 'green' : 'amber' ?>">
                  <?= $c['status'] === 'paid' ? 'Paid out' : 'Owed to you' ?>
                </span>
              </span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="portal-pager" aria-label="Pages">
        <?php $q = static fn(int $n): string =>
          url('/partners/earnings?' . http_build_query(array_filter(['show' => $show, 'page' => $n > 1 ? $n : null]))); ?>

        <a class="btn btn--outline btn--sm <?= $page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= e($q(max(1, $page - 1))) ?>"<?= $page <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
          <?= icon('chevron-left') ?> Newer
        </a>
        <span class="portal-pager__at">
          Page <?= (int) $page ?> of <?= (int) $pages ?>
          <span class="text-muted">&middot; <?= (int) $total ?> in total</span>
        </span>
        <a class="btn btn--outline btn--sm <?= $page >= $pages ? 'is-disabled' : '' ?>"
           href="<?= e($q(min($pages, $page + 1))) ?>"<?= $page >= $pages ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
          Older <?= icon('chevron-right') ?>
        </a>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
