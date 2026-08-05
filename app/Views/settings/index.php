<?php
require_once APP_PATH . '/Views/partials/icons.php';

$tabUrl = static fn(string $t): string => url('/settings?tab=' . $t);
?>

<div class="page-head">
  <div class="page-head__text">
    <h1>Settings</h1>
    <div class="page-head__sub">Company details, document defaults, payment integration and categories.</div>
  </div>
</div>

<div class="card">
  <nav class="tabs">
    <a class="tab <?= $tab === 'company'    ? 'is-active' : '' ?>" href="<?= e($tabUrl('company')) ?>">
      <?= icon('briefcase') ?> Company
    </a>
    <a class="tab <?= $tab === 'documents'  ? 'is-active' : '' ?>" href="<?= e($tabUrl('documents')) ?>">
      <?= icon('file-text') ?> Documents &amp; VAT
    </a>
    <a class="tab <?= $tab === 'payments'   ? 'is-active' : '' ?>" href="<?= e($tabUrl('payments')) ?>">
      <?= icon('smartphone') ?> M-Pesa / KopoKopo
    </a>
    <a class="tab <?= $tab === 'categories' ? 'is-active' : '' ?>" href="<?= e($tabUrl('categories')) ?>">
      <?= icon('layers') ?> Categories
    </a>
  </nav>
</div>

<?php if ($tab === 'company'): ?>

  <form method="post" action="<?= url('/settings/company') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Company details</div>
          <div class="card__sub">These appear on every quotation, invoice and receipt.</div>
        </div>
      </div>
      <div class="card__body">
        <div class="form-grid form-grid--2">
          <div class="field">
            <label class="label" for="company_name">Company name <span class="req">*</span></label>
            <input class="input" id="company_name" name="company_name" required maxlength="180"
                   value="<?= e($settings['name']) ?>">
          </div>

          <div class="field">
            <label class="label" for="company_tagline">Tagline</label>
            <input class="input" id="company_tagline" name="company_tagline" maxlength="180"
                   value="<?= e($settings['tagline']) ?>"
                   placeholder="e.g. Printing, Branding &amp; Software Solutions">
          </div>

          <div class="field">
            <label class="label" for="company_email">Email</label>
            <input class="input" type="email" id="company_email" name="company_email"
                   value="<?= e($settings['email']) ?>" maxlength="160">
            <?= error_for($errors ?? [], 'company_email') ?>
          </div>

          <div class="field">
            <label class="label" for="company_phone">Phone</label>
            <input class="input" id="company_phone" name="company_phone"
                   value="<?= e($settings['phone']) ?>" maxlength="40">
          </div>

          <div class="field">
            <label class="label" for="company_website">Website</label>
            <input class="input" id="company_website" name="company_website"
                   value="<?= e($settings['website']) ?>" maxlength="180">
          </div>

          <div class="field">
            <label class="label" for="company_kra_pin">KRA PIN</label>
            <input class="input" id="company_kra_pin" name="company_kra_pin"
                   value="<?= e($settings['kra_pin']) ?>" maxlength="30"
                   style="text-transform:uppercase" placeholder="P051234567X">
          </div>

          <div class="field field--full">
            <label class="label" for="company_address">Physical address</label>
            <input class="input" id="company_address" name="company_address"
                   value="<?= e($settings['address']) ?>" maxlength="255">
          </div>

          <div class="field">
            <label class="label" for="currency">Currency code</label>
            <input class="input" id="currency" name="currency" maxlength="5"
                   value="<?= e(setting('currency', 'KES')) ?>" style="text-transform:uppercase">
            <span class="field-hint">Shown before every amount, e.g. KES 12,500.00</span>
          </div>

          <div class="field">
            <label class="label" for="company_logo">Company logo</label>
            <input class="input" type="file" id="company_logo" name="company_logo" accept=".png,.jpg,.jpeg,.webp">
            <span class="field-hint">
              PNG with a transparent background works best. Printed at about 62px tall.
            </span>
          </div>

          <?php if ($settings['logo']): ?>
            <div class="field field--full">
              <div class="text-xs uppercase fw-700 text-muted mb-8">Current logo</div>
              <img src="<?= url('storage/' . $settings['logo']) ?>" alt="Company logo"
                   style="max-height:70px;background:#fff;padding:8px;border:1px solid var(--border);border-radius:var(--r)">
            </div>
          <?php endif; ?>
        </div>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit"><?= icon('save') ?> Save company details</button>
        </div>
      </div>
    </div>
  </form>

<?php elseif ($tab === 'documents'): ?>

  <form method="post" action="<?= url('/settings/documents') ?>">
    <?= csrf_field() ?>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">VAT</div>
          <div class="card__sub">Kenya's standard rate is 16%. Each document can still override this.</div>
        </div>
      </div>
      <div class="card__body">
        <div class="form-grid form-grid--3">
          <div class="field">
            <label class="label" for="vat_rate">Standard VAT rate (%)</label>
            <input class="input" type="number" step="0.001" min="0" max="100" id="vat_rate" name="vat_rate"
                   value="<?= e(setting('vat_rate', '16')) ?>">
            <?= error_for($errors ?? [], 'vat_rate') ?>
          </div>

          <div class="field">
            <label class="label" for="vat_default_mode">Default treatment</label>
            <select class="select" id="vat_default_mode" name="vat_default_mode">
              <?php $mode = setting('vat_default_mode', 'exclusive'); ?>
              <option value="exclusive" <?= $mode === 'exclusive' ? 'selected' : '' ?>>Add VAT on top (exclusive)</option>
              <option value="inclusive" <?= $mode === 'inclusive' ? 'selected' : '' ?>>Prices include VAT</option>
              <option value="exempt"    <?= $mode === 'exempt'    ? 'selected' : '' ?>>No VAT by default</option>
            </select>
          </div>

          <div class="field">
            <label class="label" for="mpesa_till">M-Pesa Till number</label>
            <input class="input" id="mpesa_till" name="mpesa_till" maxlength="40"
                   value="<?= e(setting('mpesa_till', '')) ?>">
            <span class="field-hint">Printed on invoices under “How to pay”.</span>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__head">
        <div>
          <div class="card__title">Numbering &amp; terms</div>
          <div class="card__sub">Numbers run as PREFIX-YEAR-0001 and reset each January.</div>
        </div>
      </div>
      <div class="card__body">
        <div class="form-grid form-grid--3">
          <?php foreach ([
              'quotation_prefix' => ['Quotation prefix', 'QTN'],
              'invoice_prefix'   => ['Invoice prefix', 'INV'],
              'receipt_prefix'   => ['Receipt prefix', 'RCP'],
              'payment_prefix'   => ['Payment prefix', 'PMT'],
              'expense_prefix'   => ['Expense prefix', 'EXP'],
              'lead_prefix'      => ['Lead prefix', 'LD'],
              'client_prefix'    => ['Client code prefix', 'CL'],
          ] as $key => [$label, $default]): ?>
            <div class="field">
              <label class="label" for="<?= e($key) ?>"><?= e($label) ?></label>
              <input class="input" id="<?= e($key) ?>" name="<?= e($key) ?>" maxlength="10"
                     value="<?= e(setting($key, $default)) ?>" style="text-transform:uppercase">
            </div>
          <?php endforeach; ?>

          <div class="field">
            <label class="label" for="quotation_validity_days">Quotation validity (days)</label>
            <input class="input" type="number" min="1" id="quotation_validity_days" name="quotation_validity_days"
                   value="<?= e(setting('quotation_validity_days', '30')) ?>">
          </div>

          <div class="field">
            <label class="label" for="invoice_due_days">Invoice payment terms (days)</label>
            <input class="input" type="number" min="0" id="invoice_due_days" name="invoice_due_days"
                   value="<?= e(setting('invoice_due_days', '14')) ?>">
          </div>
        </div>

        <hr>

        <div class="form-grid form-grid--2">
          <div class="field">
            <label class="label" for="quotation_terms">Default quotation terms</label>
            <textarea class="textarea" id="quotation_terms" name="quotation_terms" rows="5"><?= e(setting('quotation_terms', '')) ?></textarea>
          </div>

          <div class="field">
            <label class="label" for="invoice_terms">Default invoice terms</label>
            <textarea class="textarea" id="invoice_terms" name="invoice_terms" rows="5"><?= e(setting('invoice_terms', '')) ?></textarea>
          </div>

          <div class="field field--full">
            <label class="label" for="bank_details">Bank details</label>
            <textarea class="textarea" id="bank_details" name="bank_details" rows="2"><?= e(setting('bank_details', '')) ?></textarea>
            <span class="field-hint">Printed on invoices under “How to pay”.</span>
          </div>
        </div>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit"><?= icon('save') ?> Save document settings</button>
        </div>
      </div>
    </div>
  </form>

<?php elseif ($tab === 'payments'): ?>

  <?php if (!$appKeySet): ?>
    <div class="alert alert--warning">
      <?= icon('alert-triangle') ?>
      <div class="alert__body">
        <strong>Set an application key first.</strong>
        API secrets are encrypted before they are stored, which needs
        <code>security.app_key</code> in <code>config/config.php</code>.
        Generate one with <code>php -r "echo bin2hex(random_bytes(32));"</code>.
      </div>
    </div>
  <?php endif; ?>

  <div class="alert alert--info">
    <?= icon('info') ?>
    <div class="alert__body">
      Get these values from your KopoKopo dashboard under <strong>API Keys</strong>.
      Start in <strong>sandbox</strong> and switch to production once a test payment
      completes end to end. The callback URL must be publicly reachable over HTTPS —
      STK Push cannot be tested from a local machine.
    </div>
  </div>

  <form method="post" action="<?= url('/settings/payments') ?>">
    <?= csrf_field() ?>

    <div class="grid-sidebar">
      <div>
        <div class="card">
          <div class="card__head">
            <div>
              <div class="card__title">KopoKopo API credentials</div>
              <div class="card__sub">Used to send M-Pesa STK Push prompts to clients.</div>
            </div>
          </div>
          <div class="card__body">
            <div class="form-grid form-grid--2">
              <div class="field">
                <label class="label" for="kopokopo_env">Environment</label>
                <select class="select" id="kopokopo_env" name="kopokopo_env">
                  <?php $env = setting('kopokopo_env', 'sandbox'); ?>
                  <option value="sandbox"    <?= $env === 'sandbox'    ? 'selected' : '' ?>>Sandbox (testing)</option>
                  <option value="production" <?= $env === 'production' ? 'selected' : '' ?>>Production (live money)</option>
                </select>
              </div>

              <div class="field">
                <label class="label" for="kopokopo_till_number">Till number <span class="req">*</span></label>
                <input class="input <?= isset($errors['kopokopo_till_number']) ? 'has-error' : '' ?>"
                       id="kopokopo_till_number" name="kopokopo_till_number" maxlength="40"
                       value="<?= e(setting('kopokopo_till_number', '')) ?>" placeholder="e.g. K000000">
                <span class="field-hint">Your KopoKopo till, not the Safaricom till number.</span>
                <?= error_for($errors ?? [], 'kopokopo_till_number') ?>
              </div>

              <div class="field field--full">
                <label class="label" for="kopokopo_client_id">Client ID <span class="req">*</span></label>
                <input class="input <?= isset($errors['kopokopo_client_id']) ? 'has-error' : '' ?>"
                       id="kopokopo_client_id" name="kopokopo_client_id" maxlength="255"
                       value="<?= e(setting('kopokopo_client_id', '')) ?>" autocomplete="off">
                <?= error_for($errors ?? [], 'kopokopo_client_id') ?>
              </div>

              <div class="field field--full">
                <label class="label" for="kopokopo_client_secret">
                  Client Secret <span class="req">*</span>
                  <?php if ($hasSecret['client_secret']): ?>
                    <span class="badge badge--green text-xs">Stored</span>
                  <?php endif; ?>
                </label>
                <input class="input <?= isset($errors['kopokopo_client_secret']) ? 'has-error' : '' ?>"
                       type="password" id="kopokopo_client_secret" name="kopokopo_client_secret"
                       autocomplete="new-password"
                       placeholder="<?= $hasSecret['client_secret'] ? 'Leave blank to keep the stored secret' : 'Paste your Client Secret' ?>">
                <span class="field-hint">Encrypted before it is written to the database.</span>
                <?= error_for($errors ?? [], 'kopokopo_client_secret') ?>
              </div>

              <div class="field field--full">
                <label class="label" for="kopokopo_api_key">
                  API Key (webhook secret) <span class="req">*</span>
                  <?php if ($hasSecret['api_key']): ?>
                    <span class="badge badge--green text-xs">Stored</span>
                  <?php endif; ?>
                </label>
                <input class="input <?= isset($errors['kopokopo_api_key']) ? 'has-error' : '' ?>"
                       type="password" id="kopokopo_api_key" name="kopokopo_api_key"
                       autocomplete="new-password"
                       placeholder="<?= $hasSecret['api_key'] ? 'Leave blank to keep the stored key' : 'Paste your API Key' ?>">
                <span class="field-hint">
                  Verifies the HMAC signature on every callback. Without it, payment
                  confirmations are rejected.
                </span>
                <?= error_for($errors ?? [], 'kopokopo_api_key') ?>
              </div>

              <div class="field field--full">
                <label class="label" for="kopokopo_callback_url">Callback URL</label>
                <input class="input <?= isset($errors['kopokopo_callback_url']) ? 'has-error' : '' ?>"
                       id="kopokopo_callback_url" name="kopokopo_callback_url" maxlength="255"
                       value="<?= e(setting('kopokopo_callback_url', '')) ?>"
                       placeholder="<?= e($defaultCallback) ?>">
                <span class="field-hint">
                  Leave blank to use <code><?= e($defaultCallback) ?></code>.
                  Must be HTTPS and reachable from the internet.
                </span>
                <?= error_for($errors ?? [], 'kopokopo_callback_url') ?>
              </div>
            </div>

            <hr>

            <label class="check">
              <input type="checkbox" name="kopokopo_enabled" value="1"
                     <?= setting('kopokopo_enabled') === '1' ? 'checked' : '' ?>>
              <span class="check__text">
                <strong>Enable M-Pesa STK Push</strong>
                <span>Shows the “Send STK Push” button on client profiles and unpaid invoices.</span>
              </span>
            </label>

            <div class="form-actions">
              <button class="btn btn--primary" type="submit"><?= icon('save') ?> Save payment settings</button>
            </div>
          </div>
        </div>
      </div>

      <aside>
        <div class="card">
          <div class="card__head"><div class="card__title">Connection status</div></div>
          <div class="card__body">
            <dl class="dl">
              <dt>Status</dt>
              <dd>
                <span class="badge <?= setting('kopokopo_enabled') === '1' ? 'badge--green' : 'badge--grey' ?>">
                  <?= setting('kopokopo_enabled') === '1' ? 'Enabled' : 'Disabled' ?>
                </span>
              </dd>
              <dt>Environment</dt>
              <dd>
                <span class="badge <?= setting('kopokopo_env') === 'production' ? 'badge--red' : 'badge--amber' ?>">
                  <?= e(label_of(setting('kopokopo_env', 'sandbox'))) ?>
                </span>
              </dd>
              <dt>Credentials</dt>
              <dd>
                <span class="badge <?= $kopokopoReady ? 'badge--green' : 'badge--grey' ?>">
                  <?= $kopokopoReady ? 'Complete' : 'Incomplete' ?>
                </span>
              </dd>
            </dl>
          </div>
        </div>

        <div class="card">
          <div class="card__head"><div class="card__title">Test &amp; register</div></div>
          <div class="card__body">
            <p class="text-sm text-muted">
              Test the credentials first, then register the callback so KopoKopo
              starts sending payment confirmations.
            </p>
          </div>
        </div>
      </aside>
    </div>
  </form>

  <div class="grid-2">
    <div class="card">
      <div class="card__body">
        <form method="post" action="<?= url('/settings/payments/test') ?>">
          <?= csrf_field() ?>
          <button class="btn btn--outline btn--block" type="submit" <?= $kopokopoReady ? '' : 'disabled' ?>>
            <?= icon('zap') ?> Test connection
          </button>
        </form>
        <p class="field-hint mt-8 mb-0">Requests an OAuth token to confirm your Client ID and Secret.</p>
      </div>
    </div>

    <div class="card">
      <div class="card__body">
        <form method="post" action="<?= url('/settings/payments/webhook') ?>">
          <?= csrf_field() ?>
          <button class="btn btn--navy btn--block" type="submit" <?= $kopokopoReady ? '' : 'disabled' ?>>
            <?= icon('globe') ?> Register callback URL
          </button>
        </form>
        <p class="field-hint mt-8 mb-0">Run this once after going live, or if the callback URL changes.</p>
      </div>
    </div>
  </div>

<?php else: ?>

  <div class="grid-sidebar">
    <div>
      <?php foreach ([
          'inventory' => ['Inventory categories', 'Group your stock items'],
          'service'   => ['Service categories', 'Group the services you offer'],
          'expense'   => ['Expense categories', 'Drive your expense reporting'],
      ] as $type => [$heading, $sub]): ?>
        <div class="card">
          <div class="card__head">
            <div>
              <div class="card__title"><?= e($heading) ?></div>
              <div class="card__sub"><?= e($sub) ?></div>
            </div>
          </div>

          <?php $list = $categories[$type] ?? []; ?>
          <?php if (!$list): ?>
            <div class="card__body"><p class="text-sm text-muted mb-0">No categories yet.</p></div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table table--compact">
                <thead><tr><th>Name</th><th class="num">In use by</th><th class="actions"></th></tr></thead>
                <tbody>
                  <?php foreach ($list as $c):
                      $used = (int) $c['item_count'] + (int) $c['service_count'] + (int) $c['expense_count'];
                  ?>
                    <tr>
                      <td class="table__primary"><?= e($c['name']) ?></td>
                      <td class="num text-muted"><?= $used ?> record(s)</td>
                      <td class="actions">
                        <form method="post" action="<?= url('/settings/categories/' . $c['id'] . '/delete') ?>"
                              data-confirm="Delete &quot;<?= e($c['name']) ?>&quot;?<?= $used ? ' ' . $used . ' record(s) will become uncategorised.' : '' ?>">
                          <?= csrf_field() ?>
                          <button class="btn btn--danger-soft btn--sm" type="submit"><?= icon('trash') ?></button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <aside>
      <div class="card">
        <div class="card__head"><div class="card__title">Add a category</div></div>
        <div class="card__body">
          <form method="post" action="<?= url('/settings/categories') ?>">
            <?= csrf_field() ?>
            <div class="field mb-12">
              <label class="label" for="cat_type">Type</label>
              <select class="select" id="cat_type" name="type" required>
                <option value="inventory">Inventory</option>
                <option value="service">Service</option>
                <option value="expense">Expense</option>
              </select>
            </div>
            <div class="field mb-16">
              <label class="label" for="cat_name">Name</label>
              <input class="input" id="cat_name" name="name" required maxlength="120"
                     placeholder="e.g. Vehicle Branding">
            </div>
            <button class="btn btn--primary btn--block" type="submit"><?= icon('plus') ?> Add category</button>
          </form>
        </div>
      </div>
    </aside>
  </div>

<?php endif; ?>
