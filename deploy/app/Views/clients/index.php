<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Clients</h1>
    <div class="page-head__sub">Registered clients, their billing history and outstanding balances.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/clients/export') ?>"><?= icon('download') ?> Export CSV</a>
    <?php if (can('clients.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/clients/create') ?>"><?= icon('user-plus') ?> New client</a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--navy">
    <div class="stat__label">Active clients</div>
    <div class="stat__value"><?= number_format((int) $summary['active_clients']) ?></div>
    <div class="stat__meta"><?= number_format((int) $summary['all_clients']) ?> total on record</div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Billed this year</div>
    <div class="stat__value"><?= e(money_short($summary['billed_ytd'])) ?></div>
    <div class="stat__meta"><?= date('Y') ?> invoiced value</div>
  </div>
  <div class="stat stat--red">
    <div class="stat__label">Outstanding</div>
    <div class="stat__value"><?= e(money_short($summary['total_outstanding'])) ?></div>
    <div class="stat__meta">Unpaid invoice balances</div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Collection rate</div>
    <?php
      $billed = (float) $summary['billed_ytd'];
      $out    = (float) $summary['total_outstanding'];
      $rate   = $billed > 0 ? max(0, min(100, (($billed - $out) / $billed) * 100)) : 0;
    ?>
    <div class="stat__value"><?= number_format($rate, 1) ?>%</div>
    <div class="stat__meta">Of value invoiced this year</div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/clients') ?>">
    <div class="field" style="min-width:250px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Name, email, phone or code" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="type">Type</label>
      <select class="select" id="type" name="type" data-auto-submit>
        <option value="">All types</option>
        <option value="company"    <?= $filters['type'] === 'company'    ? 'selected' : '' ?>>Company</option>
        <option value="individual" <?= $filters['type'] === 'individual' ? 'selected' : '' ?>>Individual</option>
      </select>
    </div>

    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="select" id="status" name="status" data-auto-submit>
        <option value="">All</option>
        <option value="active"   <?= $filters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>

    <div class="field">
      <label class="label" for="sort">Sort by</label>
      <select class="select" id="sort" name="sort" data-auto-submit>
        <option value="recent"  <?= $filters['sort'] === 'recent'  ? 'selected' : '' ?>>Recently added</option>
        <option value="name"    <?= $filters['sort'] === 'name'    ? 'selected' : '' ?>>Name (A–Z)</option>
        <option value="balance" <?= $filters['sort'] === 'balance' ? 'selected' : '' ?>>Highest balance</option>
        <option value="billed"  <?= $filters['sort'] === 'billed'  ? 'selected' : '' ?>>Highest billed</option>
      </select>
    </div>

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/clients') ?>">Clear</a>
  </form>

  <?php if (!$clients): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('users') ?></div>
      <div class="empty__title">No clients found</div>
      <p class="empty__text">
        <?= $filters['search'] !== '' || $filters['status'] !== '' || $filters['type'] !== ''
            ? 'No clients match these filters.'
            : 'Register your first client to start raising quotations and invoices.' ?>
      </p>
      <?php if (can('clients.manage')): ?>
        <a class="btn btn--primary" href="<?= url('/clients/create') ?>"><?= icon('user-plus') ?> New client</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Client</th>
            <th>Contact</th>
            <th>Code</th>
            <th class="num">Invoices</th>
            <th class="num">Total billed</th>
            <th class="num">Outstanding</th>
            <th>Status</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clients as $c): ?>
            <tr>
              <td>
                <div class="flex items-center gap-8">
                  <span class="avatar avatar--sm"><?= e(initials($c['name'])) ?></span>
                  <span>
                    <a class="table__primary" href="<?= url('/clients/' . $c['id']) ?>"><?= e($c['name']) ?></a>
                    <div class="table__muted">
                      <?= e(label_of($c['client_type'])) ?>
                      <?= $c['contact_person'] ? ' · ' . e($c['contact_person']) : '' ?>
                    </div>
                  </span>
                </div>
              </td>
              <td class="text-sm">
                <?php if ($c['phone']): ?><div><?= e($c['phone']) ?></div><?php endif; ?>
                <?php if ($c['email']): ?><div class="table__muted truncate" style="max-width:180px"><?= e($c['email']) ?></div><?php endif; ?>
                <?php if (!$c['phone'] && !$c['email']): ?>—<?php endif; ?>
              </td>
              <td><code class="text-xs"><?= e($c['client_code']) ?></code></td>
              <td class="num"><?= (int) $c['invoice_count'] ?></td>
              <td class="num fw-600"><?= e(money($c['total_billed'], false)) ?></td>
              <td class="num">
                <?php if ((float) $c['outstanding'] > 0.009): ?>
                  <span class="fw-700 text-red"><?= e(money($c['outstanding'], false)) ?></span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $c['status'] === 'active' ? 'badge--green' : 'badge--grey' ?>">
                  <?= e(label_of($c['status'])) ?>
                </span>
              </td>
              <td class="actions">
                <a class="btn btn--outline btn--sm" href="<?= url('/clients/' . $c['id']) ?>"><?= icon('eye') ?></a>
                <?php if (can('clients.manage')): ?>
                  <a class="btn btn--outline btn--sm" href="<?= url('/clients/' . $c['id'] . '/edit') ?>"><?= icon('edit') ?></a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($clients) ?> of <?= number_format($pager['total']) ?> client(s)</span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
