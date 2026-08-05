<?php
require_once APP_PATH . '/Views/partials/icons.php';

/** Small helper so the price reads naturally for each pricing type. */
$priceLabel = static function (array $s): string {
    if ($s['pricing_type'] === 'project' || (float) $s['price'] <= 0) {
        return 'On request';
    }

    $prefix = $s['pricing_type'] === 'from' ? 'From ' : '';
    $suffix = $s['unit_label'] ? ' ' . $s['unit_label'] : match ($s['pricing_type']) {
        'hourly'  => ' per hour',
        'daily'   => ' per day',
        'monthly' => ' per month',
        default   => '',
    };

    return $prefix . money($s['price']) . $suffix;
};
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Services</h1>
    <div class="page-head__sub">
      Software, web, design and branding services with their standard rates.
    </div>
  </div>
  <div class="page-head__actions">
    <?php if (can('services.manage')): ?>
      <a class="btn btn--primary" href="<?= url('/services/create') ?>"><?= icon('plus') ?> New service</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/services') ?>">
    <div class="field" style="min-width:230px">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['search']) ?>"
             placeholder="Service name or code" data-debounce-submit>
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
      <label class="label" for="status">Status</label>
      <select class="select" id="status" name="status" data-auto-submit>
        <option value="">All</option>
        <option value="active"   <?= $filters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>

    <div class="filters__spacer"></div>
    <span class="text-sm text-muted">
      <?= (int) $stats['active'] ?> active of <?= (int) $stats['total'] ?>
    </span>
    <a class="btn btn--ghost btn--sm" href="<?= url('/services') ?>">Clear</a>
  </form>
</div>

<?php if (!$grouped): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__icon"><?= icon('layers') ?></div>
      <div class="empty__title">No services found</div>
      <p class="empty__text">
        List the services Shanfix offers — website development, custom software, design,
        branding — so they can be added to quotations in one click.
      </p>
      <?php if (can('services.manage')): ?>
        <a class="btn btn--primary" href="<?= url('/services/create') ?>"><?= icon('plus') ?> New service</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($grouped as $categoryName => $services): ?>
    <div class="card">
      <div class="card__head">
        <?= icon('layers', '') ?>
        <div>
          <div class="card__title"><?= e($categoryName) ?></div>
          <div class="card__sub"><?= count($services) ?> service<?= count($services) === 1 ? '' : 's' ?></div>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Service</th>
              <th>Code</th>
              <th>Pricing</th>
              <th>Rate</th>
              <th>Turnaround</th>
              <th>Status</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($services as $s): ?>
              <tr>
                <td>
                  <a class="table__primary" href="<?= url('/services/' . $s['id']) ?>"><?= e($s['name']) ?></a>
                  <?php if ($s['description']): ?>
                    <div class="table__muted"><?= e(str_excerpt($s['description'], 78)) ?></div>
                  <?php endif; ?>
                </td>
                <td><code class="text-xs"><?= e($s['code']) ?></code></td>
                <td class="text-sm text-muted"><?= e(\App\Controllers\ServiceController::PRICING_TYPES[$s['pricing_type']] ?? '') ?></td>
                <td class="fw-600 nums"><?= e($priceLabel($s)) ?></td>
                <td class="text-sm text-muted"><?= e($s['lead_time'] ?: '—') ?></td>
                <td>
                  <span class="badge <?= $s['is_active'] ? 'badge--green' : 'badge--grey' ?>">
                    <?= $s['is_active'] ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td class="actions">
                  <a class="btn btn--outline btn--sm" href="<?= url('/services/' . $s['id']) ?>"><?= icon('eye') ?></a>
                  <?php if (can('services.manage')): ?>
                    <a class="btn btn--outline btn--sm" href="<?= url('/services/' . $s['id'] . '/edit') ?>"><?= icon('edit') ?></a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
