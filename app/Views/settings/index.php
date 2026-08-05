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
    <a class="tab <?= $tab === 'messaging'  ? 'is-active' : '' ?>" href="<?= e($tabUrl('messaging')) ?>">
      <?= icon('send') ?> Email &amp; SMS
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

<?php elseif ($tab === 'messaging'): ?>

  <form method="post" action="<?= url('/settings/messaging') ?>">
    <?= csrf_field() ?>

    <div class="grid-2">
      <!-- Email -->
      <div class="card">
        <div class="card__head">
          <?= icon('mail') ?>
          <div>
            <div class="card__title">Email (SMTP)</div>
            <div class="card__sub">Use the mailbox details from cPanel → Email Accounts</div>
          </div>
        </div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field field--full">
              <label class="label" for="smtp_host">SMTP host</label>
              <input class="input <?= isset($errors['smtp_host']) ? 'has-error' : '' ?>"
                     id="smtp_host" name="smtp_host" maxlength="180"
                     value="<?= e(setting('smtp_host', '')) ?>" placeholder="mail.shanfix.co.ke">
              <?= error_for($errors ?? [], 'smtp_host') ?>
            </div>

            <div class="field">
              <label class="label" for="smtp_port">Port</label>
              <input class="input" type="number" id="smtp_port" name="smtp_port"
                     value="<?= e(setting('smtp_port', '587')) ?>">
              <span class="field-hint">587 for TLS, 465 for SSL, 25 unencrypted.</span>
            </div>

            <div class="field">
              <label class="label" for="smtp_encryption">Encryption</label>
              <select class="select" id="smtp_encryption" name="smtp_encryption">
                <?php $enc = setting('smtp_encryption', 'tls'); ?>
                <option value="tls"  <?= $enc === 'tls'  ? 'selected' : '' ?>>STARTTLS (recommended)</option>
                <option value="ssl"  <?= $enc === 'ssl'  ? 'selected' : '' ?>>SSL / implicit TLS</option>
                <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>None</option>
              </select>
            </div>

            <div class="field">
              <label class="label" for="smtp_username">Username</label>
              <input class="input" id="smtp_username" name="smtp_username" autocomplete="off"
                     value="<?= e(setting('smtp_username', '')) ?>" placeholder="invoices@shanfix.co.ke">
              <span class="field-hint">Usually the full email address.</span>
            </div>

            <div class="field">
              <label class="label" for="smtp_password">
                Password
                <?php if ($hasSecret['smtp_password']): ?>
                  <span class="badge badge--green text-xs">Stored</span>
                <?php endif; ?>
              </label>
              <input class="input <?= isset($errors['smtp_password']) ? 'has-error' : '' ?>"
                     type="password" id="smtp_password" name="smtp_password" autocomplete="new-password"
                     placeholder="<?= $hasSecret['smtp_password'] ? 'Leave blank to keep' : 'Mailbox password' ?>">
              <?= error_for($errors ?? [], 'smtp_password') ?>
            </div>

            <div class="field">
              <label class="label" for="smtp_from_email">Send from</label>
              <input class="input <?= isset($errors['smtp_from_email']) ? 'has-error' : '' ?>"
                     type="email" id="smtp_from_email" name="smtp_from_email"
                     value="<?= e(setting('smtp_from_email', '')) ?>" placeholder="invoices@shanfix.co.ke">
              <?= error_for($errors ?? [], 'smtp_from_email') ?>
            </div>

            <div class="field">
              <label class="label" for="smtp_from_name">From name</label>
              <input class="input" id="smtp_from_name" name="smtp_from_name" maxlength="120"
                     value="<?= e(setting('smtp_from_name', 'Shanfix Technology')) ?>">
            </div>

            <div class="field field--full">
              <label class="label" for="smtp_reply_to">Reply-to address</label>
              <input class="input" type="email" id="smtp_reply_to" name="smtp_reply_to"
                     value="<?= e(setting('smtp_reply_to', '')) ?>" placeholder="Optional">
              <span class="field-hint">Where client replies should land, if different from the sender.</span>
            </div>
          </div>

          <hr>

          <label class="check">
            <input type="checkbox" name="smtp_enabled" value="1" <?= setting('smtp_enabled') === '1' ? 'checked' : '' ?>>
            <span class="check__text">
              <strong>Enable email sending</strong>
              <span>Adds “Email to client” buttons on quotations and invoices.</span>
            </span>
          </label>
        </div>
      </div>

      <!-- SMS -->
      <div class="card">
        <div class="card__head">
          <?= icon('message') ?>
          <div>
            <div class="card__title">SMS (Shanfix Bulk SMS)</div>
            <div class="card__sub">Credentials from your portal's Developer / API page</div>
          </div>
        </div>
        <div class="card__body">
          <div class="form-grid form-grid--2">
            <div class="field field--full">
              <label class="label" for="sms_client_id">Client ID</label>
              <input class="input <?= isset($errors['sms_client_id']) ? 'has-error' : '' ?>"
                     id="sms_client_id" name="sms_client_id" autocomplete="off"
                     value="<?= e(setting('sms_client_id', '')) ?>" placeholder="e.g. SFX-1042">
              <span class="field-hint">
                Sign in at <code><?= e(setting('sms_base_url', \App\Services\Sms::DEFAULT_BASE_URL)) ?></code>
                and open <strong>API</strong> to copy your Client ID and key.
              </span>
              <?= error_for($errors ?? [], 'sms_client_id') ?>
            </div>

            <div class="field field--full">
              <label class="label" for="sms_api_key">
                API key
                <?php if ($hasSecret['sms_api_key']): ?>
                  <span class="badge badge--green text-xs">Stored</span>
                <?php endif; ?>
              </label>
              <input class="input <?= isset($errors['sms_api_key']) ? 'has-error' : '' ?>"
                     type="password" id="sms_api_key" name="sms_api_key" autocomplete="new-password"
                     placeholder="<?= $hasSecret['sms_api_key'] ? 'Leave blank to keep' : 'Paste your API key' ?>">
              <?= error_for($errors ?? [], 'sms_api_key') ?>
            </div>

            <div class="field">
              <label class="label" for="sms_sender_id">Sender ID</label>
              <input class="input <?= isset($errors['sms_sender_id']) ? 'has-error' : '' ?>"
                     id="sms_sender_id" name="sms_sender_id" maxlength="20"
                     value="<?= e(setting('sms_sender_id', '')) ?>" placeholder="SHANFIX">
              <span class="field-hint">
                Must already be approved on that account, or the gateway rejects the send.
              </span>
              <?= error_for($errors ?? [], 'sms_sender_id') ?>
            </div>

            <div class="field">
              <label class="label" for="sms_base_url">Portal address</label>
              <input class="input <?= isset($errors['sms_base_url']) ? 'has-error' : '' ?>"
                     id="sms_base_url" name="sms_base_url"
                     value="<?= e(setting('sms_base_url', \App\Services\Sms::DEFAULT_BASE_URL)) ?>">
              <span class="field-hint">Leave as is unless you run the platform on another domain.</span>
              <?= error_for($errors ?? [], 'sms_base_url') ?>
            </div>
          </div>

          <hr>

          <label class="check">
            <input type="checkbox" name="sms_enabled" value="1" <?= setting('sms_enabled') === '1' ? 'checked' : '' ?>>
            <span class="check__text">
              <strong>Enable SMS sending</strong>
              <span>Charged in SMS units per 160 characters — keep templates short.</span>
            </span>
          </label>
        </div>
      </div>
    </div>

    <!-- Which events send -->
    <div class="card">
      <div class="card__head">
        <?= icon('sliders') ?>
        <div>
          <div class="card__title">What gets sent automatically</div>
          <div class="card__sub">You can always send manually regardless of these switches</div>
        </div>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Event</th><th style="width:120px">Email</th><th style="width:120px">SMS</th></tr>
          </thead>
          <tbody>
            <?php foreach ($events as $key => $label): ?>
              <tr>
                <td><?= e($label) ?></td>
                <td>
                  <label class="check">
                    <input type="checkbox" name="notify_<?= e($key) ?>_email" value="1"
                           <?= setting("notify_{$key}_email") === '1' ? 'checked' : '' ?>>
                    <span class="check__text"><span>Send</span></span>
                  </label>
                </td>
                <td>
                  <label class="check">
                    <input type="checkbox" name="notify_<?= e($key) ?>_sms" value="1"
                           <?= setting("notify_{$key}_sms") === '1' ? 'checked' : '' ?>>
                    <span class="check__text"><span>Send</span></span>
                  </label>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card__body" style="border-top:1px solid var(--border)">
        <div class="form-grid form-grid--3">
          <div class="field">
            <label class="label" for="notify_overdue_days">Chase overdue invoices after</label>
            <input class="input <?= isset($errors['notify_overdue_days']) ? 'has-error' : '' ?>"
                   id="notify_overdue_days" name="notify_overdue_days"
                   value="<?= e(setting('notify_overdue_days', '1,7,14')) ?>">
            <span class="field-hint">Days past due, comma separated. Each invoice is chased once per figure.</span>
            <?= error_for($errors ?? [], 'notify_overdue_days') ?>
          </div>

          <div class="field">
            <label class="label" for="notify_send_window">Only send between</label>
            <input class="input <?= isset($errors['notify_send_window']) ? 'has-error' : '' ?>"
                   id="notify_send_window" name="notify_send_window"
                   value="<?= e(setting('notify_send_window', '08:00-18:00')) ?>" placeholder="08:00-18:00">
            <span class="field-hint">Stops clients being messaged overnight. Blank = any time.</span>
            <?= error_for($errors ?? [], 'notify_send_window') ?>
          </div>

          <div class="field">
            <label class="label" for="notify_max_attempts">Retry attempts</label>
            <input class="input" type="number" min="1" max="10" id="notify_max_attempts" name="notify_max_attempts"
                   value="<?= e(setting('notify_max_attempts', '3')) ?>">
          </div>
        </div>
      </div>
    </div>

    <!-- Templates -->
    <div class="card">
      <div class="card__head">
        <?= icon('file-text') ?>
        <div>
          <div class="card__title">Message templates</div>
          <div class="card__sub">
            Placeholders: <code>{client_name}</code> <code>{contact_name}</code> <code>{company_name}</code>
            <code>{doc_number}</code> <code>{amount}</code> <code>{balance}</code> <code>{due_date}</code>
            <code>{valid_until}</code> <code>{link}</code> <code>{job_number}</code> <code>{job_title}</code>
          </div>
        </div>
      </div>
      <div class="card__body">
        <div class="text-xs uppercase fw-700 text-muted mb-12">Email</div>
        <?php foreach ([
            'quotation_sent'   => 'Quotation sent',
            'invoice_sent'     => 'Invoice sent',
            'payment_received' => 'Payment received',
            'invoice_overdue'  => 'Overdue reminder',
            'job_ready'        => 'Job ready for collection',
        ] as $key => $label): ?>
          <div class="form-grid form-grid--2 mb-16">
            <div class="field">
              <label class="label" for="tpl_<?= e($key) ?>_subject"><?= e($label) ?> — subject</label>
              <input class="input" id="tpl_<?= e($key) ?>_subject" name="tpl_<?= e($key) ?>_subject"
                     value="<?= e(setting("tpl_{$key}_subject", '')) ?>">
            </div>
            <div class="field">
              <label class="label" for="tpl_<?= e($key) ?>_intro"><?= e($label) ?> — opening line</label>
              <textarea class="textarea" id="tpl_<?= e($key) ?>_intro" name="tpl_<?= e($key) ?>_intro"
                        rows="2"><?= e(setting("tpl_{$key}_intro", '')) ?></textarea>
            </div>
          </div>
        <?php endforeach; ?>

        <hr>

        <div class="text-xs uppercase fw-700 text-muted mb-12">
          SMS <span class="text-muted" style="font-weight:400;text-transform:none">— keep under 160 characters to stay at one credit</span>
        </div>
        <div class="form-grid form-grid--2">
          <?php foreach ([
              'invoice_sent'     => 'Invoice sent',
              'payment_received' => 'Payment received',
              'invoice_overdue'  => 'Overdue reminder',
              'job_ready'        => 'Job ready',
          ] as $key => $label): ?>
            <?php $tpl = (string) setting("tpl_sms_{$key}", ''); ?>
            <div class="field">
              <label class="label" for="tpl_sms_<?= e($key) ?>">
                <?= e($label) ?>
                <span class="label__hint">— <?= mb_strlen($tpl) ?> chars, <?= \App\Services\Sms::parts($tpl) ?> credit(s)</span>
              </label>
              <textarea class="textarea" id="tpl_sms_<?= e($key) ?>" name="tpl_sms_<?= e($key) ?>"
                        rows="3"><?= e($tpl) ?></textarea>
            </div>
          <?php endforeach; ?>
        </div>

        <hr>

        <div class="field">
          <label class="label" for="email_footer_note">Email footer note</label>
          <input class="input" id="email_footer_note" name="email_footer_note" maxlength="255"
                 value="<?= e(setting('email_footer_note', '')) ?>">
        </div>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit"><?= icon('save') ?> Save messaging settings</button>
        </div>
      </div>
    </div>
  </form>

  <!-- Test sends -->
  <div class="grid-2">
    <div class="card">
      <div class="card__head"><div class="card__title">Send a test email</div></div>
      <div class="card__body">
        <form method="post" action="<?= url('/settings/messaging/test') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="channel" value="email">
          <div class="field mb-12">
            <label class="label" for="test_email">To</label>
            <input class="input" type="email" id="test_email" name="to"
                   value="<?= e(auth()['email'] ?? '') ?>">
          </div>
          <button class="btn btn--outline btn--block" type="submit" <?= setting('smtp_host') ? '' : 'disabled' ?>>
            <?= icon('mail') ?> Send test email
          </button>
        </form>
        <p class="field-hint mt-8 mb-0">Connects, authenticates and delivers a real message.</p>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><div class="card__title">Send a test SMS</div></div>
      <div class="card__body">
        <form method="post" action="<?= url('/settings/messaging/test') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="channel" value="sms">
          <div class="field mb-12">
            <label class="label" for="test_sms">To</label>
            <input class="input" id="test_sms" name="to"
                   value="<?= e(auth()['phone'] ?? '') ?>" placeholder="0712345678">
          </div>
          <button class="btn btn--outline btn--block" type="submit" <?= setting('sms_client_id') ? '' : 'disabled' ?>>
            <?= icon('message') ?> Send test SMS
          </button>
        </form>
        <form method="post" action="<?= url('/settings/messaging/test') ?>" class="mt-8">
          <?= csrf_field() ?>
          <input type="hidden" name="channel" value="sms_balance">
          <button class="btn btn--ghost btn--block" type="submit" <?= setting('sms_client_id') ? '' : 'disabled' ?>>
            Check credentials &amp; balance
          </button>
        </form>
        <p class="field-hint mt-8 mb-0">
          A test send costs one SMS unit. Checking the balance is free.
        </p>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <?= icon('clock') ?>
      <div>
        <div class="card__title">Scheduled sending</div>
        <div class="card__sub">Reminders and queued messages need a cron job</div>
      </div>
    </div>
    <div class="card__body">
      <p class="text-sm text-muted">
        In cPanel → <strong>Cron Jobs</strong>, add a job running <strong>every 5 minutes</strong>:
      </p>
      <pre style="background:var(--navy-900);color:var(--green-100);padding:12px 14px;border-radius:var(--r);overflow-x:auto;font-size:12.5px">/usr/local/bin/php <?= e(BASE_PATH) ?>/cron.php >/dev/null 2>&amp;1</pre>
      <p class="field-hint mb-0">
        Without it, messages still send immediately when you press a button —
        but overdue reminders and retries will not run on their own.
      </p>
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
