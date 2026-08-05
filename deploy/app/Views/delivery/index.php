<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/jobs') ?>">Production</a> <span>/</span> Delivery notes
    </div>
    <h1>Delivery Notes</h1>
    <div class="page-head__sub">Signed proof that the client received the goods.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/jobs') ?>"><?= icon('printer') ?> Production board</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--grey">
    <div class="stat__label">Draft</div>
    <div class="stat__value"><?= (int) $summary['draft'] ?></div>
    <div class="stat__meta">Not yet dispatched</div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Out for delivery</div>
    <div class="stat__value"><?= (int) $summary['dispatched'] ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Delivered</div>
    <div class="stat__value"><?= (int) $summary['delivered'] ?></div>
    <div class="stat__meta">Signed for by the client</div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/delivery-notes') ?>">
    <div class="field" style="min-width:250px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Note number, client or recipient" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="select" id="status" name="status" data-auto-submit>
        <option value="">All</option>
        <option value="draft"      <?= $filters['status'] === 'draft'      ? 'selected' : '' ?>>Draft</option>
        <option value="dispatched" <?= $filters['status'] === 'dispatched' ? 'selected' : '' ?>>Dispatched</option>
        <option value="delivered"  <?= $filters['status'] === 'delivered'  ? 'selected' : '' ?>>Delivered</option>
      </select>
    </div>

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/delivery-notes') ?>">Clear</a>
  </form>

  <?php if (!$notes): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('archive') ?></div>
      <div class="empty__title">No delivery notes yet</div>
      <p class="empty__text">
        Raise one from a job card once the work is ready to go out — it prints
        with a signature line for the client.
      </p>
      <a class="btn btn--primary" href="<?= url('/jobs') ?>"><?= icon('printer') ?> Go to production</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Number</th><th>Client</th><th>Job</th><th>Date</th>
            <th class="num">Items</th><th>Received by</th><th>Status</th><th class="actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($notes as $n): ?>
            <tr>
              <td><a class="table__primary" href="<?= url('/delivery-notes/' . $n['id']) ?>"><?= e($n['dn_number']) ?></a></td>
              <td class="text-sm"><a href="<?= url('/clients/' . $n['client_id']) ?>"><?= e($n['client_name']) ?></a></td>
              <td class="text-sm">
                <?php if ($n['job_number']): ?>
                  <a href="<?= url('/jobs/' . $n['job_id']) ?>"><?= e($n['job_number']) ?></a>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td class="text-sm"><?= e(fdate($n['delivery_date'])) ?></td>
              <td class="num"><?= (int) $n['item_count'] ?></td>
              <td class="text-sm"><?= e($n['received_by'] ?: '—') ?></td>
              <td>
                <span class="badge <?= $n['status'] === 'delivered' ? 'badge--green'
                    : ($n['status'] === 'dispatched' ? 'badge--amber' : 'badge--grey') ?>">
                  <?= e(label_of($n['status'])) ?>
                </span>
              </td>
              <td class="actions">
                <a class="btn btn--outline btn--sm" href="<?= url('/delivery-notes/' . $n['id']) ?>"><?= icon('eye') ?></a>
                <a class="btn btn--outline btn--sm" href="<?= url('/delivery-notes/' . $n['id'] . '/print') ?>"
                   target="_blank" rel="noopener"><?= icon('printer') ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($notes) ?> of <?= number_format($pager['total']) ?></span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
