<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Inventory</h1>
    <div class="page-head__sub">Stock items and selling prices for printing &amp; branding.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/inventory/export') ?>"><?= icon('download') ?> Export CSV</a>
    <?php if (can('inventory.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/inventory/create') ?>"><?= icon('plus') ?> New item</a>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--navy">
    <div class="stat__label">Active items</div>
    <div class="stat__value"><?= number_format((int) $summary['total_items']) ?></div>
  </div>
  <div class="stat stat--green">
    <div class="stat__label">Stock value (at cost)</div>
    <div class="stat__value"><?= e(money_short($summary['stock_value'])) ?></div>
    <div class="stat__meta">Retail: <?= e(money_short($summary['retail_value'])) ?></div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Low stock</div>
    <div class="stat__value"><?= (int) $summary['low_stock'] ?></div>
    <div class="stat__meta">At or below reorder level</div>
  </div>
  <div class="stat stat--red">
    <div class="stat__label">Out of stock</div>
    <div class="stat__value"><?= (int) $summary['out_of_stock'] ?></div>
    <div class="stat__meta">Needs restocking</div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/inventory') ?>">
    <div class="field" style="min-width:230px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Name, SKU or description" data-debounce-submit>
    </div>

    <div class="field">
      <label class="label" for="category">Category</label>
      <select class="select" id="category" name="category" data-auto-submit>
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $filters['category'] === (int) $c['id'] ? 'selected' : '' ?>>
            <?= e($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="stock">Stock level</label>
      <select class="select" id="stock" name="stock" data-auto-submit>
        <option value="">Any level</option>
        <option value="in"  <?= $filters['stock'] === 'in'  ? 'selected' : '' ?>>In stock</option>
        <option value="low" <?= $filters['stock'] === 'low' ? 'selected' : '' ?>>Low stock</option>
        <option value="out" <?= $filters['stock'] === 'out' ? 'selected' : '' ?>>Out of stock</option>
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

    <div class="filters__spacer"></div>
    <a class="btn btn--ghost btn--sm" href="<?= url('/inventory') ?>">Clear</a>
  </form>

  <?php if (!$items): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('package') ?></div>
      <div class="empty__title">No items found</div>
      <p class="empty__text">
        <?= $filters['search'] !== '' || $filters['category'] || $filters['stock'] !== ''
            ? 'No inventory matches these filters. Try clearing them.'
            : 'Add your first stock item to start quoting and invoicing from the catalogue.' ?>
      </p>
      <?php if (can('inventory.manage')): ?>
        <a class="btn btn--primary" href="<?= url('/inventory/create') ?>"><?= icon('plus') ?> New item</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Item</th>
            <th>SKU</th>
            <th>Category</th>
            <th class="num">Cost</th>
            <th class="num">Selling price</th>
            <th class="num">In stock</th>
            <th>Status</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item):
              $q = (float) $item['quantity'];
              $r = (float) $item['reorder_level'];
              $margin = (float) $item['selling_price'] - (float) $item['cost_price'];
              $marginPct = (float) $item['selling_price'] > 0
                  ? ($margin / (float) $item['selling_price']) * 100 : 0;
          ?>
            <tr>
              <td>
                <div class="flex items-center gap-12">
                  <?php $thumb = $item['thumb_path'] ?: $item['image_path']; ?>
                  <a class="cell-thumb <?= $thumb ? '' : 'cell-thumb--empty' ?>"
                     href="<?= url('/inventory/' . $item['id']) ?>">
                    <?php if ($thumb): ?>
                      <img src="<?= url('files/' . $thumb) ?>" alt="" loading="lazy">
                      <?php if ((int) $item['image_count'] > 1): ?>
                        <span class="cell-thumb__count"><?= (int) $item['image_count'] ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <?= icon('image') ?>
                    <?php endif; ?>
                  </a>
                  <span style="min-width:0">
                    <a class="table__primary" href="<?= url('/inventory/' . $item['id']) ?>"><?= e($item['name']) ?></a>
                    <?php if ($item['description']): ?>
                      <div class="table__muted"><?= e(str_excerpt($item['description'], 52)) ?></div>
                    <?php endif; ?>
                  </span>
                </div>
              </td>
              <td><code class="text-xs"><?= e($item['sku']) ?></code></td>
              <td class="text-sm"><?= e($item['category_name'] ?: '—') ?></td>
              <td class="num text-sm text-muted"><?= e(money($item['cost_price'], false)) ?></td>
              <td class="num">
                <span class="fw-600"><?= e(money($item['selling_price'], false)) ?></span>
                <?php if ($marginPct > 0): ?>
                  <div class="table__muted"><?= number_format($marginPct, 0) ?>% margin</div>
                <?php endif; ?>
              </td>
              <td class="num">
                <span class="fw-600"><?= e(qty($q)) ?></span>
                <span class="text-xs text-muted"><?= e($item['unit']) ?></span>
              </td>
              <td>
                <?php if (!$item['is_active']): ?>
                  <span class="badge badge--grey">Inactive</span>
                <?php elseif ($q <= 0): ?>
                  <span class="badge badge--red">Out of stock</span>
                <?php elseif ($q <= $r): ?>
                  <span class="badge badge--amber">Low stock</span>
                <?php else: ?>
                  <span class="badge badge--green">In stock</span>
                <?php endif; ?>
              </td>
              <td class="actions">
                <a class="btn btn--outline btn--sm" href="<?= url('/inventory/' . $item['id']) ?>"><?= icon('eye') ?></a>
                <?php if (can('inventory.manage')): ?>
                  <a class="btn btn--outline btn--sm" href="<?= url('/inventory/' . $item['id'] . '/edit') ?>"><?= icon('edit') ?></a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($items) ?> of <?= number_format($pager['total']) ?> item(s)</span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
