<?php
/**
 * Quotations, or invoices — the same list with a different filter.
 *
 * Rows rather than a spreadsheet. A client is scanning for one document, or
 * for what is still owed; the seven-column table this replaced made them
 * read across a line to find out either.
 */
require_once APP_PATH . '/Views/partials/icons.php';

$isInvoice = $type === 'invoice';
$listPath  = $isInvoice ? '/portal/invoices' : '/portal/quotations';

// What a status means to the person reading it, rather than to us.
$tone = static fn(string $s): string => match ($s) {
    'paid', 'accepted'                 => 'green',
    'overdue'                          => 'red',
    'partial'                          => 'amber',
    'rejected', 'expired', 'cancelled' => 'grey',
    default                            => 'navy',
};

$tabs = [
    ''        => ['All', $counts['all']],
    'open'    => [$isInvoice ? 'Outstanding' : 'Open', $counts['open']],
    'settled' => [$isInvoice ? 'Settled' : 'Closed', $counts['settled']],
];

$tabUrl = static fn(string $k): string => url($listPath . ($k !== '' ? '?show=' . $k : ''));
?>

<div class="portal-wrap">
  <div class="portal-hello">
    <h1 class="portal-h1"><?= $isInvoice ? 'Your invoices' : 'Your quotations' ?></h1>
    <p class="portal-lede">
      <?= $isInvoice
        ? 'Everything we have billed you, and what is still outstanding.'
        : 'Prices we have quoted you. Ask us anything that is not clear.' ?>
    </p>
  </div>

  <nav class="portal-tabs" aria-label="Filter">
    <?php foreach ($tabs as $key => [$label, $n]): ?>
      <a class="portal-tab <?= $show === $key ? 'is-active' : '' ?>" href="<?= e($tabUrl($key)) ?>">
        <?= e($label) ?>
        <span class="portal-tab__count"><?= (int) $n ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
    <div class="portal-card portal-empty">
      <span class="portal-empty__icon"><?= icon('file-text') ?></span>
      <div class="portal-empty__title">
        <?= $show !== '' ? 'Nothing under this filter' : 'Nothing here yet' ?>
      </div>
      <p class="text-sm text-muted mb-0">
        <?php if ($show !== ''): ?>
          <a href="<?= e($tabUrl('')) ?>">See all <?= $isInvoice ? 'invoices' : 'quotations' ?></a>.
        <?php else: ?>
          <?= $isInvoice
            ? 'When we invoice you, it will appear here.'
            : 'When we quote you for something, it will appear here.' ?>
        <?php endif; ?>
      </p>
    </div>
  <?php else: ?>
    <div class="portal-card portal-card--flush">
      <ul class="portal-list">
        <?php foreach ($rows as $r): ?>
          <?php
            $open = $isInvoice && (float) $r['balance'] > 0.009;
            $href = url($listPath . '/' . (int) $r['id']);
            $when = $isInvoice ? $r['due_date'] : $r['valid_until'];
          ?>
          <li>
            <a class="portal-list__row" href="<?= e($href) ?>">
              <?php // Untitled documents are common, and a column of rows all
                    // reading "Invoice" tells the reader nothing. The number
                    // is what distinguishes them, so it leads when there is
                    // no title to lead with. ?>
              <span class="portal-list__main">
                <span class="portal-list__title">
                  <?= e($r['title'] ?: $r['doc_number']) ?>
                </span>
                <span class="portal-list__meta">
                  <?php if ($r['title']): ?><?= e($r['doc_number']) ?> &middot; <?php endif; ?>
                  <?= e(fdate($r['issue_date'])) ?>
                  <?php if ($when): ?>
                    &middot; <?= $isInvoice ? 'due' : 'valid to' ?> <?= e(fdate($when)) ?>
                  <?php endif; ?>
                </span>
              </span>

              <span class="portal-list__side">
                <span class="portal-list__figures">
                  <span class="portal-list__amount"><?= e(money($r['total'], false)) ?></span>
                  <?php // Only worth saying when it differs from the total.
                        // On an invoice nothing has been paid against, the
                        // outstanding figure is the total repeated. ?>
                  <?php if ($open && abs((float) $r['balance'] - (float) $r['total']) > 0.009): ?>
                    <span class="portal-list__owing">
                      <?= e(money($r['balance'], false)) ?> still owing
                    </span>
                  <?php endif; ?>
                </span>

                <span class="badge badge--<?= e($tone((string) $r['status'])) ?>">
                  <?= e(label_of((string) $r['status'])) ?>
                </span>

                <?php if ($open && $canPay): ?>
                  <?php // Outlined rather than solid: on a list where most
                        // rows are outstanding, twenty-five filled green
                        // buttons stop reading as a call to action at all. ?>
                  <span class="btn btn--outline btn--sm portal-list__pay"><?= icon('smartphone') ?> Pay</span>
                <?php else: ?>
                  <span class="portal-list__chev"><?= icon('chevron-right') ?></span>
                <?php endif; ?>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="portal-pager" aria-label="Pages">
        <?php $q = static fn(int $n): string =>
          url($listPath . '?' . http_build_query(array_filter(['show' => $show, 'page' => $n > 1 ? $n : null]))); ?>

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
