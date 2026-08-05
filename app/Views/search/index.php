<?php require_once APP_PATH . '/Views/partials/icons.php'; ?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Search</h1>
    <div class="page-head__sub">
      <?php if ($q === ''): ?>
        Find clients, leads, documents, stock and services.
      <?php else: ?>
        <?= (int) $total ?> result<?= $total === 1 ? '' : 's' ?> for “<?= e($q) ?>”
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <form class="filters" method="get" action="<?= url('/search') ?>">
    <div class="field" style="min-width:320px;flex:1">
      <label class="label" for="q">Search everything</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($q) ?>" autofocus
             placeholder="Client name, invoice number, lead, SKU…">
    </div>
    <div class="field" style="display:flex;align-items:flex-end">
      <button class="btn btn--primary btn--sm" type="submit"><?= icon('search') ?> Search</button>
    </div>
  </form>
</div>

<?php if ($q !== '' && mb_strlen($q) < 2): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__icon"><?= icon('search') ?></div>
      <div class="empty__title">Type a little more</div>
      <p class="empty__text">Enter at least two characters to search.</p>
    </div>
  </div>

<?php elseif ($q !== '' && $total === 0): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__icon"><?= icon('search') ?></div>
      <div class="empty__title">Nothing found</div>
      <p class="empty__text">No records match “<?= e($q) ?>”. Try a shorter term or a different spelling.</p>
    </div>
  </div>

<?php elseif ($q !== ''): ?>

  <?php if ($results['clients']): ?>
    <div class="card">
      <div class="card__head">
        <?= icon('users') ?>
        <div class="card__title">Clients</div>
        <div class="card__sub"><?= count($results['clients']) ?> found</div>
      </div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Name</th><th>Code</th><th>Phone</th><th>Email</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($results['clients'] as $c): ?>
              <tr>
                <td><a class="table__primary" href="<?= url('/clients/' . $c['id']) ?>"><?= e($c['name']) ?></a></td>
                <td><code class="text-xs"><?= e($c['client_code']) ?></code></td>
                <td class="text-sm"><?= e($c['phone'] ?: '—') ?></td>
                <td class="text-sm text-muted truncate" style="max-width:200px"><?= e($c['email'] ?: '—') ?></td>
                <td><span class="badge <?= status_badge($c['status']) ?>"><?= e(label_of($c['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($results['documents']): ?>
    <div class="card">
      <div class="card__head">
        <?= icon('file-text') ?>
        <div class="card__title">Documents</div>
        <div class="card__sub"><?= count($results['documents']) ?> found</div>
      </div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Number</th><th>Type</th><th>Client</th><th>Date</th><th class="num">Total</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($results['documents'] as $d):
                $path = $d['doc_type'] === 'quotation' ? '/quotations/' : ($d['doc_type'] === 'invoice' ? '/invoices/' : '/receipts/');
            ?>
              <tr>
                <td><a class="table__primary" href="<?= url($path . $d['id']) ?>"><?= e($d['doc_number']) ?></a></td>
                <td class="text-sm text-muted"><?= e(label_of($d['doc_type'])) ?></td>
                <td class="text-sm"><?= e($d['client_name']) ?></td>
                <td class="text-sm"><?= e(fdate($d['issue_date'])) ?></td>
                <td class="num fw-600"><?= e(money($d['total'], false)) ?></td>
                <td><span class="badge <?= status_badge($d['status']) ?>"><?= e(label_of($d['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($results['leads']): ?>
    <div class="card">
      <div class="card__head">
        <?= icon('target') ?>
        <div class="card__title">Leads</div>
        <div class="card__sub"><?= count($results['leads']) ?> found</div>
      </div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Name</th><th>Company</th><th>Number</th><th>Stage</th><th class="num">Value</th></tr></thead>
          <tbody>
            <?php foreach ($results['leads'] as $l): ?>
              <tr>
                <td><a class="table__primary" href="<?= url('/leads/' . $l['id']) ?>"><?= e($l['name']) ?></a></td>
                <td class="text-sm"><?= e($l['company'] ?: '—') ?></td>
                <td><code class="text-xs"><?= e($l['lead_number']) ?></code></td>
                <td><span class="badge <?= status_badge($l['stage']) ?>"><?= e(label_of($l['stage'])) ?></span></td>
                <td class="num fw-600"><?= e(money($l['estimated_value'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($results['inventory']): ?>
    <div class="card">
      <div class="card__head">
        <?= icon('package') ?>
        <div class="card__title">Inventory</div>
        <div class="card__sub"><?= count($results['inventory']) ?> found</div>
      </div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Item</th><th>SKU</th><th class="num">Price</th><th class="num">In stock</th></tr></thead>
          <tbody>
            <?php foreach ($results['inventory'] as $i): ?>
              <tr>
                <td><a class="table__primary" href="<?= url('/inventory/' . $i['id']) ?>"><?= e($i['name']) ?></a></td>
                <td><code class="text-xs"><?= e($i['sku']) ?></code></td>
                <td class="num fw-600"><?= e(money($i['selling_price'], false)) ?></td>
                <td class="num"><?= e(qty($i['quantity'])) ?> <span class="text-xs text-muted"><?= e($i['unit']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($results['services']): ?>
    <div class="card">
      <div class="card__head">
        <?= icon('layers') ?>
        <div class="card__title">Services</div>
        <div class="card__sub"><?= count($results['services']) ?> found</div>
      </div>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Service</th><th>Code</th><th class="num">Rate</th></tr></thead>
          <tbody>
            <?php foreach ($results['services'] as $s): ?>
              <tr>
                <td><a class="table__primary" href="<?= url('/services/' . $s['id']) ?>"><?= e($s['name']) ?></a></td>
                <td><code class="text-xs"><?= e($s['code']) ?></code></td>
                <td class="num fw-600">
                  <?= (float) $s['price'] > 0 ? e(money($s['price'], false)) : 'On request' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>
