<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Services\Renewals;

// How urgent a renewal is, as a badge. Everything on this page is sorted by
// date, so the colour is what makes the top of the list scannable.
$urgency = static function (string $date): array {
    $days = (int) floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);

    if ($days < 0)  return ['red',   abs($days) . 'd overdue'];
    if ($days === 0) return ['red',   'Due today'];
    if ($days <= 7)  return ['amber', 'In ' . $days . 'd'];
    if ($days <= 30) return ['navy',  'In ' . $days . 'd'];

    return ['grey', fdate($date)];
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Recurring Services</h1>
    <div class="page-head__sub">Websites, hosting and retainers that renew on a cycle.</div>
  </div>
  <?php if (can('subscriptions.manage')): ?>
    <div class="page-head__actions">
      <a class="btn btn--primary" href="<?= url('/subscriptions/create') ?>"><?= icon('plus') ?> New service</a>
    </div>
  <?php endif; ?>
</div>

<div class="stat-grid">
  <div class="stat stat--navy">
    <div class="stat__label">Live services</div>
    <div class="stat__value"><?= number_format((int) $summary['live']) ?></div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Renewing in 30 days</div>
    <div class="stat__value"><?= (int) $summary['due_soon'] ?></div>
    <div class="stat__meta">Worth chasing now</div>
  </div>
  <div class="stat stat--red">
    <div class="stat__label">Past renewal date</div>
    <div class="stat__value"><?= (int) $summary['overdue'] ?></div>
    <div class="stat__meta">Not yet invoiced or renewed</div>
  </div>
  <?php if (can('expenses.view') || can('subscriptions.manage')): ?>
    <div class="stat stat--green">
      <div class="stat__label">Value per cycle</div>
      <div class="stat__value"><?= e(money_short($summary['cycle_value'])) ?></div>
      <div class="stat__meta">Across all live services</div>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/subscriptions') ?>">
    <div class="field" style="min-width:230px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Service, address or client" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="type">Type</label>
      <select class="select" id="type" name="type" data-auto-submit>
        <option value="">All types</option>
        <?php foreach ($types as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $filters['type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="due">Renewal</label>
      <select class="select" id="due" name="due" data-auto-submit>
        <option value="">Any date</option>
        <option value="soon"    <?= $filters['due'] === 'soon'    ? 'selected' : '' ?>>Next 30 days</option>
        <option value="overdue" <?= $filters['due'] === 'overdue' ? 'selected' : '' ?>>Past due</option>
      </select>
    </div>

    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="select" id="status" name="status" data-auto-submit>
        <option value="active"    <?= $filters['status'] === 'active'    ? 'selected' : '' ?>>Active</option>
        <option value="paused"    <?= $filters['status'] === 'paused'    ? 'selected' : '' ?>>Paused</option>
        <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        <option value=""          <?= $filters['status'] === ''          ? 'selected' : '' ?>>All</option>
      </select>
    </div>

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/subscriptions') ?>">Clear</a>
  </form>

  <?php if (!$subs): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('refresh') ?></div>
      <div class="empty__title">Nothing here yet</div>
      <p class="empty__text">
        Register a website, hosting package or retainer and the system will track
        its renewal date, remind the client, and raise the invoice.
      </p>
      <?php if (can('subscriptions.manage')): ?>
        <a class="btn btn--primary" href="<?= url('/subscriptions/create') ?>"><?= icon('plus') ?> New service</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Service</th>
            <th>Client</th>
            <th>Renews</th>
            <th class="num">Amount</th>
            <th class="num">Due balance</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subs as $s):
              [$tone, $when] = $urgency($s['next_renewal_date']);
              $owing = (float) $s['due_balance'];
          ?>
            <tr>
              <td>
                <a class="table__primary" href="<?= url('/subscriptions/' . $s['id']) ?>"><?= e($s['name']) ?></a>
                <div class="table__muted">
                  <?= e($types[$s['service_type']] ?? 'Service') ?>
                  · <?= e(Renewals::CYCLES[$s['billing_cycle']] ?? '') ?>
                  <?php if ($s['status'] !== 'active'): ?>
                    · <span class="badge badge--grey"><?= e(label_of($s['status'])) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-sm">
                <a href="<?= url('/clients/' . $s['client_id']) ?>"><?= e($s['client_name']) ?></a>
              </td>
              <td>
                <span class="badge badge--<?= e($tone) ?>"><?= e($when) ?></span>
                <div class="table__muted"><?= e(fdate($s['next_renewal_date'])) ?></div>
              </td>
              <td class="num fw-600"><?= e(money($s['amount'], false)) ?></td>
              <td class="num">
                <?php if ($owing > 0): ?>
                  <span class="text-red fw-600"><?= e(money($owing, false)) ?></span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="actions">
                <?php if ($s['url']): ?>
                  <?php
                    // rel="noopener" is not cosmetic: without it the site we
                    // open gets a handle on this tab through window.opener
                    // and can navigate it somewhere else.
                  ?>
                  <a class="btn btn--outline btn--sm" href="<?= e($s['url']) ?>"
                     target="_blank" rel="noopener noreferrer"
                     title="Open <?= e($s['url']) ?> in a new tab">
                    <?= icon('external-link') ?> Visit
                  </a>
                <?php endif; ?>
                <a class="btn btn--outline btn--sm" href="<?= url('/subscriptions/' . $s['id']) ?>"><?= icon('eye') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($subs) ?> of <?= number_format($pager['total']) ?> service(s)</span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
