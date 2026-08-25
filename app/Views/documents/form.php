<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Session;

$editing = $doc !== null;
$action  = $editing ? url($meta['path'] . '/' . $doc['id']) : url($meta['path']);

$val = static function (string $key, $fallback = '') use ($doc) {
    $old = Session::old($key, null);
    if ($old !== null && $old !== '') {
        return $old;
    }
    return $doc[$key] ?? $fallback;
};

$defaultIssue = $val('issue_date', date('Y-m-d'));
$defaultValid = $val('valid_until', date('Y-m-d', strtotime("+{$validityDays} days")));
$defaultDue   = $val('due_date', date('Y-m-d', strtotime("+{$dueDays} days")));

// Catalogue handed to app.js for the line-item pickers.
$catalog = [
    'inventory' => array_map(static fn($i) => [
        'id'          => (int) $i['id'],
        'label'       => $i['name'] . ' (' . $i['sku'] . ')',
        'price'       => (float) $i['selling_price'],
        'unit'        => $i['unit'],
        'description' => $i['description'] ?: $i['name'],
    ], $inventory),
    'service' => array_map(static fn($s) => [
        'id'          => (int) $s['id'],
        'label'       => $s['name'] . ' (' . $s['code'] . ')',
        'price'       => (float) $s['price'],
        'unit'        => $s['unit_label'] ?? '',
        'description' => $s['description'] ?: $s['name'],
    ], $services),
];

$rows = $existingItems ?: [];
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url($meta['path']) ?>"><?= e($meta['plural']) ?></a> <span>/</span>
      <?= $editing ? e($doc['doc_number']) : 'New' ?>
    </div>
    <h1><?= $editing ? 'Edit ' . e($doc['doc_number']) : 'New ' . e($meta['label']) ?></h1>
    <div class="page-head__sub">
      <?= $editing ? 'Changes recalculate the totals automatically.' : 'Document number will be ' ?>
      <?php if (!$editing): ?><code><?= e($nextNumber) ?></code><?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$clients): ?>
  <div class="card">
    <div class="empty">
      <div class="empty__icon"><?= icon('users') ?></div>
      <div class="empty__title">No clients registered</div>
      <p class="empty__text">You need at least one client before you can raise a <?= e(strtolower($meta['label'])) ?>.</p>
      <a class="btn btn--primary" href="<?= url('/clients/create') ?>"><?= icon('user-plus') ?> Register a client</a>
    </div>
  </div>
<?php else: ?>

<form method="post" action="<?= e($action) ?>">
  <?= csrf_field() ?>

  <?php if (!empty($errors['items'])): ?>
    <div class="alert alert--error">
      <?= icon('x-circle') ?>
      <div class="alert__body"><?= e($errors['items']) ?></div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card__head"><div class="card__title">Document details</div></div>
    <div class="card__body">
      <div class="form-grid form-grid--3">
        <div class="field">
          <label class="label" for="client_id">Client <span class="req">*</span></label>
          <select class="select <?= isset($errors['client_id']) ? 'has-error' : '' ?>" id="client_id" name="client_id" required>
            <option value="">Select a client…</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) $val('client_id', $selectedClient) === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?> — <?= e($c['client_code']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?= error_for($errors ?? [], 'client_id') ?>
        </div>

        <div class="field">
          <label class="label" for="issue_date">Issue date <span class="req">*</span></label>
          <input class="input <?= isset($errors['issue_date']) ? 'has-error' : '' ?>" type="date"
                 id="issue_date" name="issue_date" value="<?= e($defaultIssue) ?>" required>
          <?= error_for($errors ?? [], 'issue_date') ?>
        </div>

        <?php if ($type === 'quotation'): ?>
          <div class="field">
            <label class="label" for="valid_until">Valid until</label>
            <input class="input" type="date" id="valid_until" name="valid_until" value="<?= e($defaultValid) ?>">
            <span class="field-hint">Default is <?= (int) $validityDays ?> days.</span>
          </div>
        <?php else: ?>
          <div class="field">
            <label class="label" for="due_date">Payment due</label>
            <input class="input" type="date" id="due_date" name="due_date" value="<?= e($defaultDue) ?>">
            <span class="field-hint">Default is <?= (int) $dueDays ?> days.</span>
          </div>
        <?php endif; ?>

        <div class="field field--full">
          <label class="label" for="title">
            Title / project reference
            <span class="label__hint">— appears under the document number</span>
          </label>
          <input class="input" id="title" name="title" value="<?= e($val('title')) ?>" maxlength="200"
                 placeholder="e.g. Corporate branding package — Q1 rollout">
        </div>
      </div>
    </div>
  </div>


  <?php if (!empty($isNarrative)): ?>
    <?php
    // A proposal or an agreement is mostly prose. New documents start from
    // the house headings in Settings, so nobody retypes them each time.
    $sectionRows = $existingSections ?: [];
    $sectionRows[] = ['heading' => '', 'body' => ''];
    ?>
    <div class="card">
      <div class="card__head">
        <?= icon('file-text') ?>
        <div>
          <div class="card__title">
            <?= $type === 'agreement' ? 'Clauses' : 'What the client reads' ?>
          </div>
          <div class="card__sub">
            <?= $type === 'agreement'
                ? 'The terms being agreed. Edit them to fit this piece of work.'
                : 'Your case for the work. The pricing goes in the lines below.' ?>
            Leave a block empty to drop it.
          </div>
        </div>
        <div class="card__actions">
          <button class="btn btn--outline btn--sm" type="button" id="add-section">
            <?= icon('plus') ?> Add block
          </button>
        </div>
      </div>
      <div class="card__body" id="sections-wrap">
        <?php foreach ($sectionRows as $i => $sec): ?>
          <div class="section-block" data-section>
            <div class="field">
              <input class="input fw-600" name="sections[<?= $i ?>][heading]"
                     value="<?= e($sec['heading'] ?? '') ?>"
                     placeholder="Heading — for example, Scope of work">
            </div>
            <div class="field">
              <textarea class="textarea" rows="4" name="sections[<?= $i ?>][body]"
                        placeholder="Write this section…"><?= e($sec['body'] ?? '') ?></textarea>
            </div>
            <button class="btn btn--outline btn--sm" type="button" data-remove-section>
              <?= icon('trash') ?> Remove
            </button>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Line items</div>
            <div class="card__sub">Pick from your catalogue, or type a custom line.</div>
          </div>
          <div class="card__actions">
            <button class="btn btn--outline btn--sm" type="button" id="add-item-row">
              <?= icon('plus') ?> Add line
            </button>
          </div>
        </div>

        <div class="table-wrap">
          <table class="items-table" id="items-table">
            <thead>
              <tr>
                <th style="width:26px">#</th>
                <th class="col-w-type">Type</th>
                <th>Item / description</th>
                <th class="col-w-qty num">Qty</th>
                <th class="col-w-price num">Unit price</th>
                <th class="col-w-total num">Line total</th>
                <th style="width:38px"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $item): ?>
                <tr>
                  <td class="items-table__drag" data-f="index"><?= $i + 1 ?></td>
                  <td>
                    <select class="select" data-f="item_type" aria-label="Item type" name="items[<?= $i ?>][item_type]">
                      <option value="custom"    <?= $item['item_type'] === 'custom'    ? 'selected' : '' ?>>Custom</option>
                      <option value="inventory" <?= $item['item_type'] === 'inventory' ? 'selected' : '' ?>>Inventory</option>
                      <option value="service"   <?= $item['item_type'] === 'service'   ? 'selected' : '' ?>>Service</option>
                    </select>
                    <select class="select mt-4 <?= $item['item_type'] === 'custom' ? 'hidden' : '' ?>"
                            data-f="ref_id" name="items[<?= $i ?>][ref_id]"></select>
                  </td>
                  <td>
                    <textarea class="textarea" data-f="description" aria-label="Description" name="items[<?= $i ?>][description]"
                              rows="2" style="min-height:38px" required><?= e($item['description']) ?></textarea>
                    <input type="hidden" data-f="unit" name="items[<?= $i ?>][unit]" value="<?= e($item['unit']) ?>">
                  </td>
                  <td class="num">
                    <input class="input num" type="number" step="0.01" min="0.01"
                           data-f="quantity" aria-label="Quantity" name="items[<?= $i ?>][quantity]"
                           value="<?= e(rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.')) ?>" required>
                  </td>
                  <td class="num">
                    <input class="input num" type="number" step="0.01" min="0"
                           data-f="unit_price" aria-label="Unit price" name="items[<?= $i ?>][unit_price]"
                           value="<?= e(number_format((float) $item['unit_price'], 2, '.', '')) ?>" required>
                  </td>
                  <td class="num fw-600 nums" data-f="line_total">0.00</td>
                  <td>
                    <button class="items-table__del" type="button" aria-label="Remove line"><?= icon('trash') ?></button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="card__foot" style="display:block">
          <div class="totals">
            <div class="totals__row">
              <span class="totals__label">Subtotal</span>
              <span class="totals__value"><?= e(setting('currency', 'KES')) ?> <span id="sum-subtotal">0.00</span></span>
            </div>
            <div class="totals__row hidden" id="row-discount">
              <span class="totals__label">Discount</span>
              <span class="totals__value">− <?= e(setting('currency', 'KES')) ?> <span id="sum-discount">0.00</span></span>
            </div>
            <div class="totals__row" id="row-vat">
              <span class="totals__label">VAT</span>
              <span class="totals__value"><?= e(setting('currency', 'KES')) ?> <span id="sum-vat">0.00</span></span>
            </div>
            <div class="totals__row totals__row--grand">
              <span class="totals__label">Total</span>
              <span class="totals__value"><?= e(setting('currency', 'KES')) ?> <span id="sum-total">0.00</span></span>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head"><div class="card__title">Notes &amp; terms</div></div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="notes">Notes to client</label>
              <textarea class="textarea" id="notes" name="notes" rows="4"
                        placeholder="Delivery details, artwork requirements…"><?= e($val('notes')) ?></textarea>
            </div>
            <div class="field">
              <label class="label" for="terms">Terms &amp; conditions</label>
              <textarea class="textarea" id="terms" name="terms" rows="4"><?= e($val('terms', $defaultTerms)) ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Discount &amp; VAT</div></div>
        <div class="card__body">
          <div class="field mb-12">
            <label class="label" for="discount_type">Discount</label>
            <select class="select" id="discount_type" name="discount_type">
              <option value="none"    <?= $val('discount_type', 'none') === 'none'    ? 'selected' : '' ?>>No discount</option>
              <option value="percent" <?= $val('discount_type', 'none') === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
              <option value="amount"  <?= $val('discount_type', 'none') === 'amount'  ? 'selected' : '' ?>>Fixed amount</option>
            </select>
          </div>

          <div class="field mb-16 hidden" id="discount_value_wrap">
            <label class="label" for="discount_value">Discount value</label>
            <input class="input" type="number" step="0.01" min="0" id="discount_value" name="discount_value"
                   value="<?= e($val('discount_value', '0')) ?>">
            <?= error_for($errors ?? [], 'discount_value') ?>
          </div>

          <div class="field mb-12">
            <label class="label" for="vat_mode">VAT treatment</label>
            <select class="select" id="vat_mode" name="vat_mode">
              <option value="exclusive" <?= $val('vat_mode', setting('vat_default_mode', 'exclusive')) === 'exclusive' ? 'selected' : '' ?>>
                Add VAT on top (exclusive)
              </option>
              <option value="inclusive" <?= $val('vat_mode', setting('vat_default_mode', 'exclusive')) === 'inclusive' ? 'selected' : '' ?>>
                Prices include VAT (inclusive)
              </option>
              <option value="exempt" <?= $val('vat_mode', setting('vat_default_mode', 'exclusive')) === 'exempt' ? 'selected' : '' ?>>
                No VAT (exempt / zero-rated)
              </option>
            </select>
          </div>

          <div class="field">
            <label class="label" for="vat_rate">VAT rate (%)</label>
            <input class="input" type="number" step="0.001" min="0" id="vat_rate" name="vat_rate"
                   value="<?= e($val('vat_rate', $vatRate)) ?>">
          </div>
        </div>
      </div>

      <?php if ($type !== 'receipt'): ?>
        <div class="card">
          <div class="card__head"><div class="card__title">Status</div></div>
          <div class="card__body">
            <select class="select" name="status" aria-label="Status">
              <option value="draft" <?= $val('status', 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
              <option value="sent"  <?= $val('status', 'draft') === 'sent'  ? 'selected' : '' ?>>Sent to client</option>
              <?php if ($type === 'invoice'): ?>
                <option value="unpaid" <?= $val('status', 'draft') === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
              <?php endif; ?>
            </select>
            <span class="field-hint">Paid and partial statuses are set automatically when payments are recorded.</span>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Quick add</div>
            <div class="card__sub">Click to append a catalogue line.</div>
          </div>
        </div>
        <div class="card__body" style="max-height:320px;overflow-y:auto">
          <?php if ($services): ?>
            <div class="text-xs uppercase fw-700 text-muted mb-8">Services</div>
            <?php foreach ($services as $s): ?>
              <button class="btn btn--outline btn--sm btn--block mb-4" type="button"
                      data-add-catalog="service"
                      data-id="<?= (int) $s['id'] ?>"
                      data-label="<?= e($s['name']) ?>"
                      data-description="<?= e($s['description'] ?: $s['name']) ?>"
                      data-price="<?= e((string) $s['price']) ?>"
                      data-unit="<?= e($s['unit_label'] ?? '') ?>"
                      style="justify-content:space-between;text-align:left">
                <span class="truncate"><?= e($s['name']) ?></span>
                <span class="text-xs text-muted"><?= e(money($s['price'], false)) ?></span>
              </button>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php if ($inventory): ?>
            <div class="text-xs uppercase fw-700 text-muted mb-8 mt-16">Inventory</div>
            <?php foreach ($inventory as $i): ?>
              <button class="btn btn--outline btn--sm btn--block mb-4" type="button"
                      data-add-catalog="inventory"
                      data-id="<?= (int) $i['id'] ?>"
                      data-label="<?= e($i['name']) ?>"
                      data-description="<?= e($i['description'] ?: $i['name']) ?>"
                      data-price="<?= e((string) $i['selling_price']) ?>"
                      data-unit="<?= e($i['unit']) ?>"
                      style="justify-content:flex-start;gap:9px;text-align:left">
                <?php if (!empty($i['thumb'])): ?>
                  <img class="pick-thumb" src="<?= url('files/' . $i['thumb']) ?>" alt="" loading="lazy">
                <?php else: ?>
                  <span class="pick-thumb pick-thumb--empty"><?= icon('image') ?></span>
                <?php endif; ?>
                <span class="truncate flex-1"><?= e($i['name']) ?></span>
                <span class="text-xs text-muted"><?= e(money($i['selling_price'], false)) ?></span>
              </button>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php if (!$services && !$inventory): ?>
            <p class="text-sm text-muted mb-0">
              Your catalogue is empty. Add
              <a href="<?= url('/services') ?>">services</a> or
              <a href="<?= url('/inventory') ?>">inventory</a> to quote faster.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Create ' . e(strtolower($meta['label'])) ?>
          </button>
          <a class="btn btn--ghost btn--block mt-8"
             href="<?= $editing ? url($meta['path'] . '/' . $doc['id']) : url($meta['path']) ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>

<!-- Blank row cloned by app.js when adding a line -->
<template id="item-row-template">
  <tr>
    <td class="items-table__drag" data-f="index">1</td>
    <td>
      <select class="select" data-f="item_type" aria-label="Item type" name="items[][item_type]">
        <option value="custom">Custom</option>
        <option value="inventory">Inventory</option>
        <option value="service">Service</option>
      </select>
      <select class="select mt-4 hidden" data-f="ref_id" aria-label="Catalogue item" name="items[][ref_id]"></select>
    </td>
    <td>
      <textarea class="textarea" data-f="description" aria-label="Description" name="items[][description]"
                rows="2" style="min-height:38px" placeholder="Describe the item or service…" required></textarea>
      <input type="hidden" data-f="unit" name="items[][unit]" value="">
    </td>
    <td class="num">
      <input class="input num" type="number" step="0.01" min="0.01"
             data-f="quantity" aria-label="Quantity" name="items[][quantity]" value="1" required>
    </td>
    <td class="num">
      <input class="input num" type="number" step="0.01" min="0"
             data-f="unit_price" aria-label="Unit price" name="items[][unit_price]" value="0.00" required>
    </td>
    <td class="num fw-600 nums" data-f="line_total">0.00</td>
    <td>
      <button class="items-table__del" type="button" aria-label="Remove line"><?= icon('trash') ?></button>
    </td>
  </tr>
</template>

<script type="application/json" id="catalog-data"><?= json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset('js/catalog-init.js') ?>"></script>

<?php endif; ?>

<?php // Adding and removing narrative blocks. Indexes are renumbered on
      // submit so a removed block does not leave a hole in the array. ?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var wrap = document.getElementById('sections-wrap');
  var add  = document.getElementById('add-section');
  if (!wrap || !add) return;

  function renumber() {
    wrap.querySelectorAll('[data-section]').forEach(function (block, i) {
      var h = block.querySelector('[name*="[heading]"]');
      var b = block.querySelector('[name*="[body]"]');
      if (h) h.name = 'sections[' + i + '][heading]';
      if (b) b.name = 'sections[' + i + '][body]';
    });
  }

  add.addEventListener('click', function () {
    var last  = wrap.querySelector('[data-section]:last-child');
    var block = last.cloneNode(true);
    block.querySelectorAll('input, textarea').forEach(function (f) { f.value = ''; });
    wrap.appendChild(block);
    renumber();
    var heading = block.querySelector('input');
    if (heading) heading.focus();
  });

  wrap.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-remove-section]');
    if (!btn) return;

    // Never remove the last one, or there is no way to add another back.
    if (wrap.querySelectorAll('[data-section]').length <= 1) {
      btn.closest('[data-section]').querySelectorAll('input, textarea')
         .forEach(function (f) { f.value = ''; });
      return;
    }

    btn.closest('[data-section]').remove();
    renumber();
  });
})();
</script>
