<?php
require_once APP_PATH . '/Views/partials/icons.php';

use App\Core\Session;

$val = static fn(string $key, $fallback = '') => Session::old($key, $fallback);
?>

<div class="page-head">
  <div class="page-head__text">
    <div class="breadcrumb">
      <a href="<?= url('/payments') ?>">Payments</a> <span>/</span> Record payment
    </div>
    <h1>Record a payment</h1>
    <div class="page-head__sub">
      For cash, bank transfers, cheques and M-Pesa payments received outside the system.
      STK Push payments are recorded automatically.
    </div>
  </div>
</div>

<form method="post" action="<?= url('/payments') ?>">
  <?= csrf_field() ?>

  <div class="grid-sidebar">
    <div>
      <div class="card">
        <div class="card__head"><div class="card__title">Payment details</div></div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field">
              <label class="label" for="client_id">Client <span class="req">*</span></label>
              <select class="select <?= isset($errors['client_id']) ? 'has-error' : '' ?>"
                      id="client_id" name="client_id" required <?= $document ? 'disabled' : '' ?>>
                <option value="">Select a client…</option>
                <?php foreach ($clients as $c): ?>
                  <option value="<?= (int) $c['id'] ?>" <?= (int) $clientId === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?> — <?= e($c['client_code']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if ($document): ?>
                <input type="hidden" name="client_id" value="<?= (int) $document['client_id'] ?>">
              <?php endif; ?>
              <?= error_for($errors ?? [], 'client_id') ?>
            </div>

            <div class="field">
              <label class="label" for="document_id">Apply to invoice</label>
              <?php if ($document): ?>
                <input class="input" value="<?= e($document['doc_number']) ?> — <?= e(money($document['balance'])) ?> due" aria-label="Invoice being paid" readonly>
                <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
              <?php else: ?>
                <select class="select" id="document_id" name="document_id">
                  <option value="">— On account (not linked) —</option>
                  <?php foreach ($openInvoices as $inv): ?>
                    <option value="<?= (int) $inv['id'] ?>">
                      <?= e($inv['doc_number']) ?> — <?= e(money($inv['balance'])) ?> due
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="field-hint">
                  <?= $clientId ? 'Only invoices with an outstanding balance are listed.'
                                : 'Pick a client first to see their open invoices.' ?>
                </span>
              <?php endif; ?>
              <?= error_for($errors ?? [], 'document_id') ?>
            </div>

            <div class="field">
              <label class="label" for="amount">Amount received <span class="req">*</span></label>
              <div class="input-group">
                <span class="input-group__addon input-group__addon--pre"><?= e(setting('currency', 'KES')) ?></span>
                <input class="input <?= isset($errors['amount']) ? 'has-error' : '' ?>" type="number" step="0.01" min="0.01"
                       id="amount" name="amount" required
                       value="<?= e($val('amount', $document ? number_format((float) $document['balance'], 2, '.', '') : '')) ?>">
              </div>
              <?= error_for($errors ?? [], 'amount') ?>
            </div>

            <div class="field">
              <label class="label" for="paid_at">Payment date</label>
              <input class="input" type="date" id="paid_at" name="paid_at" value="<?= e($val('paid_at', date('Y-m-d'))) ?>">
            </div>

            <div class="field">
              <label class="label" for="method">Payment method <span class="req">*</span></label>
              <select class="select" id="method" name="method" required>
                <?php foreach ($methods as $m): ?>
                  <?php if ($m === 'mpesa_stk') continue; // set automatically by KopoKopo ?>
                  <option value="<?= e($m) ?>" <?= $val('method', 'mpesa_manual') === $m ? 'selected' : '' ?>>
                    <?= e(label_of($m)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label class="label" for="reference">Reference</label>
              <input class="input" id="reference" name="reference" value="<?= e($val('reference')) ?>"
                     maxlength="120" placeholder="M-Pesa code, cheque no. or slip no.">
              <span class="field-hint">Recorded on the client's statement.</span>
            </div>

            <div class="field field--full">
              <label class="label" for="notes">Notes</label>
              <input class="input" id="notes" name="notes" value="<?= e($val('notes')) ?>"
                     maxlength="255" placeholder="Optional internal note">
            </div>
          </div>
        </div>
      </div>
    </div>

    <aside>
      <?php if ($document): ?>
        <div class="card">
          <div class="card__head"><div class="card__title">Invoice</div></div>
          <div class="card__body">
            <dl class="dl">
              <dt>Number</dt>
              <dd><a href="<?= url('/invoices/' . $document['id']) ?>"><?= e($document['doc_number']) ?></a></dd>
              <dt>Client</dt><dd><?= e($document['client_name']) ?></dd>
              <dt>Invoice total</dt><dd><?= e(money($document['total'])) ?></dd>
              <dt>Already paid</dt><dd class="text-green"><?= e(money($document['amount_paid'])) ?></dd>
              <dt>Balance due</dt><dd class="text-red fw-700"><?= e(money($document['balance'])) ?></dd>
              <dt>Due date</dt><dd><?= e(fdate($document['due_date'])) ?></dd>
            </dl>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__body">
          <button class="btn btn--primary btn--block" type="submit">
            <?= icon('check-circle') ?> Record payment
          </button>
          <a class="btn btn--ghost btn--block mt-8"
             href="<?= $document ? url('/invoices/' . $document['id']) : url('/payments') ?>">Cancel</a>
        </div>
      </div>
    </aside>
  </div>
</form>
