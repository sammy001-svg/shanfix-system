<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Session;

$editing = $expense !== null;
$action  = $editing ? url('/expenses/' . $expense['id']) : url('/expenses');

$val = static function (string $key, $fallback = '') use ($expense) {
    $old = Session::old($key, null);
    if ($old !== null && $old !== '') {
        return $old;
    }
    return $expense[$key] ?? $fallback;
};
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/expenses') ?>">Expenses</a> <span>/</span>
      <?= $editing ? e($expense['expense_number']) : 'New' ?>
    </div>
    <h1><?= $editing ? 'Edit expense' : 'Record an expense' ?></h1>
    <div class="page-head__sub">Attach the receipt so the record stands up at audit time.</div>
  </div>
</div>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">Expense details</div></div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field field--full">
              <label class="label" for="description">Description <span class="req">*</span></label>
              <input class="input <?= isset($errors['description']) ? 'has-error' : '' ?>"
                     id="description" name="description" required maxlength="500"
                     value="<?= e($val('description')) ?>"
                     placeholder="e.g. Vinyl rolls for Acme vehicle branding">
              <?= error_for($errors ?? [], 'description') ?>
            </div>

            <div class="field">
              <label class="label" for="category_id">Category</label>
              <select class="select" id="category_id" name="category_id">
                <option value="">— Uncategorised —</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int) $c['id'] ?>" <?= (int) $val('category_id') === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label class="label" for="vendor">Paid to / vendor</label>
              <input class="input" id="vendor" name="vendor" value="<?= e($val('vendor')) ?>"
                     maxlength="180" placeholder="e.g. Zenith Supplies Ltd">
            </div>

            <div class="field">
              <label class="label" for="amount">Amount <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group__addon input-group__addon--pre"><?= e(setting('currency', 'KES')) ?></span>
                <input class="input <?= isset($errors['amount']) ? 'has-error' : '' ?>" type="number" step="0.01" min="0.01"
                       id="amount" name="amount" required value="<?= e($val('amount')) ?>">
              </div>
              <span class="field-hint">Total paid, VAT included.</span>
              <?= error_for($errors ?? [], 'amount') ?>
            </div>

            <div class="field">
              <label class="label" for="vat_amount">Of which VAT</label>
              <div class="input-group">
                <span class="input-group__addon input-group__addon--pre"><?= e(setting('currency', 'KES')) ?></span>
                <input class="input <?= isset($errors['vat_amount']) ? 'has-error' : '' ?>" type="number" step="0.01" min="0"
                       id="vat_amount" name="vat_amount" value="<?= e($val('vat_amount', '0.00')) ?>">
              </div>
              <span class="field-hint">For input VAT claims. Leave at 0 if not VAT-charged.</span>
              <?= error_for($errors ?? [], 'vat_amount') ?>
            </div>

            <div class="field">
              <label class="label" for="expense_date">Date <span class="req">*</span></label>
              <input class="input" type="date" id="expense_date" name="expense_date" required
                     value="<?= e($val('expense_date', date('Y-m-d'))) ?>">
              <?= error_for($errors ?? [], 'expense_date') ?>
            </div>

            <div class="field">
              <label class="label" for="payment_method">Paid by</label>
              <select class="select" id="payment_method" name="payment_method">
                <?php foreach ($methods as $m): ?>
                  <option value="<?= e($m) ?>" <?= $val('payment_method', 'cash') === $m ? 'selected' : '' ?>>
                    <?= e(label_of($m)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field field--full">
              <label class="label" for="reference">Reference</label>
              <input class="input" id="reference" name="reference" value="<?= e($val('reference')) ?>"
                     maxlength="120" placeholder="M-Pesa code, cheque no. or invoice no.">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <div>
            <div class="card__title">Job costing</div>
            <div class="card__sub">Optional — link the cost to a client job.</div>
          </div>
        </div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="job_id">Book against a job</label>
              <select class="select" id="job_id" name="job_id">
                <option value="">— Not job-specific —</option>
                <?php foreach ($jobs as $j): ?>
                  <option value="<?= (int) $j['id'] ?>"
                          <?= (int) $val('job_id', $preJobId) === (int) $j['id'] ? 'selected' : '' ?>>
                    <?= e($j['job_number']) ?> — <?= e(str_excerpt($j['title'], 38)) ?> (<?= e($j['client_name']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="field-hint">Costs booked to a job show up in its margin.</span>
            </div>

            <div class="field">
              <label class="label" for="client_id">Related client</label>
              <select class="select" id="client_id" name="client_id">
                <option value="">— None —</option>
                <?php foreach ($clients as $c): ?>
                  <option value="<?= (int) $c['id'] ?>"
                          <?= (int) $val('client_id', $preClientId) === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field" style="display:flex;align-items:flex-end">
              <label class="check">
                <input type="checkbox" name="is_billable" value="1" <?= (int) $val('is_billable') === 1 ? 'checked' : '' ?>>
                <span class="check__text">
                  <strong>Rebillable to client</strong>
                  <span>Flag this cost so it is not forgotten at invoicing time.</span>
                </span>
              </label>
            </div>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Receipt</div></div>
        <div class="card__body">
          <?php if ($editing && $expense['receipt_file']): ?>
            <a class="btn btn--outline btn--block mb-8" target="_blank" rel="noopener"
               href="<?= url('storage/' . $expense['receipt_file']) ?>">
              <?= icon('paperclip') ?> View current receipt
            </a>
          <?php endif; ?>

          <div class="field">
            <label class="label" for="receipt_file">
              <?= $editing && $expense['receipt_file'] ? 'Replace receipt' : 'Upload receipt' ?>
            </label>
            <input class="input" type="file" id="receipt_file" name="receipt_file"
                   accept=".jpg,.jpeg,.png,.webp,.pdf">
            <span class="field-hint">JPG, PNG or PDF, up to <?= (int) config('uploads.max_size_mb', 8) ?>MB.</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('save') ?> <?= $editing ? 'Save changes' : 'Record expense' ?>
          </button>
          <a class="btn btn--ghost btn--block mt-8" href="<?= url('/expenses') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
