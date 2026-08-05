<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/services') ?>">Services</a> <span>/</span> <?= e($service['code']) ?>
    </div>
    <h1><?= e($service['name']) ?></h1>
    <div class="page-head__sub">
      <?= e($service['category_name'] ?: 'Uncategorised') ?> ·
      <code><?= e($service['code']) ?></code>
      <?php if (!$service['is_active']): ?> · <span class="badge badge--grey">Inactive</span><?php endif; ?>
    </div>
  </div>
  <div class="page-head__actions">
    <?php if (can('services.manage')): ?>
      <a class="btn btn--outline" href="<?= url('/services/' . $service['id'] . '/edit') ?>"><?= icon('edit') ?> Edit</a>
      <form method="post" action="<?= url('/services/' . $service['id'] . '/delete') ?>" style="display:inline"
            data-confirm="Delete &quot;<?= e($service['name']) ?>&quot;? Services used on documents will be deactivated instead.">
        <?= csrf_field() ?>
        <button class="btn btn--danger-soft" type="submit"><?= icon('trash') ?> Delete</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--green">
    <div class="stat__label">Standard rate</div>
    <div class="stat__value">
      <?php if ($service['pricing_type'] === 'project' || (float) $service['price'] <= 0): ?>
        On request
      <?php else: ?>
        <?= e(money($service['price'])) ?>
      <?php endif; ?>
    </div>
    <div class="stat__meta">
      <?= e($pricingTypes[$service['pricing_type']] ?? '') ?>
      <?= $service['unit_label'] ? '· ' . e($service['unit_label']) : '' ?>
    </div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Invoiced to date</div>
    <div class="stat__value"><?= e(money_short($revenue)) ?></div>
    <div class="stat__meta">Across <?= count($sold) ?> recent document(s)</div>
  </div>
  <div class="stat stat--amber">
    <div class="stat__label">Open leads</div>
    <div class="stat__value"><?= count($openLeads) ?></div>
    <div class="stat__meta">Prospects asking for this service</div>
  </div>
  <div class="stat stat--navy">
    <div class="stat__label">Turnaround</div>
    <div class="stat__value" style="font-size:17px"><?= e($service['lead_time'] ?: 'Not set') ?></div>
  </div>
</div>

<div class="grid-sidebar">
  <div>
    <?php if ($service['description']): ?>
      <div class="card">
        <div class="card__head"><div class="card__title">What's included</div></div>
        <div class="card__body">
          <p style="white-space:pre-line"><?= e($service['description']) ?></p>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Recent documents</div>
          <div class="card__sub">Quotations and invoices containing this service.</div>
        </div>
      </div>

      <?php if (!$sold): ?>
        <div class="empty">
          <div class="empty__icon"><?= icon('file-text') ?></div>
          <div class="empty__title">Not yet quoted</div>
          <p class="empty__text">Once this service is added to a quotation or invoice it will show up here.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead>
              <tr>
                <th>Document</th><th>Type</th><th>Client</th><th>Date</th>
                <th class="num">Qty</th><th class="num">Value</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sold as $d):
                  $path = $d['doc_type'] === 'quotation' ? '/quotations/' : ($d['doc_type'] === 'invoice' ? '/invoices/' : '/receipts/');
              ?>
                <tr>
                  <td><a class="table__primary" href="<?= url($path . $d['id']) ?>"><?= e($d['doc_number']) ?></a></td>
                  <td class="text-sm text-muted"><?= e(label_of($d['doc_type'])) ?></td>
                  <td class="text-sm"><?= e($d['client_name']) ?></td>
                  <td class="text-sm"><?= e(fdate($d['issue_date'])) ?></td>
                  <td class="num"><?= e(qty($d['quantity'])) ?></td>
                  <td class="num fw-600"><?= e(money($d['line_total'], false)) ?></td>
                  <td><span class="badge <?= status_badge($d['status']) ?>"><?= e(label_of($d['status'])) ?></span></td>
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
          <dt>Code</dt><dd><code><?= e($service['code']) ?></code></dd>
          <dt>Category</dt><dd><?= e($service['category_name'] ?: '—') ?></dd>
          <dt>Pricing</dt><dd><?= e($pricingTypes[$service['pricing_type']] ?? '') ?></dd>
          <dt>Unit</dt><dd><?= e($service['unit_label'] ?: '—') ?></dd>
          <dt>Status</dt>
          <dd><span class="badge <?= $service['is_active'] ? 'badge--green' : 'badge--grey' ?>">
            <?= $service['is_active'] ? 'Active' : 'Inactive' ?></span></dd>
          <dt>Created</dt><dd><?= e(fdate($service['created_at'])) ?></dd>
        </dl>
      </div>
    </div>

    <?php if ($openLeads): ?>
      <div class="card">
        <div class="card__head">
          <div class="card__title">Open leads</div>
          <div class="card__actions"><a class="btn btn--ghost btn--sm" href="<?= url('/leads') ?>">All leads</a></div>
        </div>
        <div class="card__body--flush">
          <?php foreach ($openLeads as $l): ?>
            <a class="conv" href="<?= url('/leads/' . $l['id']) ?>">
              <span class="avatar avatar--sm"><?= e(initials($l['name'])) ?></span>
              <span class="conv__meta">
                <span class="conv__name"><?= e($l['name']) ?></span>
                <span class="conv__preview"><?= e($l['company'] ?: $l['lead_number']) ?></span>
              </span>
              <span class="conv__right">
                <span class="badge <?= status_badge($l['stage']) ?>"><?= e(label_of($l['stage'])) ?></span>
                <span class="conv__time"><?= e(money_short($l['estimated_value'])) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </aside>
</div>
