<?php
/**
 * @var array $suppliers
 * @var array $pager
 * @var array $filters
 */
require_once APP_PATH . '/Views/partials/icons.php';
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Suppliers</h1>
    <div class="page-head__sub">Who you buy from, and what you have spent with them.</div>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--outline" href="<?= url('/purchase-orders') ?>">
      <?= icon('file-text') ?> Purchase orders
    </a>
    <?php if (can('purchases.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/suppliers/create') ?>">
        <?= icon('plus') ?> New supplier
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/suppliers') ?>">
    <div class="field" style="min-width:250px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Name, code, phone or email">
    </div>
    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="input" id="status" name="status">
        <option value="">All</option>
        <option value="active"   <?= $filters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="field">
      <button class="btn btn--outline" type="submit"><?= icon('search') ?> Filter</button>
    </div>
  </form>

  <?php if (!$suppliers): ?>
    <div class="card__body">
      <div class="empty">
        <div class="empty__icon"><?= icon('package') ?></div>
        <div class="empty__title">No suppliers yet</div>
        <p class="empty__text">
          Add the people you buy materials from. Receiving goods against a
          purchase order then adds stock at a real cost price, instead of a
          figure typed in by hand.
        </p>
        <?php if (can('purchases.manage')): ?>
          <a class="btn btn--primary mt-12" href="<?= url('/suppliers/create') ?>">
            <?= icon('plus') ?> New supplier
          </a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Supplier</th>
            <th>Contact</th>
            <th style="width:90px">Terms</th>
            <th style="width:90px">Orders</th>
            <th style="width:130px">Total spend</th>
            <th style="width:90px">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($suppliers as $s): ?>
            <tr>
              <td>
                <a class="fw-600" href="<?= url('/suppliers/' . $s['id']) ?>"><?= e($s['name']) ?></a>
                <div class="text-xs text-muted"><?= e($s['supplier_code']) ?></div>
              </td>
              <td class="text-sm">
                <?= e($s['contact_person'] ?: '—') ?>
                <?php if ($s['phone']): ?>
                  <div class="text-xs text-muted"><?= e($s['phone']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-sm"><?= (int) $s['payment_terms'] ?> days</td>
              <td><?= number_format((int) $s['order_count']) ?></td>
              <td><?= e(money($s['total_spend'])) ?></td>
              <td>
                <span class="badge <?= $s['status'] === 'active' ? 'badge--green' : 'badge--grey' ?>">
                  <?= e(label_of($s['status'])) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-foot">
      <span>Showing <?= count($suppliers) ?> of <?= number_format($pager['total']) ?></span>
      <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
  <?php endif; ?>
</div>
